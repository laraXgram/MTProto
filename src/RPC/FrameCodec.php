<?php

declare(strict_types=1);

namespace LaraGram\MTProto\RPC;

use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Exceptions\SecurityException;
use LaraGram\Log\LoggerInterface;

/**
 * @internal
 */
final class FrameCodec
{
    /** gzip_packed#3072cfa1 packed_data:bytes = Object. */
    private const GZIP_PACKED = 0x3072cfa1;

    /** Max allowed size of a gzip-decompressed payload (16 MiB). zip-bomb guard. */
    private const MAX_GZIP_OUTPUT = 16 * 1024 * 1024;

    /** Max server msg_ids retained for replay detection. */
    private const SEEN_MSG_ID_LIMIT = 1024;

    /**
     * Sliding window of server msg_ids already seen (replay protection).
     * @var array<int, true>
     */
    private array $seenMsgIds = [];

    public function __construct(
        private readonly CryptoInterface    $crypto,
        private readonly SessionInterface   $session,
        private readonly TransportInterface $transport,
        private readonly ?LoggerInterface   $logger = null,
    )
    {
    }

    /**
     * Build the encrypted transport frame for one serialized message WITHOUT
     * sending it. Callers that await a reply register their pending entry
     * between this and the socket write so the reader can never resolve before
     * the call is tracked.
     *
     * @return array{0: int, 1: string} [client msg_id, wire packet]
     */
    public function encrypt(string $messageData, bool $contentRelated): array
    {
        $authKey = $this->session->getAuthKey();
        if (!$authKey) {
            throw new SecurityException('No auth key available');
        }

        $msgId = $this->session->generateMessageId();
        $seqNo = $this->session->getSeqNo($contentRelated);

        // salt(8) + session_id(8) + msg_id(8) + seq_no(4) + length(4) + data
        $innerData = $this->session->getServerSalt()
            . $this->session->getSessionId()
            . pack('P', $msgId)
            . pack('V', $seqNo)
            . pack('V', strlen($messageData))
            . $messageData;

        // Pad to a 16-byte boundary with 12..1024 random bytes.
        $paddingLength = 16 - (strlen($innerData) % 16);
        if ($paddingLength < 12) {
            $paddingLength += 16;
        }
        $innerData .= $this->crypto->randomBytes($paddingLength);

        $msgKey = $this->crypto->calculateMsgKey($authKey, $innerData, true);
        $kdf = $this->crypto->kdf($authKey, $msgKey, true);
        $encrypted = $this->crypto->aesIgeEncrypt($innerData, $kdf['aes_key'], $kdf['aes_iv']);

        $authKeyId = $this->crypto->calculateAuthKeyId($authKey);

        return [$msgId, $this->transport->wrap($authKeyId . $msgKey . $encrypted)];
    }

    /**
     * Verify + decrypt one encrypted frame, returning [server_msg_id, body].
     *
     * @return array{0: int, 1: string|null}
     */
    public function decrypt(string $data): array
    {
        $authKey = $this->session->getAuthKey();
        if (!$authKey) {
            throw new SecurityException('No auth key for decryption');
        }

        if (substr($data, 0, 8) !== $this->crypto->calculateAuthKeyId($authKey)) {
            throw new SecurityException('Auth key ID mismatch');
        }

        $msgKey = substr($data, 8, 16);
        $encryptedData = substr($data, 24);

        $kdf = $this->crypto->kdf($authKey, $msgKey, false);
        $decrypted = $this->crypto->aesIgeDecrypt($encryptedData, $kdf['aes_key'], $kdf['aes_iv']);

        // msg_key must be SHA256-derived from the plaintext we just produced.
        if ($msgKey !== $this->crypto->calculateMsgKey($authKey, $decrypted, false)) {
            throw new SecurityException('Message key verification failed');
        }

        // salt(8) + session_id(8) + msg_id(8) + seq_no(4) + length(4) + body
        $sessionId = substr($decrypted, 8, 8);
        $msgId = unpack('P', substr($decrypted, 16, 8))[1];
        $length = unpack('V', substr($decrypted, 28, 4))[1];

        // Validate the inner length before trusting it: plaintext is
        // header(32) + body + padding(12..1024); a forged length could drive an
        // OOB read or padding-oracle-style probing.
        $decryptedLen = strlen($decrypted);
        if ($length < 0 || $length > $decryptedLen - 32) {
            throw new SecurityException('Invalid inner message length');
        }
        $padding = $decryptedLen - 32 - $length;
        if ($padding < 12 || $padding > 1024) {
            throw new SecurityException('Invalid padding length');
        }

        // Soft drop: a reply for a regenerated session can still arrive.
        if ($sessionId !== $this->session->getSessionId()) {
            $this->logger?->warning('FrameCodec ignoring message with stale session ID');
            return [$msgId, null];
        }

        $reason = $this->checkServerMsgId($msgId);
        if ($reason !== null) {
            $this->logger?->warning("FrameCodec dropping server message {$msgId}: {$reason}");
            return [$msgId, null];
        }

        return [$msgId, substr($decrypted, 32, $length)];
    }

    /**
     * Validate + record an incoming server msg_id (odd, in time window, not a
     * replay). Returns a reason string when the frame must be dropped.
     */
    private function checkServerMsgId(int $msgId): ?string
    {
        // Server msg_ids are odd (1 or 3 mod 4); even ids only originate client-side.
        if (($msgId & 1) === 0) {
            return 'msg_id is not odd';
        }

        // High 32 bits are the server's unix time (msg_id = time << 32 + ...).
        $msgTime = ($msgId >> 32) & 0xFFFFFFFF;
        $now = time() + $this->session->getTimeDelta();
        if ($msgTime > $now + 30 || $msgTime < $now - 300) {
            return 'timestamp out of window (drift ' . ($msgTime - $now) . 's)';
        }

        if (isset($this->seenMsgIds[$msgId])) {
            return 'duplicate msg_id (replay)';
        }

        $this->seenMsgIds[$msgId] = true;
        if (count($this->seenMsgIds) > self::SEEN_MSG_ID_LIMIT) {
            ksort($this->seenMsgIds);
            $this->seenMsgIds = array_slice($this->seenMsgIds, -self::SEEN_MSG_ID_LIMIT, null, true);
        }

        return null;
    }

    /**
     * Decompress a gzip_packed payload, guarding against bad data and bombs.
     */
    public static function gunzip(string $packedData): string
    {
        $unpacked = @gzdecode($packedData, self::MAX_GZIP_OUTPUT);
        if ($unpacked === false) {
            throw new MTProtoException('Failed to gzip-decode packed message');
        }
        if (strlen($unpacked) >= self::MAX_GZIP_OUTPUT) {
            throw new SecurityException('Decompressed payload exceeds maximum allowed size');
        }
        return $unpacked;
    }

    /**
     * Read a TL-encoded bytes value at $offset and return its raw content.
     */
    public static function readTLBytes(string $data, int $offset): string
    {
        $firstByte = ord($data[$offset]);
        $offset++;

        if ($firstByte === 254) {
            $length = unpack('V', substr($data, $offset, 3) . "\x00")[1];
            $offset += 3;
        } else {
            $length = $firstByte;
        }

        return substr($data, $offset, $length);
    }
}
