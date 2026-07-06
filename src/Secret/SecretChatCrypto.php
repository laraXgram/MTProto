<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Secret;

use LaraGram\MTProto\Crypto\NativeCrypto;

final class SecretChatCrypto
{
    public function __construct(private NativeCrypto $crypto = new NativeCrypto())
    {
    }

    /**
     * Compute the 256-byte shared key `g_other^a mod p`, left-padded to 256 bytes.
     *
     * @param string $gOther Other party's g_a (or g_b) as big-endian bytes.
     * @param string $secret Our own secret exponent bytes.
     * @param string $prime  The DH prime `p` bytes.
     */
    public function computeSharedKey(string $gOther, string $secret, string $prime): string
    {
        $key = gmp_powm(
            gmp_import($gOther),
            gmp_import($secret),
            gmp_import($prime),
        );

        return $this->pad256(gmp_export($key));
    }

    /**
     * Secret-chat key fingerprint: the low 64 bits of SHA1(key), as 8 raw bytes.
     */
    public function keyFingerprint(string $key): string
    {
        return substr(sha1($key, true), 12, 8);
    }

    /**
     * Encrypt a serialized body (a `decryptedMessageLayer` TL blob) for the wire.
     *
     * @param int $x Direction constant (0 or 8) — see class docblock.
     */
    public function encrypt(string $key, string $body, int $x): string
    {
        $plaintext = $this->frame($body);

        $msgKey = $this->messageKey($key, $plaintext, $x);
        [$aesKey, $aesIv] = $this->deriveKeyIv($key, $msgKey, $x);

        $ciphertext = $this->crypto->aesIgeEncrypt($plaintext, $aesKey, $aesIv);

        return $this->keyFingerprint($key) . $msgKey . $ciphertext;
    }

    /**
     * Decrypt a wire blob back to the serialized body, verifying msg_key.
     *
     * @param int $x Direction constant of the message's author (0 or 8).
     * @throws \RuntimeException on fingerprint / msg_key mismatch (tamper or wrong key).
     */
    public function decrypt(string $key, string $wire, int $x): string
    {
        if (strlen($wire) < 24) {
            throw new \RuntimeException('Secret message too short.');
        }

        $fingerprint = substr($wire, 0, 8);
        if (!hash_equals($this->keyFingerprint($key), $fingerprint)) {
            throw new \RuntimeException('Secret message key fingerprint mismatch.');
        }

        $msgKey = substr($wire, 8, 16);
        $ciphertext = substr($wire, 24);

        [$aesKey, $aesIv] = $this->deriveKeyIv($key, $msgKey, $x);
        $plaintext = $this->crypto->aesIgeDecrypt($ciphertext, $aesKey, $aesIv);

        // Integrity: msg_key must equal the middle 128 bits of SHA256(prefix+plaintext).
        $expected = $this->messageKey($key, $plaintext, $x);
        if (!hash_equals($expected, $msgKey)) {
            throw new \RuntimeException('Secret message integrity check failed (msg_key mismatch).');
        }

        return $this->unframe($plaintext);
    }

    /**
     * Build the padded plaintext: int32 length ‖ body ‖ random padding, total a
     * multiple of 16 with 12–1024 bytes of padding (MTProto 2.0).
     */
    private function frame(string $body): string
    {
        $data = pack('V', strlen($body)) . $body;

        $padLen = 16 - (strlen($data) % 16);
        if ($padLen < 12) {
            $padLen += 16;
        }

        return $data . $this->crypto->randomBytes($padLen);
    }

    private function unframe(string $plaintext): string
    {
        $len = unpack('V', substr($plaintext, 0, 4))[1];
        if ($len < 0 || $len > strlen($plaintext) - 4) {
            throw new \RuntimeException('Secret message has an invalid length prefix.');
        }

        return substr($plaintext, 4, $len);
    }

    private function messageKey(string $key, string $plaintext, int $x): string
    {
        $prefix = substr($key, 88 + $x, 32);

        return substr(hash('sha256', $prefix . $plaintext, true), 8, 16);
    }

    /**
     * @return array{0:string,1:string} [aes_key(32), aes_iv(32)]
     */
    private function deriveKeyIv(string $key, string $msgKey, int $x): array
    {
        $a = hash('sha256', $msgKey . substr($key, $x, 36), true);
        $b = hash('sha256', substr($key, 40 + $x, 36) . $msgKey, true);

        $aesKey = substr($a, 0, 8) . substr($b, 8, 16) . substr($a, 24, 8);
        $aesIv = substr($b, 0, 8) . substr($a, 8, 16) . substr($b, 24, 8);

        return [$aesKey, $aesIv];
    }

    private function pad256(string $bytes): string
    {
        if (strlen($bytes) > 256) {
            // Should never happen for a valid mod-p result; keep the low 256 bytes.
            return substr($bytes, -256);
        }

        return str_pad($bytes, 256, "\x00", STR_PAD_LEFT);
    }
}
