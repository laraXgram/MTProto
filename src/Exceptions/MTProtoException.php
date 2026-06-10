<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Exceptions;

/**
 * Base exception for all MTProto errors.
 */
class MTProtoException extends \Exception
{
    /**
     * Create exception with formatted message.
     */
    public static function create(string $message, int $code = 0, ?\Throwable $previous = null): static
    {
        return new static($message, $code, $previous);
    }
}
