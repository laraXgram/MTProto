<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Driver\Fork;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Driver\Sync\SyncConnection;

/**
 * Process-fork event loop driver.
 *
 * Uses pcntl_fork() to run each handler invocation in a child process.
 * This provides TRUE parallelism — even CPU-bound loops and raw sleep()
 * calls will NOT block the main event loop.
 *
 * How it works:
 *   - The main process runs the event loop (socket polling + timers)
 *   - When a handler is dispatched, a child process is forked
 *   - The child runs the handler and exits
 *   - The main process continues immediately, processing more updates
 *   - SIGCHLD is handled to reap zombies
 *
 * Trade-offs:
 *   - Each child is a full process copy (memory overhead)
 *   - Children cannot share state with the parent
 *   - Handler return values are lost (fire-and-forget)
 *   - Requires pcntl extension
 *   - $client->sendMessage() inside a handler creates a NEW connection
 *     per child (because the socket is duplicated but shared, which can
 *     cause corruption). Use with caution for RPC calls inside handlers.
 *
 * Best for:
 *   - Handlers that do heavy CPU work
 *   - Handlers that call external APIs / file I/O
 *   - Handlers that use sleep()
 *   - Scenarios where you need guaranteed non-blocking
 *
 * Requirements:
 *   PHP extension: pcntl (usually bundled with PHP on Linux)
 *
 * Usage:
 *   $loop = new UpdateLoop($client, new ForkEventLoop());
 *   $loop->onMessage(fn($msg, $client) => processHeavyTask($msg));
 *   $loop->run();
 */
class ForkEventLoop implements EventLoopInterface
{
    /** @var array{connection: ConnectionInterface, callback: callable}|null */
    private ?array $readWatcher = null;

    /** @var array<string, array{interval: float, callback: callable, nextRun: float}> */
    private array $timers = [];

    /** @var array<string, array{at: float, callback: callable}> */
    private array $delayedCallbacks = [];

    private bool $running = false;

    /** Poll timeout in seconds */
    private float $pollTimeout;

    private int $timerCounter = 0;

    /** Maximum concurrent child processes */
    private int $maxChildren;

    /** Currently running child PIDs */
    private array $children = [];

    /**
     * @param float $pollTimeout   Seconds to block on each socket_select() cycle.
     * @param int   $maxChildren   Max concurrent child processes (0 = unlimited).
     */
    public function __construct(float $pollTimeout = 0.5, int $maxChildren = 50)
    {
        if (!function_exists('pcntl_fork')) {
            throw new \RuntimeException(
                'pcntl extension is required for the Fork driver. '
                . 'Make sure PHP is compiled with --enable-pcntl.'
            );
        }

        $this->pollTimeout  = $pollTimeout;
        $this->maxChildren  = $maxChildren;

        // Install SIGCHLD handler to reap zombie processes
        pcntl_async_signals(true);
        pcntl_signal(SIGCHLD, function () {
            $this->reapChildren();
        });
    }

