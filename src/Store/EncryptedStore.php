<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\Encryption\Encrypter;
use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Exceptions\SecurityException;

/**
 * Encrypts every value before it reaches the wrapped store (file, redis,
 * database, swoole-table, and any file mirror behind them), so auth keys and
 * peer access hashes are never at rest in plain text.
 *
 * Plain-text values written before encryption was enabled are still read and
 * get encrypted on their next write, so turning encryption on needs no
 * migration step.
 */
final class EncryptedStore implements Store
{
    /** Marks an encrypted value (and lets unencrypted readers refuse it). */
    public const PREFIX = 'lgenc:';

    public function __construct(
        private readonly Store $inner,
        private readonly Encrypter $encrypter,
    ) {
    }

    /**
     * Build an Encrypter from a session key: `base64:`-prefixed 32 bytes, or
     * any passphrase (stretched with SHA-256).
     */
    public static function encrypter(string $key): Encrypter
    {
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if (strlen($key) !== 32) {
            $key = hash('sha256', $key, true);
        }

        return new Encrypter($key, 'AES-256-CBC');
    }

    /**
     * Refuse to treat encrypted content as missing: without this, a client
     * started without the key would see "no session" and overwrite the
     * encrypted auth key with a fresh one.
     */
    public static function refuseIfEncrypted(?string $content, string $what): void
    {
        if ($content !== null && str_starts_with($content, self::PREFIX)) {
            throw new SecurityException(
                "The {$what} is encrypted. Set CLIENT_SESSION_KEY (mtproto.session.encryption.key) to the key it was encrypted with."
            );
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->inner->get($key);

        if ($value === null || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        try {
            return $this->encrypter->decryptString(substr($value, strlen(self::PREFIX)));
        } catch (\Throwable) {
            throw new SecurityException("Cannot decrypt the stored '{$key}' state - the session key is wrong.");
        }
    }

    public function put(string $key, string $value): void
    {
        $this->inner->put($key, self::PREFIX . $this->encrypter->encryptString($value));
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function forget(string $key): bool
    {
        return $this->inner->forget($key);
    }
}
