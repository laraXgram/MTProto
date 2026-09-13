<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Events;

final class UpdateReceived
{
    /**
     * @param array<string, mixed> $update
     */
    public function __construct(
        public readonly array $update,
        public readonly string $type,
        public readonly string $session,
    ) {
    }
}
