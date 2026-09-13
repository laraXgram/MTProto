<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\TL\TLObject;
use Throwable;

final class Frame
{
    public const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * @param resource $stream
     * @param array<string, mixed> $payload
     */
    public static function write($stream, array $payload): void
    {
        try {
            $body = serialize($payload);
        } catch (Throwable $e) {
            throw new MTProtoException('MTProto RPC payload is not serializable: ' . $e->getMessage(), 0, $e);
        }

        $data = pack('N', strlen($body)) . $body;
        $length = strlen($data);

        for ($written = 0; $written < $length; $written += $bytes) {
            $bytes = @fwrite($stream, substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                throw new MTProtoException('MTProto RPC connection closed while writing.');
            }
        }
    }

    /**
     * Read the next frame, or null when the peer closed the connection cleanly.
     *
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    public static function read($stream): ?array
    {
        $header = self::readBytes($stream, 4, true);

        if ($header === null) {
            return null;
        }

        $length = unpack('N', $header)[1];

        if ($length > self::MAX_BYTES) {
            throw new MTProtoException("MTProto RPC frame of {$length} bytes exceeds the limit.");
        }

        $payload = unserialize(self::readBytes($stream, $length, false) ?? '', [
            'allowed_classes' => [TLObject::class],
        ]);

        if (!is_array($payload)) {
            throw new MTProtoException('Malformed MTProto RPC frame.');
        }

        return $payload;
    }

    /**
     * @param resource $stream
     */
    private static function readBytes($stream, int $length, bool $allowEof): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = @fread($stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($stream)['timed_out'] ?? false) {
                    throw new MTProtoException('MTProto RPC call timed out.');
                }

                if ($buffer === '' && $allowEof && feof($stream)) {
                    return null;
                }

                if (feof($stream)) {
                    throw new MTProtoException('MTProto RPC connection closed while reading.');
                }

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
