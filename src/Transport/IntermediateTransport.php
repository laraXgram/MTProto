<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Exceptions\TransportException;

/**
 * Intermediate transport protocol.
 * 
 * Slightly more complex than Abridged:
 * - 4 bytes 0xeeeeeeee as initial handshake
 * - 4 bytes length (little-endian) for every message
 * 
 * @see https://core.telegram.org/mtproto/mtproto-transports#intermediate
 */
class IntermediateTransport implements TransportInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'intermediate';
    }

    /**
     * {@inheritdoc}
     */
    public function getInitialBytes(): string
    {
        return "\xee\xee\xee\xee";
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(string $payload): string
    {
        return pack('V', strlen($payload)) . $payload;
    }

    /**
     * {@inheritdoc}
     */
    public function unwrap(string $data): string
    {
        // Remove 4-byte length header if present
        if (strlen($data) >= 4) {
            $length = unpack('V', substr($data, 0, 4))[1];
            if ($length === strlen($data) - 4) {
                return substr($data, 4);
            }
        }
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function readLength(ConnectionInterface $connection): int
    {
        $lengthBytes = $connection->receive(4);
        
        if ($lengthBytes === null) {
            throw TransportException::invalidFrame('Failed to read length');
        }

        $length = unpack('V', $lengthBytes)[1];

        // Check for transport error (negative length = error code)
        if ($length === 0xffffffff) {
            throw TransportException::invalidLength(-1);
        }

        // Sanity check
        if ($length < 0 || $length > 16 * 1024 * 1024) {
            throw TransportException::invalidLength($length);
        }

        return $length;
    }
}
