<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

/**
 * Event loop abstraction for update listening.
 *
 * Drivers (sync, amphp, swoole) implement this to provide their
 * own I/O multiplexing strategy.  The update system only depends
 * on this interface — never on concrete socket/timer functions.
 */
interface EventLoopInterface
{
    /**
     * Get the driver name.
     */
    public function getName(): string;

    /**
     * Register a callback to be invoked when the socket has data to read.
     *
     * @param ConnectionInterface $connection  The TCP connection to watch.
     * @param callable(ConnectionInterface): void $callback  Called when data is available.
     */
    public function onReadable(ConnectionInterface $connection, callable $callback): void;

    /**
     * Schedule a periodic timer.
     *
     * @param float    $intervalSeconds  Interval between ticks.
     * @param callable(): void $callback Called on every tick.
     * @return string  Timer ID (for cancellation).
     */
    public function addTimer(float $intervalSeconds, callable $callback): string;

    /**
     * Schedule a one-shot timer.
     *
     * @param float    $delaySeconds  Delay before execution.
     * @param callable(): void $callback Called once.
     * @return string  Timer ID.
     */
    public function addDelayedCallback(float $delaySeconds, callable $callback): string;

    /**
     * Cancel a timer.
     *
     * @param string $timerId  ID returned by addTimer / addDelayedCallback.
     */
    public function cancelTimer(string $timerId): void;

    /**
     * Run the event loop (blocks until stop() is called).
     */
    public function run(): void;

    /**
     * Signal the loop to stop after current tick.
     */
    public function stop(): void;

    /**
     * Whether the loop is currently running.
     */
    public function isRunning(): bool;

    /**
     * Queue a callback for non-blocking execution.
     *
     * On async drivers (amphp, swoole) the callback runs concurrently
     * so that blocking calls like sleep() do not freeze the event loop.
     * On the sync driver the callback is invoked immediately (blocking
     * is acceptable there).
     *
     * @param callable(): void $callback
     */
    public function queueCallback(callable $callback): void;
}
