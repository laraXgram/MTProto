<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Exceptions\ReadTimeoutException;
use LaraGram\MTProto\Exceptions\TransportException;

/**
 * Abridged transport protocol.
 * 
 * The simplest MTProto transport:
 * - Single byte 0xef as initial handshake
 * - 1 byte length for messages < 127*4 bytes
 * - 4 bytes length (0x7f prefix) for larger messages
 * 
 * @see https://core.telegram.org/mtproto/mtproto-transports#abridged
 */
class AbridgedTransport implements TransportInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'abridged';
    }

    /**
     * {@inheritdoc}
     */
    public function getInitialBytes(): string
    {
        return "\xef";
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(string $payload): string
    {
        $length = strlen($payload) / 4;

        if ($length < 127) {
            return chr((int)$length) . $payload;
        }

        // 0x7f prefix + 3 bytes length (little-endian)
        return "\x7f" . substr(pack('V', (int)$length), 0, 3) . $payload;
    }

    /**
     * {@inheritdoc}
     */
    public function unwrap(string $data): string
    {
        // Length prefix already stripped by readLength()
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function readLength(ConnectionInterface $connection): int
    {
        $byte = $connection->receive(1);

        if ($byte === null) {
            // Zero bytes consumed - still on a frame boundary; idle, not broken.
            throw ReadTimeoutException::idle();
        }

        $length = ord($byte);

        // Check for transport error
        if ($length === 0) {
            throw TransportException::invalidLength(0);
        }

        // Extended length format
        if ($length === 0x7f) {
            $lengthBytes = $connection->receive(3);
            if ($lengthBytes === null) {
                throw TransportException::invalidFrame('Failed to read extended length');
            }
            $length = unpack('V', $lengthBytes . "\x00")[1];
        }

        // Length is in 4-byte units
        $byteLength = $length * 4;

        // Sanity check
        if ($byteLength < 0 || $byteLength > 16 * 1024 * 1024) { // Max 16MB
            throw TransportException::invalidLength($byteLength);
        }

        return $byteLength;
    }
}
