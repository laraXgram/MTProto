<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

use LaraGram\MTProto\Runtime\Contracts\Channel;

/**
 * An in-flight RPC awaiting its response.
 */
final class PendingCall
{
    public function __construct(
        public readonly Channel $channel,
        public readonly string  $payload,
        public readonly bool    $contentRelated,
        public readonly string  $method,
        public bool             $acked = false,
        /** @var list<int> every msg_id this call was sent under (resends keep aliases) */
        public array            $ids = [],
        public int              $resends = 0,
        /** False when the last send failed and the call never reached the server. */
        public bool             $sent = true,
    )
    {
    }
}
