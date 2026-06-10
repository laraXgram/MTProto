<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Driver\Swoole;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Driver\Sync\SyncConnection;

/**
 * Swoole event loop driver.
 *
 * Uses Swoole\Event and Swoole\Timer for non-blocking I/O, and
 * Swoole\Runtime::enableCoroutine() to hook all blocking PHP functions.
 *
 * With coroutine hooking enabled, PHP's sleep(), file_get_contents(),
 * and socket operations automatically yield to the Swoole scheduler.
 *
 * IMPORTANT: CPU-bound loops (like `for ($i = 0; $i < 1e9; $i++)`)
 * still block because they never hit an I/O suspension point. For
 * CPU-bound handlers, use the Fork driver instead.
 *
 * Requirements:
 *   PHP extension: swoole  (pecl install swoole)
 *
 * Usage:
 *   $loop = new UpdateLoop($client, new SwooleEventLoop());
 *   $loop->on('message', fn($msg) => echo $msg->text);
 *   $loop->run();
 */
class SwooleEventLoop implements EventLoopInterface
{
    /** @var array<string, int> Map our timer IDs → Swoole timer IDs */
    private array $timerMap = [];

    /** Whether we registered a socket read watcher */
    private bool $hasReadWatcher = false;

    /** The watched socket stream for Swoole\Event */
    private mixed $watchedStream = null;

    private bool $running = false;

    private int $timerCounter = 0;

    public function __construct()
    {
        if (!extension_loaded('swoole') && !extension_loaded('openswoole')) {
            throw new \RuntimeException(
                'Swoole or OpenSwoole extension is required for this driver. '
                . 'Install with: pecl install swoole'
            );
        }

        // Enable coroutine hooking for all blocking PHP functions.
        // This makes sleep(), file_get_contents(), socket_read(), etc.
        // automatically yield to the Swoole scheduler instead of blocking.
        if (class_exists(\Swoole\Runtime::class)) {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        }
    }

    public function getName(): string
    {
        return 'swoole';
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable(ConnectionInterface $connection, callable $callback): void
    {
        // Remove previous watcher
        $this->removeReadWatcher();

        if (!$connection->isConnected()) {
            return;
        }

        $socket = $this->extractSocket($connection);
        if ($socket === null) {
            throw new \RuntimeException(
                'Cannot watch connection: unable to extract socket resource. '
                . 'The Swoole driver requires a connection that exposes getSocket().'
            );
        }

        // Swoole\Event::add expects a stream resource
        $stream = $this->socketToStream($socket);

        $result = \Swoole\Event::add(
            $stream,
            static function ($stream) use ($connection, $callback): void {
                $callback($connection);
            },
            null,  // no write callback
            SWOOLE_EVENT_READ
        );

        if ($result) {
            $this->hasReadWatcher = true;
            $this->watchedStream = $stream;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer(float $intervalSeconds, callable $callback): string
    {
        $id = 'swoole_timer_' . (++$this->timerCounter);
        $ms = max(1, (int) ($intervalSeconds * 1000));

        $swooleId = \Swoole\Timer::tick($ms, static function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[SwooleEventLoop] Timer error: {$e->getMessage()}");
            }
        });

        $this->timerMap[$id] = $swooleId;

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function addDelayedCallback(float $delaySeconds, callable $callback): string
    {
        $id = 'swoole_delayed_' . (++$this->timerCounter);
        $ms = max(1, (int) ($delaySeconds * 1000));

        $swooleId = \Swoole\Timer::after($ms, function () use ($id, $callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[SwooleEventLoop] Delayed callback error: {$e->getMessage()}");
            } finally {
                unset($this->timerMap[$id]);
            }
        });

        $this->timerMap[$id] = $swooleId;

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(string $timerId): void
    {
        if (isset($this->timerMap[$timerId])) {
            \Swoole\Timer::clear($this->timerMap[$timerId]);
            unset($this->timerMap[$timerId]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->running = true;

        // Swoole\Event::wait() must run inside a coroutine container
        // when using coroutine features. Wrap if not already in one.
        if (\Swoole\Coroutine::getCid() === -1) {
            \Swoole\Coroutine\run(function () {
                \Swoole\Event::wait();
            });
        } else {
            \Swoole\Event::wait();
        }

        $this->running = false;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;

        // Remove socket watcher
        $this->removeReadWatcher();

        // Clear all timers
        foreach ($this->timerMap as $id => $swooleId) {
            \Swoole\Timer::clear($swooleId);
        }
        $this->timerMap = [];

        // Exit the event loop
        \Swoole\Event::exit();
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
     * Swoole driver: runs the callback inside a new coroutine so that
     * blocking calls like sleep() do not freeze the event loop.
     */
    public function queueCallback(callable $callback): void
    {
        \Swoole\Coroutine::create(static function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("[SwooleEventLoop] Queued callback error: {$e->getMessage()}");
            }
        });
    }

    // ────────────────────────────────────────────────────────────────────
    //  Internals
    // ────────────────────────────────────────────────────────────────────

    /**
     * Remove the current socket read watcher.
     */
    private function removeReadWatcher(): void
    {
        if ($this->hasReadWatcher && $this->watchedStream !== null) {
            @\Swoole\Event::del($this->watchedStream);
            $this->hasReadWatcher = false;
            $this->watchedStream = null;
        }
    }

    /**
     * Extract the native PHP socket from a ConnectionInterface.
     *
     * @return \Socket|resource|null
     */
    private function extractSocket(ConnectionInterface $connection): mixed
    {
        if ($connection instanceof SyncConnection) {
            return $connection->getSocket();
        }

        if (method_exists($connection, 'getSocket')) {
            return $connection->getSocket();
        }

        return null;
    }

    /**
     * Convert a Socket object or resource to a stream resource
     * that Swoole\Event can work with.
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
            return $stream;
        }

        if (is_resource($socket)) {
            return $socket;
        }

        throw new \RuntimeException('Unsupported socket type: ' . get_debug_type($socket));
    }
}
