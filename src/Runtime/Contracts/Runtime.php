<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime\Contracts;

/**
 * Coroutine runtime abstraction (RULE 1).
 *
 * The MTProto async core (MessagePump) needs primitives the framework does not
 * expose directly: a long-lived spawned coroutine, bounded channels, a write
 * mutex, periodic timers and a yielding sleep. Surge offers concurrent-and-wait
 * (`DispatchesCoroutines::resolve`) and server-bound `tick()`, but nothing for a
 * persistent reader coroutine — so we define a minimal contract here and keep
 * every `\Swoole\*` reference inside its concrete implementation.
 *
 * Swapping to a RoadRunner/FrankenPHP/OpenSwoole backend later = a new Runtime
 * implementation bound in the container; the pump is untouched.
 */
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
}
