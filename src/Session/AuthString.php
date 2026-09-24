<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session;

use LaraGram\Encryption\Encrypter;
use LaraGram\MTProto\Core\DataCenter;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * Portable, single-line session strings.
 *
 * Formats:
 *  - `laragram`  `lg2:` + base64url(dc, flags, api_id, user_id, auth_key, crc32)
 *                encrypted: `lg2e:` + an {@see Encrypter} payload of the above
 *                legacy v1: base64 of a JSON object (still decoded)
 *  - `telethon`  `1` + base64url(dc, ipv4|ipv6, port, auth_key) - also GramJS
 *  - `pyrogram`  base64url(dc, api_id, test_mode, auth_key, user_id, is_bot)
 *                (older Pyrogram layouts without api_id are decoded too)
 *
 * Every format carries the permanent auth key: whoever has the string has the
 * account. Prefer the encrypted form anywhere it may be seen.
 */
final class AuthString
{
    public const FORMATS = ['laragram', 'telethon', 'pyrogram'];

    private const V2_PREFIX = 'lg2:';
    private const V2_ENCRYPTED_PREFIX = 'lg2e:';

    /**
     * @param int $dcId DC the auth key belongs to
     * @param string $authKey 256-byte permanent auth key
     */
    public function __construct(
        public readonly int $dcId,
        public readonly string $authKey,
        public readonly ?int $userId = null,
        public readonly bool $isBot = false,
        public readonly bool $testMode = false,
        public readonly ?int $apiId = null,
        public readonly ?string $serverSalt = null,
        public readonly int $timeDelta = 0,
        public readonly string $format = 'laragram',
    ) {
        if (strlen($authKey) !== 256) {
            throw new MTProtoException('An auth key must be exactly 256 bytes, got ' . strlen($authKey) . '.');
        }
        if (!DataCenter::isValidDcId($dcId)) {
            throw new MTProtoException("Invalid DC id in auth string: {$dcId}");
        }
    }

    /**
     * Encode in one of {@see FORMATS}. `$encrypter` encrypts the LaraGram format.
     */
    public function encode(string $format = 'laragram', ?Encrypter $encrypter = null): string
    {
        return match ($format) {
            'laragram' => $encrypter !== null
                ? self::V2_ENCRYPTED_PREFIX . $encrypter->encryptString($this->laragramPayload())
                : self::V2_PREFIX . self::base64UrlEncode($this->laragramPayload()),
            // Telethon decodes without re-padding, so its '=' padding stays.
            'telethon' => '1' . strtr(base64_encode(
                chr($this->dcId)
                . inet_pton(DataCenter::getAddress($this->dcId, $this->testMode))
                . pack('n', DataCenter::DEFAULT_PORT)
                . $this->authKey
            ), '+/', '-_'),
            'pyrogram' => self::base64UrlEncode(
                pack('C', $this->dcId)
                . pack('N', $this->apiId ?? 0)
                . pack('C', $this->testMode ? 1 : 0)
                . $this->authKey
                . pack('J', $this->userId ?? 0)
                . pack('C', $this->isBot ? 1 : 0)
            ),
            default => throw new MTProtoException("Unknown auth string format '{$format}' (use: " . implode(', ', self::FORMATS) . ').'),
        };
    }

    /**
     * Decode any supported format (detected automatically). Encrypted strings
     * need the `$encrypter` that produced them.
     */
    public static function decode(string $string, ?Encrypter $encrypter = null): self
    {
        $string = trim($string);

        if (str_starts_with($string, self::V2_ENCRYPTED_PREFIX)) {
            if ($encrypter === null) {
                throw new MTProtoException('This auth string is encrypted - set CLIENT_SESSION_KEY (or APP_KEY) to the key it was exported with.');
            }
            try {
                $payload = $encrypter->decryptString(substr($string, strlen(self::V2_ENCRYPTED_PREFIX)));
            } catch (\Throwable) {
                throw new MTProtoException('Cannot decrypt the auth string - wrong CLIENT_SESSION_KEY/APP_KEY.');
            }

            return self::fromLaragramPayload($payload);
        }

        if (str_starts_with($string, self::V2_PREFIX)) {
            return self::fromLaragramPayload(self::base64UrlDecode(substr($string, strlen(self::V2_PREFIX))));
        }

        if (str_starts_with($string, '1') && ($telethon = self::tryTelethon(substr($string, 1))) !== null) {
            return $telethon;
        }

        if (($legacy = self::tryLegacy($string)) !== null) {
            return $legacy;
        }

        if (($pyrogram = self::tryPyrogram($string)) !== null) {
            return $pyrogram;
        }

        throw new MTProtoException('Unrecognised auth string (expected a LaraGram, Telethon/GramJS or Pyrogram session string).');
    }

