<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Crypto;

use LaraGram\MTProto\Contracts\CryptoInterface;

final class StreamCipher
{
    private const BLOCK = 16;

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
     *
     * The keystream is produced in bulk by one AES-CTR call per chunk (the
     * data itself, zero-padded to a block boundary, is encrypted from the
     * current counter); the padding's output is the carried-over keystream.
     */
    public function process(string $data): string
    {
        $len = strlen($data);
        if ($len === 0) {
            return '';
        }

        // Consume keystream left over from the previous call first.
        $have = strlen($this->keystream);
        if ($have > 0) {
            $take = min($have, $len);
            $head = substr($data, 0, $take) ^ substr($this->keystream, 0, $take);
            $this->keystream = (string) substr($this->keystream, $take);

            return $take === $len ? $head : $head . $this->process(substr($data, $take));
        }

        $blocks = intdiv($len + self::BLOCK - 1, self::BLOCK);
        $pad = $blocks * self::BLOCK - $len;

        $out = $this->crypto->aesCtr($pad === 0 ? $data : $data . str_repeat("\0", $pad), $this->key, $this->counter);
        $this->counter = self::add($this->counter, $blocks);

        if ($pad === 0) {
            return $out;
        }

        $this->keystream = substr($out, $len);

        return substr($out, 0, $len);
    }

    /**
     * Add $blocks to a 16-byte big-endian counter (wraps like openssl CTR).
     */
    private static function add(string $counter, int $blocks): string
    {
        $words = array_values(unpack('N4', $counter));

        for ($i = 3; $i >= 0 && $blocks > 0; $i--) {
            $sum = $words[$i] + $blocks;
            $words[$i] = $sum & 0xFFFFFFFF;
            $blocks = $sum >> 32;
        }

        return pack('N4', ...$words);
    }
}
