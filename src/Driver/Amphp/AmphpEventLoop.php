<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Driver\Amphp;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Driver\Sync\SyncConnection;
use Revolt\EventLoop;

/**
 * amphp / Revolt event loop driver.
 *
 * Uses the Revolt event loop (amphp v3) for non-blocking I/O.
 * This gives you true async multiplexing while keeping the same
 * MTProto update pipeline.
 *
 * IMPORTANT: Revolt uses cooperative Fibers. Handlers are queued as
 * microtasks and run in separate Fibers, but they still share a single
 * thread. A CPU-bound loop that never yields will block the event loop.
 *
 * For non-blocking sleep, use:
 *   AmphpEventLoop::sleep(5);    // yields to the event loop
 *   NOT:  sleep(5);              // blocks the entire loop!
 *
 * For CPU-bound handlers, consider the Fork or Fiber drivers instead.
 *
 * Requirements:
 *   composer require revolt/event-loop
 *
 * Usage:
 *   $loop = new UpdateLoop($client, new AmphpEventLoop());
 *   $loop->on('message', fn($msg) => echo $msg->text);
 *   $loop->run();
 */
class AmphpEventLoop implements EventLoopInterface
{
    /** @var array<string, string> Map our timer IDs → Revolt watcher IDs */
    private array $watcherMap = [];

    /** Revolt readable watcher ID */
    private ?string $readWatcher = null;

    /** Stream resource kept alive for Revolt (so it doesn't get GC'd) */
    private mixed $readStream = null;

    private bool $running = false;

    private int $timerCounter = 0;

    /**
     * Non-blocking sleep for use inside handlers.
     *
     * This suspends the current Fiber and resumes it after $seconds,
     * WITHOUT blocking the event loop. Other handlers and socket reads
     * continue normally during the sleep.
     *
     * Usage inside a handler:
     *   AmphpEventLoop::sleep(5); // sleeps 5 seconds without blocking
     *
     * @param float $seconds  Duration to sleep (supports fractional).
     */
    public static function sleep(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();
    }

    public function __construct()
    {
        if (!class_exists(EventLoop::class)) {
            throw new \RuntimeException(
                'Revolt event loop is required for the amphp driver. '
                . 'Install it with: composer require revolt/event-loop'
            );
        }
    }

    public function getName(): string
    {
        return 'amphp';
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable(ConnectionInterface $connection, callable $callback): void
    {
        // Cancel previous watcher if any
        if ($this->readWatcher !== null) {
            EventLoop::cancel($this->readWatcher);
            $this->readWatcher = null;
        }

        if (!$connection->isConnected()) {
            return;
        }

        // Get the underlying PHP socket resource
        $socket = $this->extractSocket($connection);
        if ($socket === null) {
            throw new \RuntimeException(
                'Cannot watch connection: unable to extract socket resource. '
                . 'The amphp driver requires a connection that exposes getSocket().'
            );
        }

        // Revolt needs a stream resource, not a \Socket object
        $stream = $this->socketToStream($socket);

        $this->readWatcher = EventLoop::onReadable(
            $stream,
            static function (string $watcherId) use ($connection, $callback): void {
                $callback($connection);
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer(float $intervalSeconds, callable $callback): string
    {
        $id = 'amphp_timer_' . (++$this->timerCounter);

        $revoltId = EventLoop::repeat($intervalSeconds, static function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[AmphpEventLoop] Timer error: {$e->getMessage()}");
            }
        });

        $this->watcherMap[$id] = $revoltId;

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function addDelayedCallback(float $delaySeconds, callable $callback): string
    {
        $id = 'amphp_delayed_' . (++$this->timerCounter);

        $revoltId = EventLoop::delay($delaySeconds, function () use ($id, $callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[AmphpEventLoop] Delayed callback error: {$e->getMessage()}");
            } finally {
                unset($this->watcherMap[$id]);
            }
        });

        $this->watcherMap[$id] = $revoltId;

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(string $timerId): void
    {
        if (isset($this->watcherMap[$timerId])) {
            EventLoop::cancel($this->watcherMap[$timerId]);
            unset($this->watcherMap[$timerId]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->running = true;
        EventLoop::run();
        $this->running = false;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;

        // Cancel all watchers
        if ($this->readWatcher !== null) {
            EventLoop::cancel($this->readWatcher);
            $this->readWatcher = null;
            $this->readStream = null;
        }

        foreach ($this->watcherMap as $id => $revoltId) {
            EventLoop::cancel($revoltId);
        }
        $this->watcherMap = [];

        // Signal the Revolt driver to stop after current tick
        EventLoop::getDriver()->stop();
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
     * Amphp driver: schedules the callback as a microtask via
     * EventLoop::queue(). This means the callback runs in its own
     * Fiber — blocking calls like sleep() won't freeze the loop.
     */
    public function queueCallback(callable $callback): void
    {
        EventLoop::queue(static function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[AmphpEventLoop] Queued callback error: {$e->getMessage()}");
            }
        });
    }

    // ────────────────────────────────────────────────────────────────────
    //  Internals
    // ────────────────────────────────────────────────────────────────────

    /**
     * Extract the native PHP socket from a ConnectionInterface.
     *
     * Revolt's onReadable() needs a PHP stream resource.
     * SyncConnection exposes getSocket() which returns \Socket.
     *
     * @return \Socket|resource|null
     */
    private function extractSocket(ConnectionInterface $connection): mixed
    {
        if ($connection instanceof SyncConnection) {
            return $connection->getSocket();
        }

        // Duck-typing fallback
        if (method_exists($connection, 'getSocket')) {
            return $connection->getSocket();
        }

        return null;
    }

    /**
     * Convert a \Socket object to a stream resource.
     *
     * Revolt's StreamSelectDriver uses stream_select() which requires
     * stream resources, not \Socket objects.
     *
     * @return resource
     */
    private function socketToStream(mixed $socket)
    {
        if ($socket instanceof \Socket) {
            $stream = socket_export_stream($socket);
            if ($stream === false) {
                throw new \RuntimeException('Failed to export Socket to stream resource');
            }
            // Keep reference so GC doesn't close it
            $this->readStream = $stream;
            return $stream;
        }

        if (is_resource($socket)) {
            return $socket;
        }

        throw new \RuntimeException('Unsupported socket type: ' . get_debug_type($socket));
    }
}
