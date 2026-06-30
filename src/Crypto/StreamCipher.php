<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Crypto;

use LaraGram\MTProto\Contracts\CryptoInterface;

final class StreamCipher
{
    private const BLOCK = 16;
    private const ZERO_BLOCK = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    private CryptoInterface $crypto;
    private string $key;

    /** 16-byte big-endian counter block (the CTR "IV"). */
    private string $counter;

    /** Unused keystream bytes carried over from the previous chunk. */
    private string $keystream = '';

    public function __construct(CryptoInterface $crypto, string $key, string $iv)
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('StreamCipher key must be 32 bytes.');
        }
        if (strlen($iv) !== self::BLOCK) {
            throw new \InvalidArgumentException('StreamCipher IV must be 16 bytes.');
        }

        $this->crypto  = $crypto;
        $this->key     = $key;
        $this->counter = $iv;
    }

    /**
     * XOR the data with the next keystream bytes (encrypt == decrypt for CTR).
     */
    public function process(string $data): string
    {
        $len = strlen($data);
        if ($len === 0) {
            return '';
        }

        // Top up the keystream buffer to cover the requested length.
        while (strlen($this->keystream) < $len) {
            $this->keystream .= $this->nextBlock();
        }

        $out = $data ^ substr($this->keystream, 0, $len);
        $this->keystream = substr($this->keystream, $len);

        return $out;
    }

    /**
     * Produce one 16-byte keystream block for the current counter and advance.
     *
     * Encrypting a zero block under CTR yields exactly the keystream block for
     * that counter value; feeding only 16 bytes keeps the backend on block 0.
     */
    private function nextBlock(): string
    {
        $block = $this->crypto->aesCtr(self::ZERO_BLOCK, $this->key, $this->counter);
        $this->counter = self::increment($this->counter);

        return $block;
    }

    /**
     * Increment a 16-byte big-endian counter by one (matches openssl CTR).
     */
    private static function increment(string $counter): string
    {
        for ($i = self::BLOCK - 1; $i >= 0; $i--) {
            $byte = (ord($counter[$i]) + 1) & 0xff;
            $counter[$i] = chr($byte);
            if ($byte !== 0) {
                break; // no carry
            }
        }

        return $counter;
    }
}
