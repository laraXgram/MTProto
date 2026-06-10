<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Exceptions;

/**
 * Exception thrown when security checks fail.
 */
class SecurityException extends MTProtoException
{
    /**
     * Security check failed.
     */
    public static function checkFailed(string $check): static
    {
        return new static("Security check failed: {$check}");
    }

    /**
     * Invalid auth key.
     */
    public static function invalidAuthKey(string $reason = ''): static
    {
        $message = 'Invalid authorization key';
        if ($reason) {
            $message .= ": {$reason}";
        }
        return new static($message);
    }

    /**
     * Message key mismatch.
     */
    public static function msgKeyMismatch(): static
    {
        return new static('Message key verification failed');
    }

    /**
     * Session ID mismatch.
     */
    public static function sessionIdMismatch(): static
    {
        return new static('Session ID mismatch');
    }

    /**
     * Invalid message ID.
     */
    public static function invalidMsgId(int $msgId, string $reason = ''): static
    {
        $message = "Invalid message ID: {$msgId}";
        if ($reason) {
            $message .= " ({$reason})";
        }
        return new static($message);
    }

    /**
     * Replay attack detected.
     */
    public static function replayAttack(): static
    {
        return new static('Possible replay attack detected');
    }
}
