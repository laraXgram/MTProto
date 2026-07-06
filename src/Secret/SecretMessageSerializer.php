<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Secret;

final class SecretMessageSerializer
{
    private const ID_LAYER = 0x1be31789;
    private const ID_MESSAGE = 0x91cc4674;
    private const ID_SERVICE = 0x73164160;
    private const ID_ACTION_NOTIFY_LAYER = 0xf3048883;

    /**
     * Wrap a message body in a `decryptedMessageLayer` with sequence numbers.
     */
    public function serializeLayer(string $messageBody, int $layer, int $inSeq, int $outSeq, string $randomPad): string
    {
        return $this->int(self::ID_LAYER)
            . $this->bytes($randomPad)
            . $this->int($layer)
            . $this->int($inSeq)
            . $this->int($outSeq)
            . $messageBody;
    }

    /**
     * Serialize a plain text `decryptedMessage` (flags = 0, no media/entities).
     */
    public function serializeText(int $randomId, string $text, int $ttl = 0): string
    {
        return $this->int(self::ID_MESSAGE)
            . $this->int(0)          // flags
            . $this->long($randomId)
            . $this->int($ttl)
            . $this->string($text);
    }

    /**
     * Serialize a `decryptedMessageService` carrying `notifyLayer`.
     */
    public function serializeNotifyLayer(int $randomId, int $layer): string
    {
        return $this->int(self::ID_SERVICE)
            . $this->long($randomId)
            . $this->int(self::ID_ACTION_NOTIFY_LAYER)
            . $this->int($layer);
    }

    /**
     * Parse a `decryptedMessageLayer` blob into a structured array.
     *
     * @return array{layer:int,in_seq_no:int,out_seq_no:int,message:array}
     */
    public function parseLayer(string $data): array
    {
        $p = 0;
        $id = $this->readInt($data, $p);
        if (($id & 0xFFFFFFFF) !== self::ID_LAYER) {
            throw new \RuntimeException(sprintf('Expected decryptedMessageLayer, got 0x%08x', $id & 0xFFFFFFFF));
        }

        $this->readBytes($data, $p); // random_bytes (ignored)
        $layer = $this->readInt($data, $p);
        $inSeq = $this->readInt($data, $p);
        $outSeq = $this->readInt($data, $p);
        $message = $this->parseMessage($data, $p);

        return [
            'layer' => $layer,
            'in_seq_no' => $inSeq,
            'out_seq_no' => $outSeq,
            'message' => $message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseMessage(string $data, int &$p): array
    {
        $id = $this->readInt($data, $p) & 0xFFFFFFFF;

        if ($id === self::ID_MESSAGE) {
            $flags = $this->readInt($data, $p);
            $randomId = $this->readLong($data, $p);
            $ttl = $this->readInt($data, $p);
            $text = $this->readString($data, $p);

            return [
                '_' => 'decryptedMessage',
                'flags' => $flags,
                'random_id' => $randomId,
                'ttl' => $ttl,
                'message' => $text,
            ];
        }

        if ($id === self::ID_SERVICE) {
            $randomId = $this->readLong($data, $p);
            $actionId = $this->readInt($data, $p) & 0xFFFFFFFF;
            $action = ['_' => sprintf('action#%08x', $actionId)];
            if ($actionId === self::ID_ACTION_NOTIFY_LAYER) {
                $action = ['_' => 'decryptedMessageActionNotifyLayer', 'layer' => $this->readInt($data, $p)];
            }

            return ['_' => 'decryptedMessageService', 'random_id' => $randomId, 'action' => $action];
        }

        return ['_' => sprintf('unknown#%08x', $id)];
    }

    private function int(int $v): string
    {
        return pack('V', $v & 0xFFFFFFFF);
    }

    private function long(int $v): string
    {
        return pack('P', $v);
    }

    /** TL `string`/`bytes`: length prefix + data + padding to 4 bytes. */
    private function string(string $s): string
    {
        return $this->bytes($s);
    }

    private function bytes(string $s): string
    {
        $len = strlen($s);

        if ($len <= 253) {
            $out = chr($len) . $s;
        } else {
            $out = chr(254) . substr(pack('V', $len), 0, 3) . $s;
        }

        $pad = (4 - (strlen($out) % 4)) % 4;

        return $out . str_repeat("\x00", $pad);
    }

    private function readInt(string $data, int &$p): int
    {
        $v = unpack('V', substr($data, $p, 4))[1];
        $p += 4;

        // Sign-extend to a PHP int for use as a plain 32-bit value.
        return $v;
    }

    private function readLong(string $data, int &$p): int
    {
        $v = unpack('P', substr($data, $p, 8))[1];
        $p += 8;

        return $v;
    }

    private function readString(string $data, int &$p): string
    {
        return $this->readBytes($data, $p);
    }

    private function readBytes(string $data, int &$p): string
    {
        $first = ord($data[$p]);

        if ($first <= 253) {
            $len = $first;
            $start = $p + 1;
        } else {
            $len = unpack('V', substr($data, $p + 1, 3) . "\x00")[1];
            $start = $p + 4;
        }

        $value = substr($data, $start, $len);
        $consumed = ($start - $p) + $len;
        $consumed += (4 - ($consumed % 4)) % 4;
        $p += $consumed;

        return $value;
    }
}
