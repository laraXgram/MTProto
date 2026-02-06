<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Exceptions;

/**
 * Exception thrown when connection-related errors occur.
 */
class ConnectionException extends MTProtoException
{
    /**
     * Connection failed.
     */
    public static function connectionFailed(string $address, int $port, string $reason = ''): static
    {
        $message = "Failed to connect to {$address}:{$port}";
        if ($reason) {
            $message .= ": {$reason}";
        }
        return new static($message);
    }

    /**
     * Connection timeout.
     */
    public static function timeout(string $address, int $port, float $timeout): static
    {
        return new static("Connection to {$address}:{$port} timed out after {$timeout}s");
    }

    /**
     * Connection closed unexpectedly.
     */
    public static function closed(): static
    {
        return new static('Connection was closed unexpectedly');
    }

    /**
     * Not connected.
     */
    public static function notConnected(): static
    {
        return new static('Not connected to server');
    }

    /**
     * Send failed.
     */
    public static function sendFailed(string $reason = ''): static
    {
        $message = 'Failed to send data';
        if ($reason) {
            $message .= ": {$reason}";
        }
        return new static($message);
    }

    /**
     * Receive failed.
     */
    public static function receiveFailed(string $reason = ''): static
    {
        $message = 'Failed to receive data';
        if ($reason) {
            $message .= ": {$reason}";
        }
        return new static($message);
    }
}
