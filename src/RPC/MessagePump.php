<?php

declare(strict_types=1);

namespace LaraGram\MTProto\RPC;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\Core\DeviceProfile;
use LaraGram\Log\LoggerInterface;
use LaraGram\MTProto\Runtime\Contracts\Channel;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\TL\TLParser;
use LaraGram\MTProto\TL\TLSerializer;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Exceptions\SecurityException;

/**
 * MessagePump — the single-reader MTProto core (Phase 1.1).
 *
 * Replaces the lock-step {@see RPCHandler::receiveResponse()} poll loop and the
 * {@see \LaraGram\MTProto\Updates\UpdateLoop} duplicate read path with ONE
 * reader coroutine. Two readers on one socket corrupt the stream the moment an
 * RPC response and a pushed update overlap; with a single reader every frame is
 * decrypted exactly once and routed by type.
 *
 *   coroutine ──invoke()──▶ Connection ──write-mutex──▶ socket
 *      ▲ Channel.pop                                     │
 *      └──── result ◀── readLoop (only reader): decrypt → route
 *                        rpc_result → pending Channel
 *                        updates    → update handler (never blocks pump)
 *                        salts/acks/pong/new_session → handled inline
 *
 * All coroutine/channel/timer/sleep primitives come from the injected
 * {@see Runtime} (RULE 1) — this class never touches `\Swoole\*` directly, so a
 * future RoadRunner/FrankenPHP/OpenSwoole backend is a Runtime swap, nothing else.
 *
 * Framework-agnostic by design (DI rule): collaborators injected, the reconnect
 * strategy is a callable supplied by the owner (Client), no container access.
 *
 * @internal Validate against a test DC before the old read paths are deleted.
 */
final class MessagePump
{
    private const GZIP_PACKED        = 0x3072cfa1;
    private const MSG_CONTAINER      = 0x73f1f8dc;
    private const RPC_RESULT         = 0xf35c6d01;
    private const RPC_ERROR          = 0x2144ca19;
    private const MSGS_ACK           = 0x62d6b459;
    private const BAD_MSG_NOTIFICATION = 0xa7eff811;
    private const BAD_SERVER_SALT    = 0xedab447b;
    private const NEW_SESSION_CREATED = 0x9ec20908;
    private const PONG               = 0x347773c5;
    private const FUTURE_SALTS       = 0xae500895;
    private const MSGS_STATE_REQ     = 0xda69fb52;
    private const MSG_DETAILED_INFO  = 0x276d3ec6;
    private const MSG_NEW_DETAILED_INFO = 0x809db6df;

    /** @var int Max allowed size of gzip-decompressed payloads (16 MiB) — zip-bomb guard. */
    private const MAX_GZIP_OUTPUT = 16 * 1024 * 1024;

    /** @var int Max server msg_ids retained for replay detection. */
    private const SEEN_MSG_ID_LIMIT = 1024;

    private TLSerializer $serializer;

    /**
     * In-flight RPC calls keyed by the client msg_id we sent.
     * @var array<int, PendingCall>
     */
    private array $pending = [];

    /** @var array<int> Server msg_ids awaiting acknowledgement. */
    private array $pendingAcks = [];

    /**
     * Sliding window of server msg_ids already seen (replay protection).
     * @var array<int, true>
     */
    private array $seenMsgIds = [];

    private bool $initialized = false;
    private bool $running = false;

    /** Single-token write mutex. */
    private ?Channel $writeLock = null;

    /** @var int[] Active timer ids (ping, ack flush). */
    private array $timers = [];

    /** @var callable(): ConnectionInterface|null Re-handshake + return fresh connection. */
    private $reconnector = null;

    /** @var callable(array): void|null Push container handler (UpdateFeed::feed). */
    private $updateHandler = null;

    private int $apiId;
    private string $apiHash;
    private int $layer = 214;

