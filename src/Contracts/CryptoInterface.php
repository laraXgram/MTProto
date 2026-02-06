<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

/**
 * Cryptography interface for MTProto encryption/decryption.
 * 
 * Handles all cryptographic operations required by MTProto:
 * - AES-IGE encryption/decryption
 * - SHA-256 hashing
 * - RSA encryption (for auth key generation)
 * - Key derivation
 */
interface CryptoInterface
{
    /**
     * AES-256-IGE encrypt.
     *
     * @param string $data Data to encrypt (must be padded to 16-byte boundary)
     * @param string $key 32-byte AES key
     * @param string $iv 32-byte initialization vector
     * @return string Encrypted data
     */
    public function aesIgeEncrypt(string $data, string $key, string $iv): string;

    /**
     * AES-256-IGE decrypt.
     *
     * @param string $data Data to decrypt
     * @param string $key 32-byte AES key
     * @param string $iv 32-byte initialization vector
     * @return string Decrypted data
     */
    public function aesIgeDecrypt(string $data, string $key, string $iv): string;

    /**
     * AES-256-CTR encrypt/decrypt (symmetric).
     *
     * @param string $data Data to process
     * @param string $key 32-byte AES key
     * @param string $iv 16-byte initialization vector
     * @return string Processed data
     */
    public function aesCtr(string $data, string $key, string $iv): string;

    /**
     * Calculate SHA-1 hash.
     *
     * @param string $data Data to hash
     * @return string 20-byte hash
     */
    public function sha1(string $data): string;

    /**
     * Calculate SHA-256 hash.
     *
     * @param string $data Data to hash
     * @return string 32-byte hash
     */
    public function sha256(string $data): string;

    /**
     * RSA encrypt using public key.
     *
     * @param string $data Data to encrypt
     * @param string $publicKey RSA public key (PEM or modulus+exponent)
     * @return string Encrypted data
     */
    public function rsaEncrypt(string $data, string $publicKey): string;

    /**
     * Generate random bytes.
     *
     * @param int $length Number of bytes
     * @return string Random bytes
     */
    public function randomBytes(int $length): string;

    /**
     * Derive AES key and IV from auth_key and msg_key (MTProto 2.0).
     *
     * @param string $authKey 2048-bit authorization key
     * @param string $msgKey 128-bit message key
     * @param bool $outgoing True for client->server, false for server->client
     * @return array{aes_key: string, aes_iv: string} 256-bit key and 256-bit IV
     */
    public function kdf(string $authKey, string $msgKey, bool $outgoing): array;

    /**
     * Calculate auth_key_id (lower 64 bits of SHA1(auth_key)).
     *
     * @param string $authKey 2048-bit authorization key
     * @return string 8-byte auth_key_id
     */
    public function calculateAuthKeyId(string $authKey): string;

    /**
     * Calculate msg_key for MTProto 2.0.
     *
     * @param string $authKey 2048-bit authorization key
     * @param string $plaintext Message plaintext (including padding)
     * @param bool $outgoing True for client->server, false for server->client
     * @return string 16-byte msg_key
     */
    public function calculateMsgKey(string $authKey, string $plaintext, bool $outgoing): string;
}
