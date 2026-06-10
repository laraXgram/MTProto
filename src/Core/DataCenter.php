<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

/**
 * Telegram Data Center configuration.
 * 
 * Contains IP addresses and ports for all Telegram DCs.
 */
class DataCenter
{
    /**
     * Production DC addresses (IPv4).
     */
    public const DC_ADDRESSES = [
        1 => '149.154.175.53',
        2 => '149.154.167.51',
        3 => '149.154.175.100',
        4 => '149.154.167.91',
        5 => '91.108.56.130',
    ];

    /**
     * Production DC addresses (IPv6).
     */
    public const DC_ADDRESSES_IPV6 = [
        1 => '2001:b28:f23d:f001::a',
        2 => '2001:67c:4e8:f002::a',
        3 => '2001:b28:f23d:f003::a',
        4 => '2001:67c:4e8:f004::a',
        5 => '2001:b28:f23f:f005::a',
    ];

    /**
     * Test DC addresses (IPv4).
     */
    public const DC_ADDRESSES_TEST = [
        1 => '149.154.175.10',
        2 => '149.154.167.40',
        3 => '149.154.175.117',
    ];

    /**
     * Test DC addresses (IPv6).
     */
    public const DC_ADDRESSES_TEST_IPV6 = [
        1 => '2001:b28:f23d:f001::e',
        2 => '2001:67c:4e8:f002::e',
        3 => '2001:b28:f23d:f003::e',
    ];

    /**
     * Default port for MTProto connections.
     */
    public const DEFAULT_PORT = 443;

    /**
     * Alternative ports.
     */
    public const ALTERNATIVE_PORTS = [80, 88, 8080];

    /**
     * Media DC suffix.
     */
    public const MEDIA_DC_SUFFIX = '-media';

    /**
     * CDN DC range.
     */
    public const CDN_DC_START = 200;

    /**
     * Telegram RSA public keys in PEM format for authentication.
     * These are used during authorization key generation.
     * Source: MadelineProto (https://github.com/danog/MadelineProto)
     */
    public const RSA_KEYS_PEM = [
        // Production key 1
        "-----BEGIN RSA PUBLIC KEY-----\n" .
        "MIIBCgKCAQEA6LszBcC1LGzyr992NzE0ieY+BSaOW622Aa9Bd4ZHLl+TuFQ4lo4g\n" .
        "5nKaMBwK/BIb9xUfg0Q29/2mgIR6Zr9krM7HjuIcCzFvDtr+L0GQjae9H0pRB2OO\n" .
        "62cECs5HKhT5DZ98K33vmWiLowc621dQuwKWSQKjWf50XYFw42h21P2KXUGyp2y/\n" .
        "+aEyZ+uVgLLQbRA1dEjSDZ2iGRy12Mk5gpYc397aYp438fsJoHIgJ2lgMv5h7WY9\n" .
        "t6N/byY9Nw9p21Og3AoXSL2q/2IJ1WRUhebgAdGVMlV1fkuOQoEzR7EdpqtQD9Cs\n" .
        "5+bfo3Nhmcyvk5ftB0WkJ9z6bNZ7yxrP8wIDAQAB\n" .
        "-----END RSA PUBLIC KEY-----",
    ];

    /**
     * Test RSA public keys in PEM format.
     */
    public const TEST_RSA_KEYS_PEM = [
        "-----BEGIN RSA PUBLIC KEY-----\n" .
        "MIIBCgKCAQEAyMEdY1aR+sCR3ZSJrtztKTKqigvO/vBfqACJLZtS7QMgCGXJ6XIR\n" .
        "yy7mx66W0/sOFa7/1mAZtEoIokDP3ShoqF4fVNb6XeqgQfaUHd8wJpDWHcR2OFwv\n" .
        "plUUI1PLTktZ9uW2WE23b+ixNwJjJGwBDJPQEQFBE+vfmH0JP503wr5INS1poWg/\n" .
        "j25sIWeYPHYeOrFp/eXaqhISP6G+q2IeTaWTXpwZj4LzXq5YOpk4bYEQ6mvRq7D1\n" .
        "aHWfYmlEGepfaYR8Q0YqvvhYtMte3ITnuSJs171+GDqpdKcSwHnd6FudwGO4pcCO\n" .
        "j4WcDuXc2CTHgH8gFTNhp/Y8/SpDOhvn9QIDAQAB\n" .
        "-----END RSA PUBLIC KEY-----",
    ];

