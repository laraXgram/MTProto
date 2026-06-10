<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Exceptions;

/**
 * Exception thrown when cryptographic operations fail.
 */
class CryptoException extends MTProtoException
{
    /**
     * Encryption failed.
     */
    public static function encryptionFailed(string $reason = ''): static
    {
        $message = 'Encryption failed';
        if ($reason) {
            $message .= ": {$reason}";
        }
        return new static($message);
    }

    /**
     * Decryption failed.
     */
    public static function decryptionFailed(string $reason = ''): static
    {
        $message = 'Decryption failed';
        if ($reason) {
            $message .= ": {$reason}";
        }
        return new static($message);
    }

    /**
     * Invalid key size.
     */
    public static function invalidKeySize(int $expected, int $actual): static
    {
        return new static("Invalid key size: expected {$expected} bytes, got {$actual}");
    }

    /**
     * Invalid IV size.
     */
    public static function invalidIvSize(int $expected, int $actual): static
    {
        return new static("Invalid IV size: expected {$expected} bytes, got {$actual}");
    }

    /**
     * Invalid data size (not aligned).
     */
    public static function dataNotAligned(int $blockSize): static
    {
        return new static("Data must be aligned to {$blockSize}-byte boundary");
    }

    /**
     * Random generation failed.
     */
    public static function randomGenerationFailed(): static
    {
        return new static('Failed to generate random bytes');
    }
}
