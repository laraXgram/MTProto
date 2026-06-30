<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\MTProto\Exceptions\MTProtoException;

final class FileId
{
    private const VERSION = 1;
    private const TYPE_PHOTO = 'p';
    private const TYPE_DOCUMENT = 'd';

    /**
     * Build a portable id string from a TL `photo`/`document` (or a media
     * wrapper containing one), as found on an incoming/echoed message.
     */
    public static function fromMedia(array $media): string
    {
        [$type, $obj] = self::extract($media);

        return self::encode(
            $type,
            (int)($obj['dc_id'] ?? 0),
            (int)($obj['id'] ?? 0),
            (int)($obj['access_hash'] ?? 0),
            (string)($obj['file_reference'] ?? ''),
        );
    }

    /**
     * Decode a file_id string into an `inputMediaPhoto`/`inputMediaDocument`
     * ready for `messages.sendMedia` / `messages.sendMultiMedia`.
     *
     * @param int|null $ttlSeconds Optional self-destruct timer.
     * @param bool $spoiler Mark the media as a spoiler.
     */
    public static function toInputMedia(string $fileId, ?int $ttlSeconds = null, bool $spoiler = false): array
    {
        ['type' => $type, 'dc' => $dc, 'id' => $id, 'hash' => $hash, 'ref' => $ref] = self::decode($fileId);

        $inner = [
            '_' => $type === self::TYPE_PHOTO ? 'inputPhoto' : 'inputDocument',
            'id' => $id,
            'access_hash' => $hash,
            'file_reference' => $ref,
        ];

        $media = [
            '_' => $type === self::TYPE_PHOTO ? 'inputMediaPhoto' : 'inputMediaDocument',
            'id' => $inner,
        ];

        if ($spoiler) {
            $media['spoiler'] = true;
        }
        if ($ttlSeconds !== null) {
            $media['ttl_seconds'] = $ttlSeconds;
        }

        return $media;
    }

    private static function encode(string $type, int $dc, int $id, int $hash, string $ref): string
    {
        $payload = chr(self::VERSION)
            . $type
            . chr($dc & 0xFF)
            . pack('P', $id)
            . pack('P', $hash)
            . pack('v', strlen($ref))
            . $ref;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * @return array{type: string, dc: int, id: int, hash: int, ref: string}
     */
    public static function decode(string $fileId): array
    {
        $raw = base64_decode(strtr($fileId, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 21) {
            throw new MTProtoException('Malformed file_id');
        }

        $version = ord($raw[0]);
        if ($version !== self::VERSION) {
            throw new MTProtoException("Unsupported file_id version {$version}");
        }

        $type = $raw[1];
        if ($type !== self::TYPE_PHOTO && $type !== self::TYPE_DOCUMENT) {
            throw new MTProtoException('Unknown file_id media type');
        }

        $dc = ord($raw[2]);
        $id = self::toSigned(unpack('P', substr($raw, 3, 8))[1]);
        $hash = self::toSigned(unpack('P', substr($raw, 11, 8))[1]);
        $refLen = unpack('v', substr($raw, 19, 2))[1];
        $ref = substr($raw, 21, $refLen);

        return ['type' => $type, 'dc' => $dc, 'id' => $id, 'hash' => $hash, 'ref' => $ref];
    }

    /**
     * Locate the photo/document inside a media value and tag its type.
     *
     * @return array{0: string, 1: array}
     */
    private static function extract(array $media): array
    {
        $c = $media['_'] ?? '';

        // Direct objects.
        if ($c === 'photo') {
            return [self::TYPE_PHOTO, $media];
        }
        if ($c === 'document') {
            return [self::TYPE_DOCUMENT, $media];
        }

        // messageMedia* wrappers.
        if (isset($media['photo']) && is_array($media['photo'])) {
            return [self::TYPE_PHOTO, $media['photo']];
        }
        if (isset($media['document']) && is_array($media['document'])) {
            return [self::TYPE_DOCUMENT, $media['document']];
        }

        throw new MTProtoException('No photo/document found in media to build a file_id');
    }

    /**
     * Normalise a PHP int that may have been read as an unsigned 64-bit value on
     * a 64-bit platform (pack('P') round-trips bit patterns; PHP ints are signed).
     */
    private static function toSigned(int $v): int
    {
        return $v;
    }
}
