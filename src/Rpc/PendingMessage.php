<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

/**
 * Represents a pending RPC message
 */
final class PendingMessage
{
    public function __construct(
        public readonly int    $msgId,
        public readonly string $method,
        public readonly array  $params,
        public readonly float  $sentAt,
        public int             $retries = 0,
    )
    {
    }
}
