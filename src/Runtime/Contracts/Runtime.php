<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime\Contracts;

interface Runtime
{
    /**
     * Whether this runtime can actually schedule coroutines (extension loaded).
     */
    public function isSupported(): bool;

    /**
     * Whether the caller is currently executing inside a coroutine context.
     */
    public function inCoroutine(): bool;

    /**
     * Run $main inside a fresh coroutine container, blocking until it (and every
     * coroutine it spawned) completes. No-op wrapper if already in a context.
     */
    public function run(callable $main): void;

    /**
     * Fire a long-lived coroutine. Returns immediately; the coroutine runs until
     * its callable returns.
     */
    public function spawn(callable $fn): void;

    /**
     * Create a bounded channel.
     */
    public function channel(int $capacity = 1): Channel;

    /**
     * Register a periodic timer. Returns an id usable with {@see clearTimer()}.
     */
    public function timer(float $seconds, callable $callback): int;

    /**
     * Cancel a timer previously created with {@see timer()}.
     */
    public function clearTimer(int $timerId): void;

    /**
     * Coroutine-yielding sleep (does not block the scheduler).
     */
    public function sleep(float $seconds): void;

    /**
     * Get (creating once) a named shared-memory table.
     */
    public function table(string $name, int $rows = 1024, int $valueSize = 8192): Table;
}