    /**
     * What the string holds, without the key material (safe to print).
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'format' => $this->format,
            'dc_id' => $this->dcId,
            'user_id' => $this->userId,
            'bot' => $this->isBot,
            'test_mode' => $this->testMode,
            'api_id' => $this->apiId,
            'auth_key_id' => bin2hex(substr(sha1($this->authKey, true), -8)),
        ];
    }

    private function laragramPayload(): string
    {
        $body = pack('C', 2)
            . pack('C', $this->dcId)
            . pack('C', ($this->testMode ? 1 : 0) | ($this->isBot ? 2 : 0))
            . pack('N', $this->apiId ?? 0)
            . pack('J', $this->userId ?? 0)
            . $this->authKey;

        return $body . pack('N', crc32($body));
    }

    private static function fromLaragramPayload(string $payload): self
    {
        if (strlen($payload) !== 1 + 1 + 1 + 4 + 8 + 256 + 4) {
            throw new MTProtoException('Malformed LaraGram auth string (wrong length).');
        }

        $body = substr($payload, 0, -4);
        if (unpack('N', substr($payload, -4))[1] !== crc32($body)) {
            throw new MTProtoException('Corrupted LaraGram auth string (checksum mismatch) - was it copied completely?');
        }

        $flags = ord($body[2]);
        $apiId = unpack('N', substr($body, 3, 4))[1];
        $userId = unpack('J', substr($body, 7, 8))[1];

        return new self(
            dcId: ord($body[1]),
            authKey: substr($body, 15, 256),
            userId: $userId !== 0 ? $userId : null,
            isBot: ($flags & 2) === 2,
            testMode: ($flags & 1) === 1,
            apiId: $apiId !== 0 ? $apiId : null,
            format: 'laragram',
        );
    }

    /**
     * Legacy v1: base64(JSON{dc_id, auth_key, server_salt, time_delta}).
     */
    private static function tryLegacy(string $string): ?self
    {
        $json = base64_decode($string, true);
        $data = $json !== false ? json_decode($json, true) : null;

        if (!is_array($data) || !isset($data['dc_id'], $data['auth_key'])) {
            return null;
        }

        $authKey = base64_decode((string) $data['auth_key'], true);
        if ($authKey === false || strlen($authKey) !== 256) {
            throw new MTProtoException('auth_string has a malformed auth_key.');
        }

        $salt = !empty($data['server_salt']) ? base64_decode((string) $data['server_salt'], true) : null;

        return new self(
            dcId: (int) $data['dc_id'],
            authKey: $authKey,
            serverSalt: $salt !== false ? $salt : null,
            timeDelta: (int) ($data['time_delta'] ?? 0),
            format: 'laragram-v1',
        );
    }

    /**
     * Telethon / GramJS: dc(1) + ip(4|16) + port(2) + key(256).
     */
    private static function tryTelethon(string $encoded): ?self
    {
        $raw = self::base64UrlDecode($encoded);
        $length = strlen($raw);

        if ($length !== 263 && $length !== 275) {
            return null;
        }

        return new self(
            dcId: ord($raw[0]),
            authKey: substr($raw, $length - 256),
            format: 'telethon',
        );
    }

    /**
     * Pyrogram layouts (big-endian):
     *   271: dc(B) api_id(I) test(?) key(256s) user_id(Q) bot(?)
     *   267: dc(B) test(?) key(256s) user_id(Q) bot(?)
     *   263: dc(B) test(?) key(256s) user_id(I) bot(?)
     */
    private static function tryPyrogram(string $encoded): ?self
    {
        $raw = self::base64UrlDecode($encoded);

        return match (strlen($raw)) {
            271 => new self(
                dcId: ord($raw[0]),
                authKey: substr($raw, 6, 256),
                userId: unpack('J', substr($raw, 262, 8))[1] ?: null,
                isBot: ord($raw[270]) === 1,
                testMode: ord($raw[5]) === 1,
                apiId: unpack('N', substr($raw, 1, 4))[1] ?: null,
                format: 'pyrogram',
            ),
            267 => new self(
                dcId: ord($raw[0]),
                authKey: substr($raw, 2, 256),
                userId: unpack('J', substr($raw, 258, 8))[1] ?: null,
                isBot: ord($raw[266]) === 1,
                testMode: ord($raw[1]) === 1,
                format: 'pyrogram',
            ),
            263 => new self(
                dcId: ord($raw[0]),
                authKey: substr($raw, 2, 256),
                userId: unpack('N', substr($raw, 258, 4))[1] ?: null,
                isBot: ord($raw[262]) === 1,
                testMode: ord($raw[1]) === 1,
                format: 'pyrogram',
            ),
            default => null,
        };
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Keep key material out of var_dump()/print_r()/dump() output.
     */
    public function __debugInfo(): array
    {
        return $this->describe();
    }
}
