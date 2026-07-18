<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Crypto;

use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Exceptions\CryptoException;

/**
 * Native PHP cryptography implementation using OpenSSL.
 *
 * Uses PHP's built-in openssl extension for:
 * - AES-256-IGE encryption/decryption (IGE implemented manually using CBC primitives)
 * - AES-256-CTR encryption/decryption
 * - SHA-1 and SHA-256 hashing
 * - RSA encryption
 *
 * This is the default crypto implementation - secure and fast with native extensions.
 */
class NativeCrypto implements CryptoInterface
{
    /**
     * AES block size in bytes.
     */
    private const AES_BLOCK_SIZE = 16;

    /**
     * AES key size in bytes (256-bit).
     */
    private const AES_KEY_SIZE = 32;

    /**
     * AES-IGE IV size in bytes (2 blocks).
     */
    private const AES_IGE_IV_SIZE = 32;

    /**
     * AES-CTR IV size in bytes.
     */
    private const AES_CTR_IV_SIZE = 16;

    /**
     * {@inheritdoc}
     */
    public function aesIgeEncrypt(string $data, string $key, string $iv): string
    {
        $this->validateAesParams($data, $key, $iv, true);

        // With c_i = E(p_i ^ c_{i-1}) ^ p_{i-1} and d_i := c_i ^ p_{i-1},
        // the recurrence becomes d_i = E((p_i ^ p_{i-2}) ^ d_{i-1}) - exactly
        // CBC over the shift-2-XORed plaintext - and c is recovered by XORing
        // d with the shift-1 plaintext. (p_0 = iv2, c_0 = iv1.)
        $len = strlen($data);
        $iv1 = substr($iv, 0, self::AES_BLOCK_SIZE);
        $iv2 = substr($iv, self::AES_BLOCK_SIZE, self::AES_BLOCK_SIZE);

        $shift2 = str_repeat("\0", self::AES_BLOCK_SIZE) . $iv2 . substr($data, 0, max(0, $len - 2 * self::AES_BLOCK_SIZE));

        $cbc = openssl_encrypt(
            $data ^ $shift2,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv1,
        );

        if ($cbc === false) {
            throw CryptoException::encryptionFailed(openssl_error_string() ?: 'Unknown error');
        }

        return $cbc ^ ($iv2 . substr($data, 0, max(0, $len - self::AES_BLOCK_SIZE)));
    }

