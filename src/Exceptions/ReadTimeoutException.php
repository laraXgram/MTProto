<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Exceptions;

class ReadTimeoutException extends TransportException
{
    public static function idle(): static
    {
        return new static('Read timeout on frame boundary (idle link)');
    }
}