    public function getName(): string
    {
        return 'fork';
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable(ConnectionInterface $connection, callable $callback): void
    {
        $this->readWatcher = [
            'connection' => $connection,
            'callback'   => $callback,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer(float $intervalSeconds, callable $callback): string
    {
        $id = 'fork_timer_' . (++$this->timerCounter);
        $this->timers[$id] = [
            'interval' => $intervalSeconds,
            'callback' => $callback,
            'nextRun'  => microtime(true) + $intervalSeconds,
        ];
        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function addDelayedCallback(float $delaySeconds, callable $callback): string
    {
        $id = 'fork_delayed_' . (++$this->timerCounter);
        $this->delayedCallbacks[$id] = [
            'at'       => microtime(true) + $delaySeconds,
            'callback' => $callback,
        ];
        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(string $timerId): void
    {
        unset($this->timers[$timerId], $this->delayedCallbacks[$timerId]);
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            $this->tick();
        }

        // Wait for remaining children
        $this->waitAllChildren();
    }

    /**
     * Execute a single tick of the event loop.
     */
    public function tick(): void
    {
        // 1. Check socket readability
        $this->pollSocket();

        // 2. Run expired timers (in main process)
        $this->runTimers();

        // 3. Non-blocking reap of finished children
        $this->reapChildren();
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * {@inheritdoc}
     *
     * Fork driver: runs the callback in a child process.
     * This provides TRUE parallelism — even CPU-bound loops and
     * raw sleep() calls will NOT block the main event loop.
     */
    public function queueCallback(callable $callback): void
    {
        // Enforce max children limit
        while ($this->maxChildren > 0 && count($this->children) >= $this->maxChildren) {
            $this->reapChildren();
            if (count($this->children) >= $this->maxChildren) {
                usleep(1000); // 1ms wait
            }
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            // Fork failed — fallback to direct execution
            error_log('[ForkEventLoop] pcntl_fork() failed, executing inline');
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[ForkEventLoop] Handler error: {$e->getMessage()}");
            }
            return;
        }

        if ($pid === 0) {
            // ── Child process ──────────────────────────────────────
            // Reset signal handler (child should not reap siblings)
            pcntl_signal(SIGCHLD, SIG_DFL);

            // Stop the event loop state in the child — we're not
            // running the loop here, just executing the handler
            $this->running = false;
            $this->readWatcher = null;
            $this->timers = [];
            $this->delayedCallbacks = [];
            $this->children = [];

            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[ForkEventLoop] Child handler error: {$e->getMessage()}");
            }

            // Exit the child — use posix_kill(SIGKILL) to avoid running
            // parent's shutdown handlers / destructors. This prevents the
            // child from saving session files, closing the parent's socket
            // via Client::__destruct(), etc.
            if (function_exists('posix_kill')) {
                posix_kill(getmypid(), SIGKILL);
            }
            exit(0);
        }

        // ── Parent process ─────────────────────────────────────────
        $this->children[$pid] = true;
    }

    // ────────────────────────────────────────────────────────────────────
    //  Internals
    // ────────────────────────────────────────────────────────────────────

    /**
     * Poll the socket via socket_select and call the read callback if data is available.
     */
    private function pollSocket(): void
    {
        if ($this->readWatcher === null) {
            usleep((int) ($this->pollTimeout * 1_000_000));
            return;
        }

        /** @var SyncConnection $conn */
        $conn = $this->readWatcher['connection'];

        if (!$conn->isConnected()) {
            return;
        }

        $socket = $conn->getSocket();
        if ($socket === null) {
            return;
        }

        $read   = [$socket];
        $write  = [];
        $except = [];

        $sec  = (int) $this->pollTimeout;
        $usec = (int) (($this->pollTimeout - $sec) * 1_000_000);

        $result = @socket_select($read, $write, $except, $sec, $usec);

        if ($result > 0) {
            // Socket reading ALWAYS happens in the main process
            // (only handlers are forked)
            ($this->readWatcher['callback'])($conn);
        }
    }

    /**
     * Run any expired periodic and delayed timers (in main process).
     */
    private function runTimers(): void
    {
        $now = microtime(true);

        foreach ($this->timers as $id => &$timer) {
            if ($now >= $timer['nextRun']) {
                try {
                    ($timer['callback'])();
                } catch (\Throwable $e) {
                    error_log("[ForkEventLoop] Timer {$id} error: {$e->getMessage()}");
                }
                $timer['nextRun'] = $now + $timer['interval'];
            }
        }
        unset($timer);

        foreach ($this->delayedCallbacks as $id => $cb) {
            if ($now >= $cb['at']) {
                try {
                    ($cb['callback'])();
                } catch (\Throwable $e) {
                    error_log("[ForkEventLoop] Delayed {$id} error: {$e->getMessage()}");
                }
                unset($this->delayedCallbacks[$id]);
            }
        }
    }

    /**
     * Non-blocking reap of finished child processes.
     */
    private function reapChildren(): void
    {
        foreach ($this->children as $pid => $_) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result > 0 || $result === -1) {
                unset($this->children[$pid]);
            }
        }
    }

    /**
     * Wait for all remaining children to finish (used at shutdown).
     */
    private function waitAllChildren(): void
    {
        foreach ($this->children as $pid => $_) {
            pcntl_waitpid($pid, $status);
        }
        $this->children = [];
    }
}