    /**
     * {@inheritdoc}
     */
    public function aesIgeDecrypt(string $data, string $key, string $iv): string
    {
        $this->validateAesParams($data, $key, $iv, true);

        // iv_part_1 = first 16 bytes, iv_part_2 = last 16 bytes
        $ivPart1 = substr($iv, 0, self::AES_BLOCK_SIZE);
        $ivPart2 = substr($iv, self::AES_BLOCK_SIZE, self::AES_BLOCK_SIZE);

        $result = '';
        $dataLen = strlen($data);

        for ($i = 0; $i < $dataLen; $i += self::AES_BLOCK_SIZE) {
            $cipher = substr($data, $i, self::AES_BLOCK_SIZE);

            // plain = AES_decrypt(cipher XOR iv_part_2) XOR iv_part_1
            $plain = openssl_decrypt(
                $cipher ^ $ivPart2,
                'aes-256-ecb',
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
            );

            if ($plain === false) {
                throw CryptoException::decryptionFailed(openssl_error_string() ?: 'Unknown error');
            }

            $plain = $plain ^ $ivPart1;

            $result .= $plain;

            // Update IVs for next block
            $ivPart1 = $cipher;
            $ivPart2 = $plain;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function aesCtr(string $data, string $key, string $iv): string
    {
        if (strlen($key) !== self::AES_KEY_SIZE) {
            throw CryptoException::invalidKeySize(self::AES_KEY_SIZE, strlen($key));
        }

        if (strlen($iv) !== self::AES_CTR_IV_SIZE) {
            throw CryptoException::invalidIvSize(self::AES_CTR_IV_SIZE, strlen($iv));
        }

        $result = openssl_encrypt(
            $data,
            'aes-256-ctr',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($result === false) {
            throw CryptoException::encryptionFailed(openssl_error_string() ?: 'Unknown error');
        }

        return $result;
    }

    /**
     * Alias for aesCtr for encryption (symmetric).
     */
    public function aesCtrEncrypt(string $data, string $key, string $iv): string
    {
        return $this->aesCtr($data, $key, $iv);
    }

    /**
     * Alias for aesCtr for decryption (symmetric).
     */
    public function aesCtrDecrypt(string $data, string $key, string $iv): string
    {
        return $this->aesCtr($data, $key, $iv);
    }

    /**
     * {@inheritdoc}
     */
    public function sha1(string $data): string
    {
        return hash('sha1', $data, true);
    }

    /**
     * {@inheritdoc}
     */
    public function sha256(string $data): string
    {
        return hash('sha256', $data, true);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $data Data to encrypt
     * @param string $publicKey PEM format or hex modulus
     * @param string|null $exponent Optional hex exponent (only used with hex modulus)
     */
    public function rsaEncrypt(string $data, string $publicKey, ?string $exponent = null): string
    {
        // Handle PEM format
        if (str_starts_with($publicKey, '-----BEGIN')) {
            $key = openssl_pkey_get_public($publicKey);
            if ($key === false) {
                throw CryptoException::encryptionFailed('Invalid RSA public key');
            }

            $encrypted = '';
            if (!openssl_public_encrypt($data, $encrypted, $key, OPENSSL_NO_PADDING)) {
                throw CryptoException::encryptionFailed(openssl_error_string() ?: 'RSA encryption failed');
            }

            return $encrypted;
        }

        // Handle hex modulus/exponent (used in MTProto auth key generation)
        if ($exponent !== null) {
            // Convert hex to binary
            $modulus = hex2bin($publicKey);
            $exp = hexdec($exponent);

            if ($modulus === false) {
                throw CryptoException::encryptionFailed('Invalid hex modulus');
            }

            return $this->rsaEncryptWithModulus($data, $modulus, $exp);
        }

        throw CryptoException::encryptionFailed('Invalid RSA key format - use PEM or provide modulus and exponent');
    }

    /**
     * RSA encrypt with modulus and exponent (for auth key generation).
     *
     * @param string $data Data to encrypt (will be padded)
     * @param string $modulus RSA modulus (256 bytes, big-endian)
     * @param int $exponent RSA exponent (typically 65537)
     * @return string Encrypted data (256 bytes)
     */
    public function rsaEncryptWithModulus(string $data, string $modulus, int $exponent = 65537): string
    {
        // Pad data with SHA-1 hash and random bytes to 255 bytes
        // data_with_hash = SHA1(data) + data + random_padding
        // Then prepend 0x00 to make it 256 bytes

        $dataWithHash = $this->sha1($data) . $data;
        $paddingLength = 255 - strlen($dataWithHash);

        if ($paddingLength < 0) {
            throw CryptoException::encryptionFailed('Data too long for RSA encryption');
        }

        $dataWithHash .= $this->randomBytes($paddingLength);
        $paddedData = "\x00" . $dataWithHash;

        // Convert to GMP numbers
        $m = gmp_import($paddedData, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $n = gmp_import($modulus, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $e = gmp_init($exponent);

        // Modular exponentiation: c = m^e mod n
        $c = gmp_powm($m, $e, $n);

        // Convert back to binary (256 bytes)
        $result = gmp_export($c, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        // Pad to 256 bytes if needed
        return str_pad($result, 256, "\x00", STR_PAD_LEFT);
    }

    /**
     * {@inheritdoc}
     */
    public function randomBytes(int $length): string
    {
        try {
            return random_bytes($length);
        } catch (\Exception $e) {
            throw CryptoException::randomGenerationFailed();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function kdf(string $authKey, string $msgKey, bool $outgoing): array
    {
        // MTProto 2.0 key derivation
        // https://core.telegram.org/mtproto/description#defining-aes-key-and-initialization-vector

        $x = $outgoing ? 0 : 8;

        $sha256a = $this->sha256($msgKey . substr($authKey, $x, 36));
        $sha256b = $this->sha256(substr($authKey, 40 + $x, 36) . $msgKey);

        $aesKey = substr($sha256a, 0, 8) . substr($sha256b, 8, 16) . substr($sha256a, 24, 8);
        $aesIv = substr($sha256b, 0, 8) . substr($sha256a, 8, 16) . substr($sha256b, 24, 8);

        return [
            'aes_key' => $aesKey,
            'aes_iv' => $aesIv,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function calculateAuthKeyId(string $authKey): string
    {
        return substr($this->sha1($authKey), -8);
    }

    /**
     * {@inheritdoc}
     */
    public function calculateMsgKey(string $authKey, string $plaintext, bool $outgoing): string
    {
        $x = $outgoing ? 0 : 8;

        // msg_key = middle 128 bits of SHA256(substr(auth_key, 88+x, 32) + plaintext)
        $hash = $this->sha256(substr($authKey, 88 + $x, 32) . $plaintext);

        return substr($hash, 8, 16);
    }

    /**
     * Validate AES parameters.
     *
     * @param string $data Data to process
     * @param string $key AES key
     * @param string $iv Initialization vector
     * @param bool $isIge True for IGE mode (32-byte IV), false for CTR (16-byte IV)
     * @throws CryptoException
     */
    protected function validateAesParams(string $data, string $key, string $iv, bool $isIge): void
    {
        if (strlen($key) !== self::AES_KEY_SIZE) {
            throw CryptoException::invalidKeySize(self::AES_KEY_SIZE, strlen($key));
        }

        $expectedIvSize = $isIge ? self::AES_IGE_IV_SIZE : self::AES_CTR_IV_SIZE;
        if (strlen($iv) !== $expectedIvSize) {
            throw CryptoException::invalidIvSize($expectedIvSize, strlen($iv));
        }

        if ($isIge && strlen($data) % self::AES_BLOCK_SIZE !== 0) {
            throw CryptoException::dataNotAligned(self::AES_BLOCK_SIZE);
        }
    }
}