    /**
     * Parsed RSA keys cache.
     * @var array<string, array{n: \GMP, e: \GMP, fingerprint: string}>|null
     */
    private static ?array $parsedKeys = null;

    /**
     * Parsed test RSA keys cache.
     * @var array<string, array{n: \GMP, e: \GMP, fingerprint: string}>|null
     */
    private static ?array $parsedTestKeys = null;

    /**
     * Get DC address.
     *
     * @param int $dcId Datacenter ID (1-5)
     * @param bool $test Use test servers
     * @param bool $ipv6 Use IPv6
     * @return string IP address
     */
    public static function getAddress(int $dcId, bool $test = false, bool $ipv6 = false): string
    {
        if ($test) {
            $addresses = $ipv6 ? self::DC_ADDRESSES_TEST_IPV6 : self::DC_ADDRESSES_TEST;
        } else {
            $addresses = $ipv6 ? self::DC_ADDRESSES_IPV6 : self::DC_ADDRESSES;
        }

        return $addresses[$dcId] ?? $addresses[2]; // Default to DC2
    }

    /**
     * Get all DC addresses for a given configuration.
     *
     * @param bool $test Use test servers
     * @param bool $ipv6 Use IPv6
     * @return array<int, string>
     */
    public static function getAllAddresses(bool $test = false, bool $ipv6 = false): array
    {
        if ($test) {
            return $ipv6 ? self::DC_ADDRESSES_TEST_IPV6 : self::DC_ADDRESSES_TEST;
        }
        return $ipv6 ? self::DC_ADDRESSES_IPV6 : self::DC_ADDRESSES;
    }

    /**
     * Check if DC ID is valid.
     */
    public static function isValidDcId(int $dcId): bool
    {
        return $dcId >= 1 && $dcId <= 5;
    }

    /**
     * Check if DC ID is for media.
     */
    public static function isMediaDc(int $dcId): bool
    {
        return $dcId > 10000; // Media DCs are offset
    }

    /**
     * Check if DC ID is for CDN.
     */
    public static function isCdnDc(int $dcId): bool
    {
        return $dcId >= self::CDN_DC_START;
    }

    /**
     * Get the base DC ID (without media offset).
     */
    public static function getBaseDcId(int $dcId): int
    {
        if ($dcId > 10000) {
            return $dcId - 10000;
        }
        return $dcId;
    }