    /** Device fingerprint sent at connection init. Defaults to a realistic preset. */
    private ?DeviceProfile $deviceProfile = null;

    /** Seconds between keep-alive pings. */
    private float $pingInterval = 25.0;

    public function __construct(
        private ConnectionInterface $connection,
        private readonly TransportInterface $transport,
        private readonly CryptoInterface $crypto,
        private readonly SessionInterface $session,
        private readonly TLParser $parser,
        private readonly Runtime $runtime,
        int $apiId = 0,
        string $apiHash = '',
        private ?LoggerInterface $logger = null,
    ) {
        $this->serializer = new TLSerializer($parser);
        $this->apiId   = $apiId;
        $this->apiHash = $apiHash;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Configuration
    // ════════════════════════════════════════════════════════════════════

    public function setApiCredentials(int $apiId, string $apiHash): void
    {
        $this->apiId   = $apiId;
        $this->apiHash = $apiHash;
    }

    public function setLayer(int $layer): void
    {
        $this->layer = $layer;
    }

    public function setDeviceProfile(DeviceProfile $profile): void
    {
        $this->deviceProfile = $profile;
    }

    public function getLayer(): int
    {
        return $this->layer;
    }

    /**
     * Strategy to re-establish the link after EOF: re-handshake and return the
     * fresh, connected {@see ConnectionInterface}. Owned by Client because only
     * it knows how to TCP-connect + generate/reuse the auth key.
     *
     * @param callable(): ConnectionInterface $reconnector
     */
    public function setReconnector(callable $reconnector): void
    {
        $this->reconnector = $reconnector;
    }

    /**
     * Sink for pushed update containers. The pump never blocks on this — the
     * handler must hand off (queue a coroutine) and return immediately.
     *
     * @param callable(array): void $handler
     */
    public function setUpdateHandler(callable $handler): void
    {
        $this->updateHandler = $handler;
    }

    public function setPingInterval(float $seconds): void
    {
        $this->pingInterval = $seconds;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Lifecycle
    // ════════════════════════════════════════════════════════════════════

    /**
     * Spawn the reader coroutine + keep-alive/ack timers. MUST be called from
     * within a coroutine context (see {@see Runtime::run()}).
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        if (!$this->runtime->isSupported()) {
            throw new MTProtoException('MessagePump requires a coroutine runtime (swoole/openswoole).');
        }
        if (!$this->runtime->inCoroutine()) {
            throw new MTProtoException('MessagePump::start() must run inside a coroutine context (Runtime::run).');
        }

        // Seed the write mutex with its single token.
        $this->writeLock = $this->runtime->channel(1);
        $this->writeLock->push(true);

        $this->running = true;

        // Single reader coroutine — the only thing that reads the socket.
        $this->runtime->spawn(function (): void {
            $this->readLoop();
        });

        // Keep-alive ping + periodic ack flush via runtime timers.
        $this->timers[] = $this->runtime->timer($this->pingInterval, function (): void {
            $this->sendPing();
        });
        $this->timers[] = $this->runtime->timer(1.0, function (): void {
            $this->flushAcks();
        });
    }

    public function stop(): void
    {
        $this->running = false;

        foreach ($this->timers as $id) {
            try { $this->runtime->clearTimer($id); } catch (\Throwable) {}
        }
        $this->timers = [];

        // Fail any callers still parked on a result channel.
        foreach ($this->pending as $call) {
            $call->channel->push(['_pump_error' => new MTProtoException('Pump stopped')]);
        }
        $this->pending = [];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Connection initialisation
    // ════════════════════════════════════════════════════════════════════

    /**
     * Ping + InvokeWithLayer(InitConnection(help.getConfig)). Requires the
     * reader coroutine to be live, so call after start().
     *
     * @return array help.getConfig response
     */
    public function initializeConnection(): array
    {
        if ($this->initialized) {
            return [];
        }

        // A ping primes the link and confirms the reader is routing pongs.
        $this->ping();

        $device = $this->deviceProfile ??= DeviceProfile::preset(DeviceProfile::DEFAULT_PRESET);

        $initConnection = array_merge([
            '_'               => 'initConnection',
            'flags'           => 0,
            'api_id'          => $this->apiId,
        ], $device->toInitConnection(), [
            'query'           => ['_' => 'help.getConfig'],
        ]);

        $result = $this->invoke('invokeWithLayer', [
            'layer' => $this->layer,
            'query' => $initConnection,
        ]);

        $this->initialized = true;

        return is_array($result) ? $result : [];
    }

    // ════════════════════════════════════════════════════════════════════
    //  Invoke — concurrency-safe; N callers park on their own channel
    // ════════════════════════════════════════════════════════════════════

    /**
     * Serialize a method, send it under the write-lock, then block this
     * coroutine on a result channel until the reader routes the response.
     *
     * @param string $method        e.g. "messages.sendMessage"
     * @param array  $params        Method parameters (already preprocessed)
     * @param float  $timeout       Seconds to wait before throwing
     * @param bool   $contentRelated Counts toward the content seqno
     * @return mixed Deserialized TL result
     * @throws MTProtoException
     */
    public function invoke(string $method, array $params = [], float $timeout = 30.0, bool $contentRelated = true): mixed
    {
        $message = array_merge(['_' => $method], $params);
        $payload = $this->serializer->serialize($message);

        return $this->sendAndWait($payload, $contentRelated, $method, $timeout);
    }

    /**
     * Ping + wait for pong. Used by initializeConnection().
     */
    public function ping(): int
    {
        $pingId  = random_int(PHP_INT_MIN, PHP_INT_MAX);
        $payload = $this->serializer->serialize(['_' => 'ping', 'ping_id' => $pingId]);
        $result  = $this->sendAndWait($payload, false, 'ping', 10.0);

        if (($result['_'] ?? '') !== 'pong') {
            throw new MTProtoException('Expected pong response');
        }

        return $result['ping_id'];
    }

    /**
     * Send a pre-serialized payload and park on a result channel until the
     * reader routes its rpc_result. A bad-salt resend is handled transparently
     * by the reader (the call is re-keyed to the new msg_id).
     */
    private function sendAndWait(string $payload, bool $contentRelated, string $method, float $timeout): mixed
    {
        $channel = $this->runtime->channel(1);

        // Register the pending call BEFORE the socket write: the write yields
        // under the coroutine hook, during which the reader may already route the
        // response — if pending isn't set yet that reply is dropped.
        [$msgId, $packet] = $this->encryptFrame($payload, $contentRelated);
        $this->pending[$msgId] = new PendingCall(
            channel: $channel,
            payload: $payload,
            contentRelated: $contentRelated,
            method: $method,
        );
        $this->withWriteLock(fn () => $this->connection->send($packet));

        $boxed = $channel->pop($timeout);

        if ($boxed === false) {
            unset($this->pending[$msgId]);
            throw new MTProtoException("Timeout waiting for response to {$method} ({$msgId})");
        }

        if (isset($boxed['_pump_error'])) {
            throw $boxed['_pump_error'];
        }

        return $boxed['result'];
    }

    // ════════════════════════════════════════════════════════════════════
    //  Encryption / framing (outgoing)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Encrypt + write a serialized message (fire-and-forget: ping, ack).
     *
     * @return int The client msg_id assigned to this frame.
     */
    private function sendEncrypted(string $messageData, bool $contentRelated): int
    {
        [$msgId, $packet] = $this->encryptFrame($messageData, $contentRelated);
        $this->withWriteLock(fn () => $this->connection->send($packet));

        return $msgId;
    }

    /**
     * Build the encrypted transport frame without sending it. Callers that need
     * a reply register their pending entry between this and the write so the
     * reader can never resolve before the call is tracked.
     *
     * @return array{0: int, 1: string} [msg_id, wire packet]
     */
    private function encryptFrame(string $messageData, bool $contentRelated): array
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
        $kdf    = $this->crypto->kdf($authKey, $msgKey, true);
        $encrypted = $this->crypto->aesIgeEncrypt($innerData, $kdf['aes_key'], $kdf['aes_iv']);

        $authKeyId = $this->crypto->calculateAuthKeyId($authKey);

        return [$msgId, $this->transport->wrap($authKeyId . $msgKey . $encrypted)];
    }

    /**
     * Run a closure while holding the single-token write mutex.
     */
    private function withWriteLock(callable $fn): void
    {
        if ($this->writeLock === null) {
            $fn();
            return;
        }
        $this->writeLock->pop();
        try {
            $fn();
        } finally {
            $this->writeLock->push(true);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  Reader coroutine — the ONLY socket reader
    // ════════════════════════════════════════════════════════════════════

    private function readLoop(): void
    {
        while ($this->running) {
            try {
                $length = $this->transport->readLength($this->connection);
                $packet = $this->connection->receive($length);
                if ($packet === null) {
                    continue; // read timeout — loop again
                }

                $data = $this->transport->unwrap($packet);
                $this->routeFrame($data);
            } catch (\Throwable $e) {
                if (!$this->running) {
                    break;
                }

                $this->logger?->warning("Pump read error: {$e->getMessage()}");

                if (!$this->connection->isConnected()) {
                    $this->reconnect();
                }
            }
        }
    }

    /**
     * Decrypt one transport frame and dispatch it.
     */
    private function routeFrame(string $data): void
    {
        $authKeyId = substr($data, 0, 8);

        if ($authKeyId === str_repeat("\x00", 8)) {
            // Plaintext frames are only legal during the handshake. Once an auth
            // key exists, accepting them is a downgrade/injection vector.
            if ($this->session->getAuthKey() !== null) {
                throw new SecurityException('Unencrypted message received after handshake');
            }
            return;
        }

        [$msgId, $body] = $this->decryptFrame($data);
        if ($body === null) {
            return; // stale session / dropped / failed checks
        }

        $this->pendingAcks[] = $msgId;
        $this->dispatch($msgId, $body);
    }

    /**
     * Verify + decrypt an encrypted frame, returning [server_msg_id, body] or
     * [_, null] when the frame must be silently dropped.
     *
     * @return array{0: int, 1: string|null}
     */
    private function decryptFrame(string $data): array
    {
        $authKey = $this->session->getAuthKey();
        if (!$authKey) {
            throw new SecurityException('No auth key for decryption');
        }

        if (substr($data, 0, 8) !== $this->crypto->calculateAuthKeyId($authKey)) {
            throw new SecurityException('Auth key ID mismatch');
        }

        $msgKey        = substr($data, 8, 16);
        $encryptedData = substr($data, 24);

        $kdf       = $this->crypto->kdf($authKey, $msgKey, false);
        $decrypted = $this->crypto->aesIgeDecrypt($encryptedData, $kdf['aes_key'], $kdf['aes_iv']);

        // msg_key must be SHA256-derived from the plaintext we just produced.
        if ($msgKey !== $this->crypto->calculateMsgKey($authKey, $decrypted, false)) {
            throw new SecurityException('Message key verification failed');
        }

        // salt(8) + session_id(8) + msg_id(8) + seq_no(4) + length(4) + body
        $sessionId = substr($decrypted, 8, 8);
        $msgId     = unpack('P', substr($decrypted, 16, 8))[1];
        $length    = unpack('V', substr($decrypted, 28, 4))[1];

        $decryptedLen = strlen($decrypted);
        if ($length < 0 || $length > $decryptedLen - 32) {
            throw new SecurityException('Invalid inner message length');
        }
        $padding = $decryptedLen - 32 - $length;
        if ($padding < 12 || $padding > 1024) {
            throw new SecurityException('Invalid padding length');
        }

        if ($sessionId !== $this->session->getSessionId()) {
            $this->logger?->warning('Pump ignoring message with stale session ID');
            return [$msgId, null];
        }

        $reason = $this->checkServerMsgId($msgId);
        if ($reason !== null) {
            $this->logger?->warning("Pump dropping server message {$msgId}: {$reason}");
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
        if (($msgId & 1) === 0) {
            return 'msg_id is not odd';
        }

        $msgTime = ($msgId >> 32) & 0xFFFFFFFF;
        $now     = time() + $this->session->getTimeDelta();
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

    // ════════════════════════════════════════════════════════════════════
    //  Routing by constructor
    // ════════════════════════════════════════════════════════════════════

    /**
     * Dispatch a decrypted message body by its TL constructor.
     */
    private function dispatch(int $msgId, string $body): void
    {
        if (strlen($body) < 4) {
            return;
        }

        $constructorId = unpack('V', substr($body, 0, 4))[1];

        match ($constructorId) {
            self::GZIP_PACKED          => $this->dispatch($msgId, $this->gunzip($this->readTLBytes($body, 4))),
            self::MSG_CONTAINER        => $this->handleContainer($body),
            self::RPC_RESULT           => $this->handleRpcResult($body),
            self::MSGS_ACK             => $this->handleMsgsAck($body),
            self::BAD_MSG_NOTIFICATION,
            self::BAD_SERVER_SALT      => $this->handleBadMsg($body),
            self::NEW_SESSION_CREATED  => $this->handleNewSession($body),
            self::PONG                 => $this->handlePong($body),
            self::FUTURE_SALTS         => $this->handleFutureSalts($body),
            self::MSGS_STATE_REQ,
            self::MSG_DETAILED_INFO,
            self::MSG_NEW_DETAILED_INFO => null, // service info — ignored for now
            default                    => $this->handleUpdateOrUnknown($body),
        };
    }

    /**
     * msg_container#73f1f8dc — unwrap and route each inner message.
     */
    private function handleContainer(string $data): void
    {
        $offset  = 4;
        $count   = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        $dataLen = strlen($data);

        for ($i = 0; $i < $count; $i++) {
            if ($offset + 16 > $dataLen) {
                break;
            }
            $msgId   = unpack('P', substr($data, $offset, 8))[1];
            $offset += 8;
            $offset += 4; // seqno (unused)
            $bodyLen = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if ($offset + $bodyLen > $dataLen) {
                $bodyLen = $dataLen - $offset;
            }
            $body    = substr($data, $offset, $bodyLen);
            $offset += $bodyLen;

            $this->pendingAcks[] = $msgId;
            try {
                $this->dispatch($msgId, $body);
            } catch (\Throwable $e) {
                $this->logger?->error("Pump container message {$msgId} failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * rpc_result#f35c6d01 — resolve the waiting invoke() for req_msg_id.
     */
    private function handleRpcResult(string $data): void
    {
        $reqMsgId   = unpack('P', substr($data, 4, 8))[1];
        $resultData = substr($data, 12);

        $constructorId = unpack('V', substr($resultData, 0, 4))[1];
        if ($constructorId === self::GZIP_PACKED) {
            $resultData    = $this->gunzip($this->readTLBytes($resultData, 4));
            $constructorId = unpack('V', substr($resultData, 0, 4))[1];
        }

        if ($constructorId === self::RPC_ERROR) {
            $error = $this->serializer->deserialize($resultData);
            $this->resolve($reqMsgId, [
                '_pump_error' => new MTProtoException(
                    "RPC Error {$error['error_code']}: {$error['error_message']}",
                    $error['error_code'],
                ),
            ]);
            return;
        }

        $this->resolve($reqMsgId, ['result' => $this->serializer->deserialize($resultData)]);
    }

    /**
     * Push a boxed result/error into the channel of a waiting call, if any.
     */
    private function resolve(int $reqMsgId, array $boxed): void
    {
        $call = $this->pending[$reqMsgId] ?? null;
        if ($call === null) {
            return; // late/duplicate response — nothing waiting
        }
        unset($this->pending[$reqMsgId]);
        $call->channel->push($boxed);
    }

    /**
     * msgs_ack — mark matched calls acknowledged (their rpc_result still
     * resolves the channel separately).
     */
    private function handleMsgsAck(string $data): void
    {
        $ack = $this->serializer->deserialize($data);
        foreach ($ack['msg_ids'] ?? [] as $id) {
            if (isset($this->pending[$id])) {
                $this->pending[$id]->acked = true;
            }
        }
    }

    /**
     * bad_server_salt / bad_msg_notification. For a stale salt we update it and
     * transparently resend the offending payload under a fresh msg_id, re-keying
     * its pending entry so the original invoke() still gets its answer.
     */
    private function handleBadMsg(string $data): void
    {
        $badMsg    = $this->serializer->deserialize($data);
        $errorCode = $badMsg['error_code'] ?? 0;
        $badMsgId  = $badMsg['bad_msg_id'] ?? 0;

        if (isset($badMsg['new_server_salt'])) {
            $this->session->setServerSalt(pack('P', $badMsg['new_server_salt']));
        }

        // 48 = incorrect server salt → resend with corrected salt.
        if ($errorCode === 48) {
            $this->resend($badMsgId);
            return;
        }

        // Everything else: surface as an error to the waiting caller.
        $this->resolve($badMsgId, [
            '_pump_error' => new MTProtoException("Bad message notification: code {$errorCode}", $errorCode),
        ]);
    }

    /**
     * Re-send a pending call's payload under a new msg_id and re-key it.
     */
    private function resend(int $oldMsgId): void
    {
        $call = $this->pending[$oldMsgId] ?? null;
        if ($call === null) {
            return;
        }
        unset($this->pending[$oldMsgId]);

        [$newMsgId, $packet] = $this->encryptFrame($call->payload, $call->contentRelated);
        $this->pending[$newMsgId] = $call;
        $this->withWriteLock(fn () => $this->connection->send($packet));
    }

    /**
     * new_session_created — adopt the server salt for the new session.
     */
    private function handleNewSession(string $data): void
    {
        $session = $this->serializer->deserialize($data);
        if (isset($session['server_salt'])) {
            $this->session->setServerSalt(pack('P', $session['server_salt']));
        }
    }

    /**
     * pong#347773c5 — resolves the ping it answers (pong.msg_id is the ping's
     * client msg_id). Keep-alive pings carry no pending entry, so those no-op.
     */
    private function handlePong(string $data): void
    {
        $pong = $this->serializer->deserialize($data);
        if (isset($pong['msg_id'])) {
            $this->resolve($pong['msg_id'], ['result' => $pong]);
        }
    }

    /**
     * future_salts#ae500895 — resolves the get_future_salts request it answers.
     */
    private function handleFutureSalts(string $data): void
    {
        $salts = $this->serializer->deserialize($data);
        if (isset($salts['req_msg_id'])) {
            $this->resolve($salts['req_msg_id'], ['result' => $salts]);
        }
    }

    /**
     * Anything not a service message is an update container — hand it off to the
     * update sink without ever blocking the reader.
     */
    private function handleUpdateOrUnknown(string $body): void
    {
        if ($this->updateHandler === null) {
            return;
        }

        try {
            $decoded = $this->serializer->deserialize($body);
        } catch (\Throwable $e) {
            $this->logger?->error("Pump failed to deserialize pushed message: {$e->getMessage()}");
            return;
        }

        if (!is_array($decoded) || !isset($decoded['_'])) {
            return;
        }

        static $updateTypes = [
            'updates'                => true,
            'updatesCombined'        => true,
            'updateShort'            => true,
            'updateShortMessage'     => true,
            'updateShortChatMessage' => true,
            'updateShortSentMessage' => true,
            'updatesTooLong'         => true,
        ];

        if (isset($updateTypes[$decoded['_']])) {
            ($this->updateHandler)($decoded);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  Acks / keep-alive / reconnect
    // ════════════════════════════════════════════════════════════════════

    /**
     * Flush accumulated server msg_ids as a single msgs_ack.
     */
    private function flushAcks(): void
    {
        if (!$this->running || empty($this->pendingAcks)) {
            return;
        }

        $acks    = array_splice($this->pendingAcks, 0, 8192);
        $payload = $this->serializer->serialize(['_' => 'msgs_ack', 'msg_ids' => $acks]);

        try {
            $this->sendEncrypted($payload, false);
        } catch (\Throwable $e) {
            // Put them back; a later flush (or reconnect) retries.
            $this->pendingAcks = array_merge($acks, $this->pendingAcks);
            $this->logger?->warning("Pump ack flush failed: {$e->getMessage()}");
        }
    }

    /**
     * Keep-alive: ping_delay_disconnect every pingInterval. Fire-and-forget —
     * the pong is routed (and ignored) by the reader.
     */
    private function sendPing(): void
    {
        if (!$this->running) {
            return;
        }
        try {
            $payload = $this->serializer->serialize([
                '_'                => 'ping_delay_disconnect',
                'ping_id'          => random_int(PHP_INT_MIN, PHP_INT_MAX),
                'disconnect_delay' => (int) ($this->pingInterval * 2.5),
            ]);
            $this->sendEncrypted($payload, false);
        } catch (\Throwable $e) {
            $this->logger?->warning("Pump ping failed: {$e->getMessage()}");
        }
    }

    /**
     * EOF recovery: back off, re-handshake via the injected reconnector, then
     * replay every unanswered call and ask for the missed difference.
     */
    private function reconnect(): void
    {
        if ($this->reconnector === null) {
            $this->logger?->error('Pump connection lost and no reconnector set — stopping');
            $this->running = false;
            return;
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                // Equal-jitter back-off so reconnects avoid a fixed cadence (B4).
                $base = min($attempt * 2, 10);
                $this->runtime->sleep($base / 2 + (mt_rand(0, 1000) / 1000.0) * ($base / 2));
                $this->connection = ($this->reconnector)();
                $this->logger?->info("Pump reconnected (attempt {$attempt})");

                // Replay calls that never got an answer (new msg_ids, re-keyed).
                foreach ($this->pending as $oldMsgId => $call) {
                    $this->resend($oldMsgId);
                }

                // Tell the update side to fetch what it missed while we were down.
                if ($this->updateHandler !== null) {
                    ($this->updateHandler)(['_' => 'updatesTooLong']);
                }

                return;
            } catch (\Throwable $e) {
                $this->logger?->warning("Pump reconnect attempt {$attempt} failed: {$e->getMessage()}");
            }
        }

        $this->logger?->error('Pump failed to reconnect after 5 attempts — stopping');
        $this->running = false;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Decompress a gzip_packed payload, guarding against bad data and bombs.
     */
    private function gunzip(string $packedData): string
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
    private function readTLBytes(string $data, int $offset): string
    {
        $firstByte = ord($data[$offset]);
        $offset++;

        if ($firstByte === 254) {
            $length  = unpack('V', substr($data, $offset, 3) . "\x00")[1];
            $offset += 3;
        } else {
            $length = $firstByte;
        }

        return substr($data, $offset, $length);
    }
}

/**
 * An in-flight RPC awaiting its response.
 */
final class PendingCall
{
    public function __construct(
        public readonly Channel $channel,
        public readonly string $payload,
        public readonly bool $contentRelated,
        public readonly string $method,
        public bool $acked = false,
    ) {}
}
