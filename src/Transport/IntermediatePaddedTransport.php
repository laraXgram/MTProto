<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Exceptions\TransportException;

/**
 * Padded Intermediate transport protocol.
 * 
 * Like Intermediate but with random padding:
 * - 4 bytes 0xdddddddd as initial handshake
 * - 4 bytes length (little-endian)
 * - Random 0-15 padding bytes added to each message
 * 
 * @see https://core.telegram.org/mtproto/mtproto-transports#padded-intermediate
 */
class IntermediatePaddedTransport implements TransportInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'intermediate_padded';
    }

    /**
     * {@inheritdoc}
     */
    public function getInitialBytes(): string
    {
        return "\xdd\xdd\xdd\xdd";
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(string $payload): string
    {
        // Store original length for unwrap
        $originalLength = strlen($payload);
        
        // Add random padding (0-15 bytes)
        $paddingLength = random_int(0, 15);
        $padding = $paddingLength > 0 ? random_bytes($paddingLength) : '';
        
        $totalLength = $originalLength + $paddingLength;
        
        // Pack format: total_length(4) + original_length(4) + payload + padding
        return pack('V', $totalLength + 4) . pack('V', $originalLength) . $payload . $padding;
    }

    /**
     * {@inheritdoc}
     */
    public function unwrap(string $data): string
    {
        // Skip total length (4 bytes)
        if (strlen($data) >= 8) {
            $totalLength = unpack('V', substr($data, 0, 4))[1];
            $originalLength = unpack('V', substr($data, 4, 4))[1];
            
            // Check if this has our header format
            if ($totalLength === strlen($data) - 4 && $originalLength <= $totalLength - 4) {
                return substr($data, 8, $originalLength);
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

        // Check for transport error
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