    /**
     * Parse a PEM-encoded RSA public key and extract n, e, and calculate fingerprint.
     *
     * @param string $pem PEM-encoded RSA public key
     * @return array{n: \GMP, e: \GMP, fingerprint: string}
     */
    public static function parseRsaKey(string $pem): array
    {
        // Remove PEM headers and decode base64
        $pem = str_replace(['-----BEGIN RSA PUBLIC KEY-----', '-----END RSA PUBLIC KEY-----', "\n", "\r"], '', $pem);
        $der = base64_decode($pem);
        
        // Parse ASN.1 DER structure
        // RSAPublicKey ::= SEQUENCE {
        //     modulus           INTEGER,  -- n
        //     publicExponent    INTEGER   -- e
        // }
        
        $offset = 0;
        
        // Read SEQUENCE tag and length
        [$offset, $seqLen] = self::readAsn1Length($der, $offset + 1);
        
        // Read modulus (n)
        if (ord($der[$offset]) !== 0x02) {
            throw new \RuntimeException('Expected INTEGER tag for modulus');
        }
        [$offset, $nLen] = self::readAsn1Length($der, $offset + 1);
        $nBytes = substr($der, $offset, $nLen);
        // Remove leading zero if present (used for positive sign)
        if ($nBytes[0] === "\x00") {
            $nBytes = substr($nBytes, 1);
        }
        $n = gmp_import($nBytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $offset += $nLen;
        
        // Read exponent (e)
        if (ord($der[$offset]) !== 0x02) {
            throw new \RuntimeException('Expected INTEGER tag for exponent');
        }
        [$offset, $eLen] = self::readAsn1Length($der, $offset + 1);
        $eBytes = substr($der, $offset, $eLen);
        if ($eBytes[0] === "\x00") {
            $eBytes = substr($eBytes, 1);
        }
        $e = gmp_import($eBytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        
        // Calculate fingerprint
        // fingerprint = lower 64 bits of SHA1(TL-serialized(n) || TL-serialized(e))
        $nBytesFull = gmp_export($n, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $eBytesFull = gmp_export($e, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        
        $tlData = self::serializeTLBytes($nBytesFull) . self::serializeTLBytes($eBytesFull);
        $sha1 = sha1($tlData, true);
        $fingerprint = substr($sha1, -8);
        
        // Convert fingerprint to hex string (little-endian unsigned int64)
        $fpHex = bin2hex(strrev($fingerprint));
        
        return [
            'n' => $n,
            'e' => $e,
            'fingerprint' => $fpHex,
        ];
    }

    /**
     * Read ASN.1 length encoding.
     *
     * @param string $data DER data
     * @param int $offset Current offset
     * @return array{int, int} New offset and length
     */
    private static function readAsn1Length(string $data, int $offset): array
    {
        $len = ord($data[$offset]);
        $offset++;
        
        if ($len < 0x80) {
            return [$offset, $len];
        }
        
        $numBytes = $len & 0x7F;
        $len = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $len = ($len << 8) | ord($data[$offset]);
            $offset++;
        }
        
        return [$offset, $len];
    }

    /**
     * Serialize bytes in TL format.
     *
     * @param string $bytes Bytes to serialize
     * @return string TL-serialized bytes
     */
    private static function serializeTLBytes(string $bytes): string
    {
        $len = strlen($bytes);
        
        if ($len < 254) {
            $result = chr($len) . $bytes;
            $padding = (4 - ((1 + $len) % 4)) % 4;
        } else {
            $result = "\xfe" . substr(pack('V', $len), 0, 3) . $bytes;
            $padding = (4 - ((4 + $len) % 4)) % 4;
        }
        
        return $result . str_repeat("\x00", $padding);
    }

    /**
     * Get all parsed RSA keys.
     *
     * @param bool $test Use test keys
     * @return array<string, array{n: \GMP, e: \GMP, fingerprint: string}>
     */
    public static function getRsaKeys(bool $test = false): array
    {
        if ($test) {
            if (self::$parsedTestKeys === null) {
                self::$parsedTestKeys = [];
                foreach (self::TEST_RSA_KEYS_PEM as $pem) {
                    $key = self::parseRsaKey($pem);
                    self::$parsedTestKeys[$key['fingerprint']] = $key;
                }
            }
            return self::$parsedTestKeys;
        }
        
        if (self::$parsedKeys === null) {
            self::$parsedKeys = [];
            foreach (self::RSA_KEYS_PEM as $pem) {
                $key = self::parseRsaKey($pem);
                self::$parsedKeys[$key['fingerprint']] = $key;
            }
        }
        
        return self::$parsedKeys;
    }

    /**
     * Find RSA key by fingerprint.
     *
     * @param string $fingerprint Fingerprint as hex string
     * @param bool $test Use test keys
     * @return array{n: \GMP, e: \GMP, fingerprint: string}|null
     */
    public static function findRsaKey(string $fingerprint, bool $test = false): ?array
    {
        $keys = self::getRsaKeys($test);
        
        // Normalize fingerprint
        $fp = strtolower(ltrim($fingerprint, '0'));
        
        foreach ($keys as $keyFp => $key) {
            $kfp = strtolower(ltrim($keyFp, '0'));
            if ($fp === $kfp) {
                return $key;
            }
        }
        
        return null;
    }

    /**
     * Find RSA key by any of the given fingerprints.
     *
     * @param array<string> $fingerprints Array of fingerprints as hex strings
     * @param bool $test Use test keys
     * @return array{n: \GMP, e: \GMP, fingerprint: string}|null
     */
    public static function findRsaKeyByFingerprints(array $fingerprints, bool $test = false): ?array
    {
        foreach ($fingerprints as $fp) {
            $key = self::findRsaKey($fp, $test);
            if ($key !== null) {
                return $key;
            }
        }
        return null;
    }
}
