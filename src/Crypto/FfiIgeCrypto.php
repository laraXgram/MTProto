<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Crypto;

class FfiIgeCrypto extends NativeCrypto
{
    private const CDEF = '
        typedef struct aes_key_st { unsigned int rd_key[60]; int rounds; } AES_KEY;
        int AES_set_decrypt_key(const unsigned char *userKey, const int bits, AES_KEY *key);
        void AES_ige_encrypt(const unsigned char *in, unsigned char *out, size_t length, const AES_KEY *key, unsigned char *ivec, const int enc);
    ';

    private const LIBS = ['libcrypto.so.3', 'libcrypto.so.1.1', 'libcrypto.so'];

    private static ?\FFI $ffi = null;
    private static ?bool $supported = null;

    /** Reusable per-instance C buffers (grown on demand, reset on clone). */
    private ?\FFI\CData $aesKey = null;
    private ?\FFI\CData $keyBuf = null;
    private ?\FFI\CData $ivecBuf = null;
    private ?\FFI\CData $inBuf = null;
    private ?\FFI\CData $outBuf = null;
    private int $bufSize = 0;

    /**
     * True when ext-ffi is loaded and a libcrypto exposing AES_ige_encrypt is
     * dlopen-able. Cached per process.
     */
    public static function isSupported(): bool
    {
        if (self::$supported !== null) {
            return self::$supported;
        }

        if (!\extension_loaded('ffi')) {
            return self::$supported = false;
        }

        return self::$supported = self::ffi() !== null;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }

        foreach (self::LIBS as $lib) {
            try {
                return self::$ffi = \FFI::cdef(self::CDEF, $lib);
            } catch (\Throwable) {
                // try next candidate
            }
        }

        return null;
    }

    public function aesIgeDecrypt(string $data, string $key, string $iv): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return parent::aesIgeDecrypt($data, $key, $iv);
        }

        $this->validateAesParams($data, $key, $iv, true);

        $len = strlen($data);
        if ($len === 0) {
            return '';
        }

        try {
            $this->ensureBuffers($ffi, $len);

            \FFI::memcpy($this->keyBuf, $key, 32);
            \FFI::memcpy($this->ivecBuf, $iv, 32);
            \FFI::memcpy($this->inBuf, $data, $len);

            $ffi->AES_set_decrypt_key($this->keyBuf, 256, \FFI::addr($this->aesKey));
            // enc=0 selects the decrypt direction of AES_ige_encrypt.
            $ffi->AES_ige_encrypt($this->inBuf, $this->outBuf, $len, \FFI::addr($this->aesKey), $this->ivecBuf, 0);

            return \FFI::string($this->outBuf, $len);
        } catch (\Throwable) {
            return parent::aesIgeDecrypt($data, $key, $iv);
        }
    }

    private function ensureBuffers(\FFI $ffi, int $len): void
    {
        if ($this->aesKey === null) {
            $this->aesKey = $ffi->new('AES_KEY');
            $this->keyBuf = $ffi->new('unsigned char[32]');
            $this->ivecBuf = $ffi->new('unsigned char[32]');
        }

        if ($len > $this->bufSize) {
            // Round up to the next MiB so repeated 512K/1M chunks reuse buffers.
            $size = (int) (ceil($len / 1048576) * 1048576);
            $this->inBuf = $ffi->new("unsigned char[$size]");
            $this->outBuf = $ffi->new("unsigned char[$size]");
            $this->bufSize = $size;
        }
    }

    public function __clone(): void
    {
        // Clones must not share C buffers with the original.
        $this->aesKey = null;
        $this->keyBuf = null;
        $this->ivecBuf = null;
        $this->inBuf = null;
        $this->outBuf = null;
        $this->bufSize = 0;
    }
}
