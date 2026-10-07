<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

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
use LaraGram\MTProto\Exceptions\ReadTimeoutException;
use LaraGram\MTProto\Exceptions\SecurityException;

final class MessagePump
{
    private const GZIP_PACKED = 0x3072cfa1;
    private const MSG_CONTAINER = 0x73f1f8dc;
    private const RPC_RESULT = 0xf35c6d01;
    private const RPC_ERROR = 0x2144ca19;
    private const MSGS_ACK = 0x62d6b459;
    private const BAD_MSG_NOTIFICATION = 0xa7eff811;
    private const BAD_SERVER_SALT = 0xedab447b;
    private const NEW_SESSION_CREATED = 0x9ec20908;
    private const PONG = 0x347773c5;
    private const FUTURE_SALTS = 0xae500895;
    private const MSGS_STATE_REQ = 0xda69fb52;
    private const MSG_DETAILED_INFO = 0x276d3ec6;
    private const MSG_NEW_DETAILED_INFO = 0x809db6df;

    private TLSerializer $serializer;

    /** Shared frame crypto. */
    private FrameCodec $codec;

    /**
     * In-flight RPC calls keyed by the client msg_id we sent.
     * @var array<int, PendingCall>
     */
    private array $pending = [];

    /** @var array<int> Server msg_ids awaiting acknowledgement. */
    private array $pendingAcks = [];

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
    private int $layer = \LaraGram\MTProto\TL\SchemaSource::DEFAULT_LAYER;

    /** Device fingerprint sent at connection init. Defaults to a realistic preset. */
    private ?DeviceProfile $deviceProfile = null;

    /** Seconds between keep-alive pings. */
    private float $pingInterval = 25.0;

    /**
     * Lightweight transfer diagnostics, cumulative since start().
     * @var array<string, int>
     */
    private array $stats = [
        'frames_in' => 0,
        'bytes_in' => 0,
        'frames_out' => 0,
        'bytes_out' => 0,
        'transport_errors' => 0,
        'garbage_frames' => 0,
        'reconnects' => 0,
        'resends' => 0,
        'timeouts' => 0,
        'rpc_errors' => 0,
        'bad_msgs' => 0,
        'flood_waits' => 0,
    ];

    /**
     * Why things went wrong: RPC error types ("420 FLOOD_WAIT_X"), bad_msg
     * codes and reconnect reasons, each with a count. Exposed via getStats().
     *
     * @var array{rpc_error_types: array<string, int>, bad_msg_codes: array<int, int>, reconnect_reasons: array<string, int>}
     */
    private array $diagnostics = [
        'rpc_error_types' => [],
        'bad_msg_codes' => [],
        'reconnect_reasons' => [],
    ];

    /**
     * Seconds a call sent before a reconnect waits for the server to redeliver
     * its answer on the resumed session before it is resent. Self-tuning: it
     * drops to 0 after two reconnects in a row without any redelivery.
     */
    private float $redeliveryGrace = 0.5;

    /** @var array<int, true> msg_ids in flight at the last reconnect */
    private array $awaitingRedelivery = [];

    private int $redelivered = 0;

    private int $graceMisses = 0;

    /** Last time a seqno correction was applied (32/33 arrive in bursts). */
    private float $lastSeqShift = 0.0;

    public function __construct(
        private ConnectionInterface         $connection,
        private readonly TransportInterface $transport,
        private readonly CryptoInterface    $crypto,
        private readonly SessionInterface   $session,
        private readonly TLParser           $parser,
        private readonly Runtime            $runtime,
        int                                 $apiId = 0,
        string                              $apiHash = '',
        private ?LoggerInterface            $logger = null,
    )
    {
        $this->serializer = new TLSerializer($parser);
        $this->codec = new FrameCodec($crypto, $session, $transport, $logger);
        $this->apiId = $apiId;
        $this->apiHash = $apiHash;
    }

    public function setApiCredentials(int $apiId, string $apiHash): void
    {
        $this->apiId = $apiId;
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
     * Sink for pushed update containers. The pump never blocks on this. the
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

        // Single reader coroutine - the only thing that reads the socket.
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
            try {
                $this->runtime->clearTimer($id);
            } catch (\Throwable) {
            }
        }
        $this->timers = [];

