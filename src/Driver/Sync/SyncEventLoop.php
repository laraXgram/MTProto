<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Driver\Sync;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;

/**
 * Synchronous (blocking) event loop implementation.
 *
 * Uses socket_select() to poll the connection for readable data.
 * Timers are checked between each poll cycle.
 *
 * This is the default driver — no external dependencies required.
 */
class SyncEventLoop implements EventLoopInterface
{
    /** @var array{connection: ConnectionInterface, callback: callable}|null */
    private ?array $readWatcher = null;

    /** @var array<string, array{interval: float, callback: callable, nextRun: float}> */
    private array $timers = [];

    /** @var array<string, array{at: float, callback: callable}> */
    private array $delayedCallbacks = [];

    private bool $running = false;

    /** Poll timeout in seconds (how long socket_select blocks per cycle) */
    private float $pollTimeout;

    private int $timerCounter = 0;

    /**
     * @param float $pollTimeout  Seconds to block on each socket_select() cycle.
     *                            Lower = more responsive timers, higher = less CPU.
     */
    public function __construct(float $pollTimeout = 0.5)
    {
        $this->pollTimeout = $pollTimeout;
    }

    public function getName(): string
    {
        return 'sync';
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
        $id = 'timer_' . (++$this->timerCounter);
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
        $id = 'delayed_' . (++$this->timerCounter);
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
    }

    /**
     * Execute a single tick of the event loop.
     *
     * Useful for manual loop control (e.g. integrating with a framework).
     */
    public function tick(): void
    {
        // 1. Check socket readability
        $this->pollSocket();

        // 2. Run expired timers
        $this->runTimers();
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
     * Sync driver: just call immediately (blocking is expected).
     */
    public function queueCallback(callable $callback): void
    {
        $callback();
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
            // No socket to watch — just sleep a bit
            usleep((int) ($this->pollTimeout * 1_000_000));
            return;
        }

        /** @var SyncConnection $conn */
        $conn = $this->readWatcher['connection'];

        if (!$conn->isConnected()) {
            return;
        }

        // Use socket_select to check readability
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
            ($this->readWatcher['callback'])($conn);
        }
    }

    /**
     * Run any expired periodic and delayed timers.
     */
    private function runTimers(): void
    {
        $now = microtime(true);

        // Periodic timers
        foreach ($this->timers as $id => &$timer) {
            if ($now >= $timer['nextRun']) {
                try {
                    ($timer['callback'])();
                } catch (\Throwable $e) {
                    error_log("[SyncEventLoop] Timer {$id} error: {$e->getMessage()}");
                }
                $timer['nextRun'] = $now + $timer['interval'];
            }
        }
        unset($timer);

        // Delayed (one-shot) callbacks
        foreach ($this->delayedCallbacks as $id => $cb) {
            if ($now >= $cb['at']) {
                try {
                    ($cb['callback'])();
                } catch (\Throwable $e) {
                    error_log("[SyncEventLoop] Delayed {$id} error: {$e->getMessage()}");
                }
                unset($this->delayedCallbacks[$id]);
            }
        }
    }
}
