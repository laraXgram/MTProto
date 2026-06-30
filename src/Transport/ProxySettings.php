<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Exceptions\TransportException;

/**
 * @see https://core.telegram.org/mtproto/mtproto-transports#transport-obfuscation
 */
final class ProxySettings
{
    /** Raw 16-byte proxy secret (marker byte already stripped). */
    private string $secret;

    /** Whether the secret carried the 0xdd random-padding marker. */
    private bool $padded;

    /** FakeTLS SNI domain (from an 0xee secret), or '' when not FakeTLS. */
    private string $domain;

    public function __construct(
        private string $host,
        private int    $port,
        string         $secret,
        bool           $padded = false,
        string         $domain = '',
    )
    {
        if (strlen($secret) !== 16) {
            throw new TransportException(
                'MTProxy secret must be exactly 16 bytes after marker stripping, got ' . strlen($secret) . '.'
            );
        }

        $this->secret = $secret;
        $this->padded = $padded;
        $this->domain = $domain;
    }

    /**
     * Build from config values. Accepts hex or raw-byte secrets and auto-detects
     * the `dd` (padded) and `ee` (FakeTLS) markers.
     */
    public static function fromConfig(string $host, int $port, string $secret): self
    {
        $secret = trim($secret);

        if ($host === '' || $port <= 0 || $secret === '') {
            throw new TransportException('MTProxy requires host, port and secret.');
        }

        // Accept a hex secret (the common tg:// form) or already-raw bytes.
        $bytes = self::looksHex($secret)
            ? (string)hex2bin($secret)
            : $secret;

        $padded = false;
        $domain = '';

        if (strlen($bytes) === 17 && $bytes[0] === "\xdd") {
            $padded = true;
            $bytes = substr($bytes, 1);
        } elseif (strlen($bytes) >= 17 && $bytes[0] === "\xee") {
            // FakeTLS: 0xee marker, 16-byte secret, then the SNI domain (raw bytes).
            $domain = substr($bytes, 17);
            $bytes = substr($bytes, 1, 16);

            if ($domain === '') {
                throw new TransportException('FakeTLS (ee) secret carries no SNI domain.');
            }
        }

        return new self($host, $port, $bytes, $padded, $domain);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function isPadded(): bool
    {
        return $this->padded;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function isFakeTls(): bool
    {
        return $this->domain !== '';
    }

    /**
     * Whether a hex string is a clean even-length hex blob (32 or 34 chars for a
     * 16- or 17-byte secret). Raw 16-byte secrets that happen to be valid hex are
     * indistinguishable, but those are 16 chars - too short to be a 32-hex secret -
     * so this stays unambiguous for real values.
     */
    private static function looksHex(string $s): bool
    {
        return (strlen($s) % 2) === 0
            && strlen($s) >= 32
            && ctype_xdigit($s);
    }
}
