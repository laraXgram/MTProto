<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Exceptions\TransportException;

/**
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
        // Padded intermediate (per spec): length(4, LE) || payload || padding,
        // where length covers BOTH payload and padding. There is NO second
        // (nested) length field. the inner MTProto message carries its own
        // length, and on read the 0-15 trailing pad bytes are stripped by
        // 16-byte alignment (see unwrap()). An MTProxy with a `dd` secret rejects
        // and drops the connection if the frame carries anything else.
        $paddingLength = random_int(0, 15);
        $padding = $paddingLength > 0 ? random_bytes($paddingLength) : '';

        return pack('V', strlen($payload) + $paddingLength) . $payload . $padding;
    }

    /**
     * {@inheritdoc}
     */
    public function unwrap(string $data): string
    {
        // $data is the frame body (the 4-byte length was already consumed by
        // readLength). It is payload||padding with 0-15 trailing pad bytes. An
        // MTProto encrypted frame is auth_key_id(8) + msg_key(16) + ciphertext,
        // and the ciphertext is always a multiple of 16, so everything after the
        // 24-byte header must be 16-aligned, the remainder is exactly the pad.
        $len = strlen($data);
        if ($len >= 24) {
            $pad = ($len - 24) % 16;
            return $pad > 0 ? substr($data, 0, $len - $pad) : $data;
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
            // Zero bytes consumed - still on a frame boundary; idle, not broken.
            throw \LaraGram\MTProto\Exceptions\ReadTimeoutException::idle();
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
