<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Exceptions;

/**
 * Exception thrown when transport-related errors occur.
 */
class TransportException extends MTProtoException
{
    /**
     * Invalid transport frame.
     */
    public static function invalidFrame(string $reason = ''): static
    {
        $message = 'Invalid transport frame';
        if ($reason) {
            $message .= ": {$reason}";
        }
        return new static($message);
    }

    /**
     * Transport error code received from server.
     */
    public static function errorCode(int $code): static
    {
        $message = match ($code) {
            404 => 'Auth key not found in the system',
            429 => 'Transport flood - slow down your requests',
            444 => 'Invalid data center',
            default => "Unknown transport error code: {$code}",
        };
        return new static($message, $code);
    }

    /**
     * Invalid length.
     */
    public static function invalidLength(int $length): static
    {
        return new static("Invalid message length: {$length}");
    }

    /**
     * CRC32 mismatch (for Full transport).
     */
    public static function checksumMismatch(): static
    {
        return new static('CRC32 checksum mismatch');
    }
}