        // Fail any callers still parked on a result channel.
        $this->failPending(new MTProtoException('Pump stopped'));
    }

    /**
     * Push an error to every caller parked on a pending result channel.
     */
    private function failPending(MTProtoException $error): void
    {
        $calls = [];
        foreach ($this->pending as $call) {
            $calls[spl_object_id($call)] = $call;
        }
        $this->pending = [];

        foreach ($calls as $call) {
            try {
                $call->channel->push(['_pump_error' => $error]);
            } catch (\Throwable) {
            }
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Cumulative transfer diagnostics: integer counters (frames/bytes in+out,
     * errors, resends, reconnects, bad_msgs, flood_waits) plus the
     * `rpc_error_types`, `bad_msg_codes` and `reconnect_reasons` breakdowns.
     *
     * @return array<string, int|array<string|int, int>>
     */
    public function getStats(): array
    {
        return $this->stats + $this->diagnostics;
    }

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
            '_' => 'initConnection',
            'flags' => 0,
            'api_id' => $this->apiId,
        ], $device->toInitConnection(), [
            'query' => ['_' => 'help.getConfig'],
        ]);

        $result = $this->invoke('invokeWithLayer', [
            'layer' => $this->layer,
            'query' => $initConnection,
        ]);

        $this->initialized = true;

        return is_array($result) ? $result : [];
    }

    /**
     * Serialize a method, send it under the write-lock, then block this
     * coroutine on a result channel until the reader routes the response.
     *
     * @param string $method
     * @param array $params
     * @param float $timeout
     * @param bool $contentRelated
     * @return mixed
     * @throws MTProtoException
     */
    public function invoke(string $method, array $params = [], float $timeout = 30.0, bool $contentRelated = true): mixed
    {
        // File-transfer RPCs legitimately queue behind megabytes of in-flight
        // parts; a 30s cap makes every congestion blip a timeout->resend spiral.
        if ($timeout <= 30.0 && str_starts_with($method, 'upload.')) {
            $timeout = 180.0;
        }

        $message = array_merge(['_' => $method], $params);
        $payload = $this->serializer->serialize($message);

        return $this->sendAndWait($payload, $contentRelated, $method, $timeout);
    }

    /**
     * Ping + wait for pong. Used by initializeConnection().
     */
    public function ping(): int
    {
        $pingId = random_int(PHP_INT_MIN, PHP_INT_MAX);
        $payload = $this->serializer->serialize(['_' => 'ping', 'ping_id' => $pingId]);
        $result = $this->sendAndWait($payload, false, 'ping', 10.0);

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

        [$msgId, $packet] = $this->codec->encrypt($payload, $contentRelated);
        $call = new PendingCall(
            channel: $channel,
            payload: $payload,
            contentRelated: $contentRelated,
            method: $method,
        );
        $call->ids[] = $msgId;
        $this->pending[$msgId] = $call;
        $this->stats['frames_out']++;
        $this->stats['bytes_out'] += strlen($packet);

        try {
            $this->withWriteLock(fn() => $this->connection->send($packet));
        } catch (\Throwable $e) {
            if (!$this->running) {
                $this->forget($call);
                throw $e;
            }
            // The link is dying: the reader reconnects and replays this call
            // (immediately, since it never reached the server) - keep waiting.
            $call->sent = false;
            $this->logger?->debug("Pump send of {$method} failed ({$e->getMessage()}); waiting for reconnect replay");
        }

        $boxed = $channel->pop($timeout);

        if ($boxed === false) {
            $this->forget($call);
            $this->stats['timeouts']++;
            throw new MTProtoException("Timeout waiting for response to {$method} ({$msgId})");
        }

        if (isset($boxed['_pump_error'])) {
            throw $boxed['_pump_error'];
        }

        return $boxed['result'];
    }

    /**
     * Encrypt + write a serialized message (fire-and-forget: ping, ack).
     *
     * @return int
     */
    private function sendEncrypted(string $messageData, bool $contentRelated): int
    {
        [$msgId, $packet] = $this->codec->encrypt($messageData, $contentRelated);
        $this->stats['frames_out']++;
        $this->stats['bytes_out'] += strlen($packet);
        $this->withWriteLock(fn() => $this->connection->send($packet));

        return $msgId;
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

    private function readLoop(): void
    {
        while ($this->running) {
            try {
                $length = $this->transport->readLength($this->connection);
                $packet = $this->connection->receive($length);
                if ($packet === null) {
                    throw new MTProtoException("Timed out mid-frame ({$length} bytes expected)");
                }

                $data = $this->transport->unwrap($packet);
                $this->routeFrame($data);
            } catch (ReadTimeoutException) {
                // Quiet link on a frame boundary - the stream is intact.
                // Keep-alive pings cover liveness; reconnecting here would kill
                // and resend every in-flight call (congestion storm).
                if ($this->running && $this->connection->isConnected()) {
                    continue;
                }
                if (!$this->running) {
                    break;
                }
                $this->reconnect('socket closed while idle');
            } catch (\Throwable $e) {
                if (!$this->running) {
                    break;
                }

                $this->logger?->warning("Pump read error: {$e->getMessage()}");

                try {
                    $this->connection->disconnect();
                } catch (\Throwable) {
                }
                $this->reconnect((new \ReflectionClass($e))->getShortName() . ': ' . self::normalise($e->getMessage()));
            }
        }
    }

    /**
     * Decrypt one transport frame and dispatch it.
     */
    private function routeFrame(string $data): void
    {
        $this->stats['frames_in']++;
        $this->stats['bytes_in'] += strlen($data);

        if (strlen($data) === 4) {
            $code = unpack('l', $data)[1];

            if ($code >= 0 || $code < -9999) {
                $this->stats['garbage_frames']++;
                throw new MTProtoException("Unrecognized 4-byte frame {$code} - stream desync, resyncing");
            }

            $error = match ($code) {
                -429 => new MTProtoException('TRANSPORT_FLOOD (-429): server is rate-limiting this connection'),
                -404 => new MTProtoException('AUTH_KEY_INVALID (-404): auth key not registered on this DC'),
                default => new MTProtoException("Transport error {$code}"),
            };

            $this->stats['transport_errors']++;
            $this->failPending($error);
            throw $error;
        }

        $authKeyId = substr($data, 0, 8);

        if ($authKeyId === str_repeat("\x00", 8)) {
            // Plaintext frames are only legal during the handshake. Once an auth
            // key exists, accepting them is a downgrade/injection vector.
            if ($this->session->getAuthKey() !== null) {
                throw new SecurityException('Unencrypted message received after handshake');
            }
            return;
        }

        $authKey = $this->session->getAuthKey();
        if ($authKey !== null) {
            $expected = $this->codec->authKeyId($authKey);
            if ($authKeyId !== $expected) {
                $this->logger?->warning(sprintf(
                    'FrameCodec key-id mismatch: len=%d expected=%s got=%s head=%s',
                    strlen($data),
                    bin2hex($expected),
                    bin2hex($authKeyId),
                    bin2hex(substr($data, 0, 16)),
                ));
            }
        }

        [$msgId, $body] = $this->codec->decrypt($data);
        if ($body === null) {
            return; // stale session / dropped / failed checks
        }

        $this->pendingAcks[] = $msgId;
        $this->dispatch($msgId, $body);
    }

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
            self::GZIP_PACKED => $this->dispatch($msgId, FrameCodec::gunzip(FrameCodec::readTLBytes($body, 4))),
            self::MSG_CONTAINER => $this->handleContainer($body),
            self::RPC_RESULT => $this->handleRpcResult($body),
            self::MSGS_ACK => $this->handleMsgsAck($body),
            self::BAD_MSG_NOTIFICATION,
            self::BAD_SERVER_SALT => $this->handleBadMsg($msgId, $body),
            self::NEW_SESSION_CREATED => $this->handleNewSession($body),
            self::PONG => $this->handlePong($body),
            self::FUTURE_SALTS => $this->handleFutureSalts($body),
            self::MSGS_STATE_REQ,
            self::MSG_DETAILED_INFO,
            self::MSG_NEW_DETAILED_INFO => null,
            default => $this->handleUpdateOrUnknown($body),
        };
    }

    /**
     * msg_container#73f1f8dc - unwrap and route each inner message.
     */
    private function handleContainer(string $data): void
    {
        $offset = 4;
        $count = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        $dataLen = strlen($data);

        for ($i = 0; $i < $count; $i++) {
            if ($offset + 16 > $dataLen) {
                break;
            }
            $msgId = unpack('P', substr($data, $offset, 8))[1];
            $offset += 8;
            $offset += 4; // seqno (unused)
            $bodyLen = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if ($offset + $bodyLen > $dataLen) {
                $bodyLen = $dataLen - $offset;
            }
            $body = substr($data, $offset, $bodyLen);
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
     * rpc_result#f35c6d01 - resolve the waiting invoke() for req_msg_id.
     */
    private function handleRpcResult(string $data): void
    {
        $reqMsgId = unpack('P', substr($data, 4, 8))[1];
        $resultData = substr($data, 12);

        $constructorId = unpack('V', substr($resultData, 0, 4))[1];
        if ($constructorId === self::GZIP_PACKED) {
            $resultData = FrameCodec::gunzip(FrameCodec::readTLBytes($resultData, 4));
            $constructorId = unpack('V', substr($resultData, 0, 4))[1];
        }

        if ($constructorId === self::RPC_ERROR) {
            $this->stats['rpc_errors']++;
            $error = $this->serializer->deserialize($resultData);
            $type = ($error['error_code'] ?? 0) . ' ' . self::normalise((string) ($error['error_message'] ?? ''));
            $this->diagnostics['rpc_error_types'][$type] = ($this->diagnostics['rpc_error_types'][$type] ?? 0) + 1;
            if (str_contains((string) ($error['error_message'] ?? ''), 'FLOOD')) {
                $this->stats['flood_waits']++;
            }
            $this->logger?->debug(sprintf(
                'Pump rpc_error %s for %s',
                trim(($error['error_code'] ?? '') . ' ' . ($error['error_message'] ?? '')),
                $this->pending[$reqMsgId]->method ?? 'unknown call',
            ));
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
            return; // late/duplicate response - nothing waiting
        }
        if (isset($this->awaitingRedelivery[$reqMsgId])) {
            $this->redelivered++;
        }
        $this->forget($call);
        $call->channel->push($boxed);
    }

    /**
     * Drop every msg_id a call is known under (it may have been resent).
     */
    private function forget(PendingCall $call): void
    {
        foreach ($call->ids as $id) {
            unset($this->pending[$id]);
        }
    }

    /**
     * msgs_ack - mark matched calls acknowledged (their rpc_result still
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
     * bad_server_salt / bad_msg_notification: correct what the server
     * complains about, then resend the call under a fresh msg_id so the
     * original invoke() still gets its answer.
     *
     *  16/17  msg_id too low/high  -> sync the clock from the server msg_id
     *  32/33  seqno too low/high   -> shift the seqno counter
     *  48     bad server salt      -> adopt new_server_salt
     *  18/19/20/34/35/64           -> plain resend (fresh msg_id/seqno)
     */
    private function handleBadMsg(int $serverMsgId, string $data): void
    {
        $badMsg = $this->serializer->deserialize($data);
        $errorCode = (int) ($badMsg['error_code'] ?? 0);
        $badMsgId = (int) ($badMsg['bad_msg_id'] ?? 0);

        $this->stats['bad_msgs']++;
        $this->diagnostics['bad_msg_codes'][$errorCode] = ($this->diagnostics['bad_msg_codes'][$errorCode] ?? 0) + 1;

        if (isset($badMsg['new_server_salt'])) {
            $this->session->setServerSalt(pack('P', $badMsg['new_server_salt']));
        }

        switch ($errorCode) {
            case 16:
            case 17:
                $delta = ($serverMsgId >> 32) - time();
                if ($delta !== $this->session->getTimeDelta()) {
                    $this->session->setTimeDelta($delta);
                    $this->logger?->info("Pump clock corrected by server (bad_msg {$errorCode}): time delta {$delta}s");
                }
                break;
            case 32:
            case 33:
                $this->shiftSeqNo($errorCode === 32 ? 32 : -8);
                break;
            case 18:
            case 19:
            case 20:
            case 34:
            case 35:
            case 48:
            case 64:
                break;
            default:
                $this->resolve($badMsgId, [
                    '_pump_error' => new MTProtoException("Bad message notification: code {$errorCode}", $errorCode),
                ]);
                return;
        }

        $this->resend($badMsgId, keepAlias: false);
    }

    /**
     * Apply a seqno correction at most once per second - a burst of 32/33
     * notifications for calls sent together describes the same drift.
     */
    private function shiftSeqNo(int $contentMessages): void
    {
        $now = microtime(true);
        if ($now - $this->lastSeqShift < 1.0) {
            return;
        }
        $this->lastSeqShift = $now;

        if (method_exists($this->session, 'shiftSeqNo')) {
            $this->session->shiftSeqNo($contentMessages);
        } else {
            $this->session->regenerateSessionId();
        }
    }

    /**
     * Re-send a pending call's payload under a new msg_id and re-key it.
     *
     * @param bool $keepAlias keep answering to the old msg_id too - after a
     *        reconnect the server may still redeliver the original answer
     */
    private function resend(int $oldMsgId, bool $keepAlias = true): void
    {
        $call = $this->pending[$oldMsgId] ?? null;
        if ($call === null) {
            return;
        }

        // Rejections (bad_msg) loop only so often; reconnect replays never give up.
        if (!$keepAlias && ++$call->resends > 5) {
            $this->resolve($oldMsgId, ['_pump_error' => new MTProtoException("{$call->method} rejected after 5 resends")]);
            return;
        }

        if (!$keepAlias) {
            unset($this->pending[$oldMsgId]);
            $call->ids = array_values(array_diff($call->ids, [$oldMsgId]));
        }

        [$newMsgId, $packet] = $this->codec->encrypt($call->payload, $call->contentRelated);
        $call->ids[] = $newMsgId;
        $call->sent = true;
        $this->pending[$newMsgId] = $call;
        $this->stats['resends']++;
        $this->stats['frames_out']++;
        $this->stats['bytes_out'] += strlen($packet);
        $this->withWriteLock(fn() => $this->connection->send($packet));
    }

    /**
     * new_session_created - adopt the server salt for the new session.
     */
    private function handleNewSession(string $data): void
    {
        $session = $this->serializer->deserialize($data);
        if (isset($session['server_salt'])) {
            $this->session->setServerSalt(pack('P', $session['server_salt']));
        }
    }

    /**
     * pong#347773c5 - resolves the ping it answers (pong.msg_id is the ping's
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
     * future_salts#ae500895 - resolves the get_future_salts request it answers.
     */
    private function handleFutureSalts(string $data): void
    {
        $salts = $this->serializer->deserialize($data);
        if (isset($salts['req_msg_id'])) {
            $this->resolve($salts['req_msg_id'], ['result' => $salts]);
        }
    }

    /**
     * Anything not a service message is an update container - hand it off to the
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
            'updates' => true,
            'updatesCombined' => true,
            'updateShort' => true,
            'updateShortMessage' => true,
            'updateShortChatMessage' => true,
            'updateShortSentMessage' => true,
            'updatesTooLong' => true,
        ];

        if (isset($updateTypes[$decoded['_']])) {
            ($this->updateHandler)($decoded);
        }
    }

    /**
     * Flush accumulated server msg_ids as a single msgs_ack.
     */
    private function flushAcks(): void
    {
        if (!$this->running || empty($this->pendingAcks)) {
            return;
        }

        $acks = array_splice($this->pendingAcks, 0, 8192);
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
     * Keep-alive: ping_delay_disconnect every pingInterval. Fire-and-forget -
     * the pong is routed (and ignored) by the reader.
     */
    private function sendPing(): void
    {
        if (!$this->running) {
            return;
        }
        try {
            $payload = $this->serializer->serialize([
                '_' => 'ping_delay_disconnect',
                'ping_id' => random_int(PHP_INT_MIN, PHP_INT_MAX),
                'disconnect_delay' => (int)($this->pingInterval * 2.5),
            ]);
            $this->sendEncrypted($payload, false);
        } catch (\Throwable $e) {
            $this->logger?->warning("Pump ping failed: {$e->getMessage()}");
        }
    }

    /**
     * EOF recovery: re-open the link on the SAME MTProto session (the server
     * keeps it across TCP connections, so no new_session/salt churn), then
     * replay unanswered calls. Calls that never reached the server go out at
     * once; calls that did get {@see $redeliveryGrace} seconds for the server
     * to redeliver their answer before they are resent - otherwise a dropped
     * link would download every in-flight file part twice.
     */
    private function reconnect(string $reason = 'unknown'): void
    {
        $this->diagnostics['reconnect_reasons'][$reason] = ($this->diagnostics['reconnect_reasons'][$reason] ?? 0) + 1;

        if ($this->reconnector === null) {
            $this->logger?->error("Pump connection lost ({$reason}) and no reconnector set - stopping");
            $this->running = false;
            return;
        }

        $this->logger?->info("Pump reconnecting: {$reason}");

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                // First attempt is immediate; later ones back off with equal jitter.
                if ($attempt > 1) {
                    $base = min(($attempt - 1) * 2, 10);
                    $this->runtime->sleep($base / 2 + (mt_rand(0, 1000) / 1000.0) * ($base / 2));
                }
                $this->connection = ($this->reconnector)();
                $this->stats['reconnects']++;
                $this->logger?->info("Pump reconnected (attempt {$attempt})");

                $this->replayPending();

                // Tell the update side to fetch what it missed while we were down.
                if ($this->updateHandler !== null) {
                    ($this->updateHandler)(['_' => 'updatesTooLong']);
                }

                return;
            } catch (\Throwable $e) {
                $this->logger?->warning("Pump reconnect attempt {$attempt} failed: {$e->getMessage()}");
            }
        }

        $this->logger?->error('Pump failed to reconnect after 5 attempts - stopping');
        $this->running = false;
        $this->failPending(new MTProtoException("Pump stopped: reconnect failed ({$reason})"));
    }

    /**
     * Resend calls after a reconnect: unsent ones now, sent ones after the
     * redelivery grace if the server has not answered them by then.
     */
    private function replayPending(): void
    {
        $calls = [];
        foreach ($this->pending as $msgId => $call) {
            $calls[spl_object_id($call)] ??= [$msgId, $call];
        }

        $this->awaitingRedelivery = [];
        $this->redelivered = 0;
        foreach ($calls as [, $call]) {
            foreach ($call->ids as $id) {
                $this->awaitingRedelivery[$id] = true;
            }
        }

        $delayed = [];
        foreach ($calls as [$msgId, $call]) {
            if (!$call->sent || $this->redeliveryGrace <= 0) {
                $this->resend(end($call->ids));
            } else {
                $delayed[] = $call;
            }
        }

        if ($delayed === []) {
            return;
        }

        $this->runtime->spawn(function () use ($delayed): void {
            $this->runtime->sleep($this->redeliveryGrace);

            if ($this->redelivered > 0) {
                $this->graceMisses = 0;
            } elseif (++$this->graceMisses >= 2) {
                $this->redeliveryGrace = 0.0;
                $this->logger?->debug('Pump: the server does not redeliver answers after reconnects - replaying immediately from now on');
            }

            foreach ($delayed as $call) {
                $last = end($call->ids);
                if ($this->running && isset($this->pending[$last])) {
                    try {
                        $this->resend($last);
                    } catch (\Throwable $e) {
                        $this->logger?->warning("Pump replay of {$call->method} failed: {$e->getMessage()}");
                    }
                }
            }
        });
    }

    /**
     * Collapse numbers so error texts group together ("FLOOD_WAIT_7" -> "FLOOD_WAIT_X").
     */
    private static function normalise(string $message): string
    {
        return (string) preg_replace(['/_\d+(?=\b|_)/', '/\d+/'], ['_X', 'N'], $message);
    }

}
