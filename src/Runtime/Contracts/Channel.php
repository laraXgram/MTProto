<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime\Contracts;

/**
 * A coroutine-safe, bounded, blocking queue.
 *
 * Used by {@see \LaraGram\MTProto\RPC\MessagePump} both as a per-call result
 * mailbox and as a single-token write mutex. The concrete implementation is
 * runtime-specific ({@see \LaraGram\MTProto\Runtime\SwooleChannel}); nothing in
 * Core touches the underlying coroutine extension directly (RULE 1).
 */
interface Channel
{
    /**
     * Push a value, blocking the current coroutine while the channel is full.
     *
     * @param float $timeout Seconds to wait; -1 blocks indefinitely.
     * @return bool false on timeout or when the channel is closed.
     */
    public function push(mixed $value, float $timeout = -1): bool;

    /**
     * Pop a value, blocking the current coroutine while the channel is empty.
     *
     * @param float $timeout Seconds to wait; -1 blocks indefinitely.
     * @return mixed The value, or false on timeout / closed channel.
     */
    public function pop(float $timeout = -1): mixed;

    /**
     * Close the channel, waking every parked coroutine with a false return.
     */
    public function close(): void;
}
