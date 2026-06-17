<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime;

use LaraGram\MTProto\Runtime\Contracts\Channel;

/**
 * {@see Channel} backed by Swoole's coroutine channel.
 */
final class SwooleChannel implements Channel
{
    private \Swoole\Coroutine\Channel $channel;

    public function __construct(int $capacity = 1)
    {
        $this->channel = new \Swoole\Coroutine\Channel(max(1, $capacity));
    }

    public function push(mixed $value, float $timeout = -1): bool
    {
        return $this->channel->push($value, $timeout);
    }

    public function pop(float $timeout = -1): mixed
    {
        return $this->channel->pop($timeout);
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
