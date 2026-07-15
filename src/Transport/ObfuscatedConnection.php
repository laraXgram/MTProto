<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Crypto\StreamCipher;

/**
 * @see https://core.telegram.org/mtproto/mtproto-transports#transport-obfuscation
 */
final class ObfuscatedConnection implements ConnectionInterface
{
    /**
     * Protocol tags placed at bytes 56..59 of the handshake.
     */
    public const TAG_ABRIDGED     = "\xef\xef\xef\xef";
    public const TAG_INTERMEDIATE = "\xee\xee\xee\xee";
    public const TAG_PADDED       = "\xdd\xdd\xdd\xdd";
    public const TAG_FULL         = "";

    /**
     * First-4-byte prefixes that must never appear in the handshake - they
     * collide with HTTP/TLS/other transport markers and would be misread by the
     * server (or a middlebox) as a different protocol.
     */
    private const FORBIDDEN_PREFIXES = [
        "HEAD", "POST", "GET ", "OPTI",
        "\xee\xee\xee\xee",
        "\x16\x03\x01\x02",
    ];

    private ConnectionInterface $inner;
    private CryptoInterface $crypto;
    private string $protocolTag;

    /**
     * MTProxy 16-byte secret. When set, the CTR keys are SHA-256-mixed with it
     * and the target DC id is embedded in the handshake (MTProxy relay, B5 tail).
     * Null = direct DC connection (plain obfuscated2).
     */
    private ?string $secret;

    /** Target DC id written to the handshake (proxy mode only). */
    private ?int $dcId;

    private ?StreamCipher $encrypt = null;
    private ?StreamCipher $decrypt = null;

    public function __construct(
        ConnectionInterface $inner,
        CryptoInterface $crypto,
        string $protocolTag = self::TAG_ABRIDGED,
        ?string $secret = null,
        ?int $dcId = null,
    ) {
        $this->inner       = $inner;
        $this->crypto      = $crypto;
        $this->protocolTag = $protocolTag;
        $this->secret      = $secret;
        $this->dcId        = $dcId;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $address, int $port, float $timeout = 10.0): bool
    {
        $ok = $this->inner->connect($address, $port, $timeout);

        if ($ok) {
            $this->handshake();
        }

        return $ok;
    }

    /**
     * Build and send the 64-byte obfuscation header, then arm the CTR ciphers.
     */
    private function handshake(): void
    {
        $header = $this->generateHeader();

        $encKey = substr($header, 8, 32);
        $encIv  = substr($header, 40, 16);

        // Decryption context is the reverse of bytes 8..55 (key then IV).
        $reversed = strrev(substr($header, 8, 48));
        $decKey   = substr($reversed, 0, 32);
        $decIv    = substr($reversed, 32, 16);

        // MTProxy relay: bind both keys to the proxy secret so only a client that
        // knows the secret can drive the stream. IVs are left untouched.
        if ($this->secret !== null) {
            $encKey = $this->crypto->sha256($encKey . $this->secret);
            $decKey = $this->crypto->sha256($decKey . $this->secret);
        }

        $this->encrypt = new StreamCipher($this->crypto, $encKey, $encIv);
        $this->decrypt = new StreamCipher($this->crypto, $decKey, $decIv);

        // Encrypt the whole header to derive its last 8 bytes; the first 56
        // stay plaintext so the server can recover the key. The encrypt cipher
        // keeps the advanced state, so real data continues from byte 64.
        $encrypted = $this->encrypt->process($header);
        $wire = substr($header, 0, 56) . substr($encrypted, 56, 8);

        $this->inner->send($wire);
    }

    /**
     * Produce a constraint-valid 64-byte handshake with the protocol tag set.
     */
    private function generateHeader(): string
    {
        do {
            $header = $this->crypto->randomBytes(64);
            $first4 = substr($header, 0, 4);
        } while (
            $header[0] === "\xef"
            || in_array($first4, self::FORBIDDEN_PREFIXES, true)
            || substr($header, 4, 4) === "\x00\x00\x00\x00"
        );

        if ($this->protocolTag !== '') {
            $header = substr_replace($header, $this->protocolTag, 56, 4);
        }

        // MTProxy: tell the proxy which DC to relay to. Signed little-endian
        // int16 at bytes 60..61 (bytes 62..63 stay random). Only meaningful when
        // a proxy secret is in play; a direct DC ignores these bytes.
        if ($this->dcId !== null) {
            $header = substr_replace($header, pack('v', $this->dcId & 0xffff), 60, 2);
        }

        return $header;
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $data): int
    {
        if ($this->encrypt === null) {
            return $this->inner->send($data);
        }

        return $this->inner->send($this->encrypt->process($data));
    }

    /**
     * {@inheritdoc}
     */
    public function receive(int $length = 0, float $timeout = 30.0): ?string
    {
        $data = $this->inner->receive($length, $timeout);

        if ($data === null || $data === '' || $this->decrypt === null) {
            return $data;
        }

        return $this->decrypt->process($data);
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->inner->disconnect();
        $this->encrypt = null;
        $this->decrypt = null;
    }

    public function isConnected(): bool          { return $this->inner->isConnected(); }
    public function getRemoteAddress(): ?string  { return $this->inner->getRemoteAddress(); }
    public function getRemotePort(): ?int        { return $this->inner->getRemotePort(); }

    /**
     * Expose the wrapped connection (e.g. for drivers that introspect it).
     */
    public function getInner(): ConnectionInterface
    {
        return $this->inner;
    }
}
