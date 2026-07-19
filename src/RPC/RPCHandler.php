<?php

declare(strict_types=1);

namespace LaraGram\MTProto\RPC;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\Log\LoggerInterface;
use LaraGram\MTProto\TL\TLParser;
use LaraGram\MTProto\TL\TLSerializer;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Exceptions\SecurityException;

final class RPCHandler
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

    private TLSerializer $serializer;

    /** Shared frame crypto. */
    private FrameCodec $codec;

    /** @var array<int, PendingMessage> Pending RPC calls */
    private array $pendingMessages = [];

    /** @var array<int> Message IDs to acknowledge */
    private array $pendingAcks = [];

    /** @var int Current layer (overridden by Client::LAYER; must match parsed schema/types) */
    private int $layer = 228;

    /** @var bool Whether connection has been initialized */
    private bool $initialized = false;

    /** @var int API ID */
    private int $apiId;

    /** @var string API Hash */
    private string $apiHash;

    /** @var \LaraGram\MTProto\Core\DeviceProfile|null Device fingerprint sent at init. */
    private ?\LaraGram\MTProto\Core\DeviceProfile $deviceProfile = null;

    public function setDeviceProfile(\LaraGram\MTProto\Core\DeviceProfile $profile): void
    {
        $this->deviceProfile = $profile;
    }

    /**
     * Callback to forward updates received during blocking receiveResponse().
     * Without this, any update arriving while waiting for an RPC response
     * would be silently discarded.
     *
     * @var callable(array): void|null
     */
    private $updateFeedCallback = null;

    private ?LoggerInterface $logger;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TransportInterface  $transport,
        private readonly CryptoInterface     $crypto,
        private readonly SessionInterface    $session,
        private readonly TLParser            $parser,
        int                                  $apiId = 0,
        string                               $apiHash = '',
        ?LoggerInterface                     $logger = null,
    )
    {
        $this->serializer = new TLSerializer($parser);
        $this->codec = new FrameCodec($crypto, $session, $transport, $logger);
        $this->apiId = $apiId;
        $this->apiHash = $apiHash;
        $this->logger = $logger;
    }

    /**
     * Set API credentials.
     */
    public function setApiCredentials(int $apiId, string $apiHash): void
    {
        $this->apiId = $apiId;
        $this->apiHash = $apiHash;
    }

    /**
     * Register a callback to receive updates that arrive during
     * blocking receiveResponse() calls, so they are not lost when user code
     * calls $client->sendMessage() etc. inside a handler. The pump path
     * supersedes this; on the sync path it stays null (auth/login emits no
     * updates) and the forwarding below is inert.
     *
     * @param callable(array): void $callback
     */
    public function setUpdateFeedCallback(callable $callback): void
    {
        $this->updateFeedCallback = $callback;
    }

    /**
     * Initialize the connection - MUST be called before any API calls.
     *
     * @return array help.getConfig response
     */
    public function initializeConnection(): array
    {
        if ($this->initialized) {
            return [];
        }

        $this->ping();

        $getConfig = ['_' => 'help.getConfig'];

        $device = $this->deviceProfile ??= \LaraGram\MTProto\Core\DeviceProfile::preset(
            \LaraGram\MTProto\Core\DeviceProfile::DEFAULT_PRESET
        );

        $initConnection = array_merge([
            '_' => 'initConnection',
            'flags' => 0,
            'api_id' => $this->apiId,
        ], $device->toInitConnection(), [
            'query' => $getConfig,
        ]);

        $wrapped = [
            '_' => 'invokeWithLayer',
            'layer' => $this->layer,
            'query' => $initConnection,
        ];

        $messageData = $this->serializer->serialize($wrapped);
        $msgId = $this->sendEncrypted($messageData, true);

        $this->pendingMessages[$msgId] = new PendingMessage(
            msgId: $msgId,
            method: 'help.getConfig',
            params: [],
            sentAt: microtime(true),
        );

        $result = $this->receiveResponse($msgId);

        // Handle retry signals (bad_server_salt, etc.)
        if (isset($result['_retry'])) {
            $this->logger?->debug('Retrying initializeConnection after salt update');
            $msgId = $this->sendEncrypted($messageData, true);
            $this->pendingMessages[$msgId] = new PendingMessage(
                msgId: $msgId,
                method: 'help.getConfig',
                params: [],
                sentAt: microtime(true),
            );
            $result = $this->receiveResponse($msgId);
        }

        $this->initialized = true;

        return $result;
    }

    /**
     * Send an RPC call and wait for response.
     *
     * Connection MUST be initialized first via initializeConnection().
     * All calls are sent directly without any wrapper.
     *
     * @param string $method
     * @param array $params
     * @param bool $contentRelated
     * @return array Response
     */
    public function call(string $method, array $params = [], bool $contentRelated = true): array
    {
        if (!$this->initialized) {
            $this->initializeConnection();
        }

        return $this->callInternal($method, $params, $contentRelated, maxRetries: 2);
    }

    /**
     * Internal call with retry support for recoverable errors (bad_server_salt, etc.)
     */
    private function callInternal(string $method, array $params, bool $contentRelated, int $maxRetries): array
    {
        $methodDef = $this->parser->getMethod($method);
        if (!$methodDef) {
            throw new MTProtoException("Unknown method: {$method}");
        }

        $message = array_merge(['_' => $method], $params);

        $messageData = $this->serializer->serialize($message);

        $msgId = $this->sendEncrypted($messageData, $contentRelated);

        $this->pendingMessages[$msgId] = new PendingMessage(
            msgId: $msgId,
            method: $method,
            params: $params,
            sentAt: microtime(true),
        );

        $response = $this->receiveResponse($msgId);

        if (isset($response['_retry']) && $maxRetries > 0) {
            $this->logger?->debug("Retrying {$method} (retries left: {$maxRetries})");
            return $this->callInternal($method, $params, $contentRelated, $maxRetries - 1);
        }

        return $response;
    }

    /**
     * Invoke a TL method (alias for call).
     *
     * @param string $method
     * @param array $params
     * @return array
     */
    public function invoke(string $method, array $params = []): array
    {
        return $this->call($method, $params);
    }

    /**
     * Send ping and wait for pong
     */
    public function ping(): int
    {
        $pingId = random_int(PHP_INT_MIN, PHP_INT_MAX);

        $message = [
            '_' => 'ping',
            'ping_id' => $pingId,
        ];

        $messageData = $this->serializer->serialize($message);
        $msgId = $this->sendEncrypted($messageData, false);

        $response = $this->receiveResponse($msgId);

        if (isset($response['_retry'])) {
            $this->logger?->debug('Retrying ping after salt update');
            $msgId = $this->sendEncrypted($messageData, false);
            $response = $this->receiveResponse($msgId);
        }

        if (($response['_'] ?? '') !== 'pong') {
            throw new MTProtoException('Expected pong response');
        }

        return $response['ping_id'];
    }

    /**
     * Get future salts
     */
    public function getFutureSalts(int $num = 64): array
    {
        $message = [
            '_' => 'get_future_salts',
            'num' => $num,
        ];

        $messageData = $this->serializer->serialize($message);
        $msgId = $this->sendEncrypted($messageData, true);

        return $this->receiveResponse($msgId);
    }

    /**
     * Send encrypted message
     */
    public function sendEncrypted(string $messageData, bool $contentRelated): int
    {
        [$msgId, $packet] = $this->codec->encrypt($messageData, $contentRelated);
        $this->connection->send($packet);

        return $msgId;
    }

    /**
     * Receive and process response
     */
    public function receiveResponse(int $expectedMsgId, float $timeout = 30.0): array
    {
        $startTime = microtime(true);

        while (microtime(true) - $startTime < $timeout) {
            try {
                $lengthData = $this->transport->readLength($this->connection);
            } catch (\LaraGram\MTProto\Exceptions\ReadTimeoutException) {
                continue; // idle link on a frame boundary - keep waiting out the deadline
            }
            $packet = $this->connection->receive($lengthData);
            if ($packet === null) {
                try {
                    $this->connection->disconnect();
                } catch (\Throwable) {
                }
                throw new MTProtoException("Timed out mid-frame ({$lengthData} bytes expected)");
            }
            $data = $this->transport->unwrap($packet);

            $result = $this->processReceivedData($data);

            if (isset($result['msg_id']) && $result['msg_id'] === $expectedMsgId) {
                if (isset($result['_retry'])) {
                    return $result;
                }
                unset($this->pendingMessages[$expectedMsgId]);
                return $result['result'] ?? $result;
            }

            if (isset($result['results'])) {
                $found = false;
                foreach ($result['results'] as $r) {
                    if (isset($r['msg_id']) && $r['msg_id'] === $expectedMsgId) {
                        if (isset($r['_exception'])) {
                            unset($this->pendingMessages[$expectedMsgId]);
                            throw $r['_exception'];
                        }

                        if (isset($r['_retry'])) {
                            return $r;
                        }
                        unset($this->pendingMessages[$expectedMsgId]);
                        $found = $r['result'] ?? $r;
                    } elseif ($this->updateFeedCallback !== null) {
                        $inner = $r['result'] ?? $r;
                        $this->forwardIfUpdate($inner);
                    }
                }
                if ($found !== false) {
                    return $found;
                }
            } else {
                if ($this->updateFeedCallback !== null) {
                    $inner = $result['result'] ?? $result;
                    $this->forwardIfUpdate($inner);
                }
            }

            $this->sendPendingAcks();
        }

        throw new MTProtoException("Timeout waiting for response to message {$expectedMsgId}");
    }

    /**
     * Process received data
     */
    private function processReceivedData(string $data): array
    {
        $authKeyId = substr($data, 0, 8);

        if ($authKeyId === str_repeat("\x00", 8)) {
            if ($this->session->getAuthKey() !== null) {
                throw new SecurityException('Unencrypted message received after handshake');
            }

            return $this->processUnencrypted($data);
        }

        return $this->processEncrypted($data);
    }

    /**
     * Process unencrypted message
     */
    private function processUnencrypted(string $data): array
    {
        // auth_key_id (8) + msg_id (8) + length (4) + data
        $msgId = unpack('P', substr($data, 8, 8))[1];
        $length = unpack('V', substr($data, 16, 4))[1];
        $messageData = substr($data, 20, $length);

        return [
            'msg_id' => $msgId,
            'result' => $this->serializer->deserialize($messageData),
        ];
    }

    /**
     * Process encrypted message.
     */
    private function processEncrypted(string $data): array
    {
        [$msgId, $messageData] = $this->codec->decrypt($data);

        if ($messageData === null) {
            return ['_' => 'dropped_message'];
        }

        $this->pendingAcks[] = $msgId;

        return $this->handleMessage($msgId, $messageData);
    }

    /**
     * Handle a deserialized message
     */
    private function handleMessage(int $msgId, string $messageData): array
    {
        $constructorId = unpack('V', substr($messageData, 0, 4))[1];

        return match ($constructorId) {
            self::GZIP_PACKED => $this->handleGzipPacked($msgId, $messageData),
            self::MSG_CONTAINER => $this->handleContainer($messageData),
            self::RPC_RESULT => $this->handleRpcResult($messageData),
            self::MSGS_ACK => $this->handleMsgsAck($messageData),
            self::BAD_MSG_NOTIFICATION, self::BAD_SERVER_SALT => $this->handleBadMsg($messageData),
            self::NEW_SESSION_CREATED => $this->handleNewSession($messageData),
            self::PONG => $this->handlePong($messageData),
            self::FUTURE_SALTS => $this->handleFutureSalts($messageData),
            default => $this->handleDefault($msgId, $messageData),
        };
    }

    /**
     * Handle default (non-special) messages with graceful error recovery
     */
    private function handleDefault(int $msgId, string $messageData): array
    {
        try {
            return [
                'msg_id' => $msgId,
                'result' => $this->serializer->deserialize($messageData),
            ];
        } catch (\Throwable $e) {
            $constructorId = unpack('V', substr($messageData, 0, 4))[1];
            $this->logger?->error(sprintf(
                'Failed to deserialize message 0x%08x: %s',
                $constructorId,
                $e->getMessage()
            ));
            return [
                'msg_id' => $msgId,
                'result' => ['_' => 'unknown', '_error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Handle gzip packed message
     * gzip_packed#3072cfa1 packed_data:bytes = Object;
     */
    private function handleGzipPacked(int $msgId, string $data): array
    {
        // Skip constructor ID (4 bytes), then read TL bytes (packed_data)
        $packedData = FrameCodec::readTLBytes($data, 4);
        $unpacked = FrameCodec::gunzip($packedData);

        return $this->handleMessage($msgId, $unpacked);
    }

    /**
     * Handle message container
     *
     * msg_container#73f1f8dc messages:vector<%Message> = MessageContainer;
     * message msg_id:long seqno:int bytes:int body:Object = Message;
     */
    private function handleContainer(string $data): array
    {
        $offset = 4;

        $count = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        $results = [];
        $dataLen = strlen($data);

        for ($i = 0; $i < $count; $i++) {
            // Read message: msg_id (8) + seqno (4) + bytes (4) + body
            if ($offset + 16 > $dataLen) {
                break;
            }

            $msgId = unpack('P', substr($data, $offset, 8))[1];
            $offset += 8;

            $seqNo = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            $bodyLen = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if ($offset + $bodyLen > $dataLen) {
                $bodyLen = $dataLen - $offset;
            }

            $body = substr($data, $offset, $bodyLen);
            $offset += $bodyLen;

            $this->pendingAcks[] = $msgId;
            try {
                $results[] = $this->handleMessage($msgId, $body);
            } catch (MTProtoException $e) {
                $results[] = [
                    'msg_id' => $msgId,
                    '_exception' => $e,
                ];
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf(
                    'Failed to handle container message %d: %s',
                    $msgId,
                    $e->getMessage()
                ));
                $results[] = [
                    'msg_id' => $msgId,
                    'result' => ['_' => 'unknown', '_error' => $e->getMessage()],
                ];
            }
        }

        return ['results' => $results];
    }

    /**
     * Handle RPC result
     */
    private function handleRpcResult(string $data): array
    {
        // rpc_result#f35c6d01 req_msg_id:long result:Object
        // Skip constructor (4) + req_msg_id (8)
        $reqMsgId = unpack('P', substr($data, 4, 8))[1];
        $resultData = substr($data, 12);

        $constructorId = unpack('V', substr($resultData, 0, 4))[1];

        if ($constructorId === self::GZIP_PACKED) {
            $packedData = FrameCodec::readTLBytes($resultData, 4);
            $resultData = FrameCodec::gunzip($packedData);
            $constructorId = unpack('V', substr($resultData, 0, 4))[1];
        }

        if ($constructorId === self::RPC_ERROR) {
            $error = $this->serializer->deserialize($resultData);
            throw new MTProtoException(
                "RPC Error {$error['error_code']}: {$error['error_message']}",
                $error['error_code']
            );
        }

        return [
            'msg_id' => $reqMsgId,
            'result' => $this->serializer->deserialize($resultData),
        ];
    }

    /**
     * Handle message acknowledgement
     */
    private function handleMsgsAck(string $data): array
    {
        $ack = $this->serializer->deserialize($data);

        foreach ($ack['msg_ids'] ?? [] as $msgId) {
            unset($this->pendingMessages[$msgId]);
        }

        return ['_' => 'msgs_ack', 'msg_ids' => $ack['msg_ids'] ?? []];
    }

    /**
     * Handle bad message notification
     */
    private function handleBadMsg(string $data): array
    {
        $badMsg = $this->serializer->deserialize($data);
        $errorCode = $badMsg['error_code'];

        if (isset($badMsg['new_server_salt'])) {
            $saltBytes = pack('P', $badMsg['new_server_salt']);
            $this->session->setServerSalt($saltBytes);
        }

        $errorMessages = [
            16 => 'msg_id too low',
            17 => 'msg_id too high',
            18 => 'incorrect two lower order msg_id bits',
            19 => 'container msg_id is the same as msg_id of a previously received message',
            20 => 'message too old',
            32 => 'msg_seqno too low',
            33 => 'msg_seqno too high',
            34 => 'even msg_seqno expected, but odd received',
            35 => 'odd msg_seqno expected, but even received',
            48 => 'incorrect server salt',
            64 => 'invalid container',
        ];

        $errorMsg = $errorMessages[$errorCode] ?? "code {$errorCode}";

        if ($errorCode === 48) {
            $this->logger?->warning("Bad message: {$errorMsg} - salt updated, will retry");
            return [
                '_' => 'bad_server_salt',
                'msg_id' => $badMsg['bad_msg_id'] ?? 0,
                'bad_msg_id' => $badMsg['bad_msg_id'] ?? 0,
                'error_code' => $errorCode,
                '_retry' => true,
            ];
        }

        if ($errorCode >= 32 && $errorCode <= 35) {
            $this->logger?->warning("Bad message: {$errorMsg} - regenerating session");
            $this->session->regenerateSessionId();
            $this->initialized = false;
        }

        throw new MTProtoException(
            "Bad message notification: {$errorMsg}",
            $errorCode
        );
    }

    /**
     * Handle new session created
     */
    private function handleNewSession(string $data): array
    {
        $session = $this->serializer->deserialize($data);

        if (isset($session['server_salt'])) {
            $saltBytes = pack('P', $session['server_salt']);
            $this->session->setServerSalt($saltBytes);
        }

        return $session;
    }

    /**
     * Handle pong
     */
    private function handlePong(string $data): array
    {
        return $this->serializer->deserialize($data);
    }

    /**
     * Handle future salts
     */
    private function handleFutureSalts(string $data): array
    {
        return $this->serializer->deserialize($data);
    }

    /**
     * Send pending acknowledgements
     */
    private function sendPendingAcks(): void
    {
        if (empty($this->pendingAcks)) {
            return;
        }

        $acks = array_splice($this->pendingAcks, 0, 8192);

        $message = [
            '_' => 'msgs_ack',
            'msg_ids' => $acks,
        ];

        $messageData = $this->serializer->serialize($message);
        $this->sendEncrypted($messageData, false);
    }

    /**
     * Check if a deserialized result looks like a Telegram update
     * container and, if so, forward it to the registered feed callback
     * so it is not lost.
     */
    private function forwardIfUpdate(array $data): void
    {
        $constructor = $data['_'] ?? '';

        static $updateTypes = [
            'updates' => true,
            'updatesCombined' => true,
            'updateShort' => true,
            'updateShortMessage' => true,
            'updateShortChatMessage' => true,
            'updateShortSentMessage' => true,
            'updatesTooLong' => true,
        ];

        if (isset($updateTypes[$constructor]) && $this->updateFeedCallback !== null) {
            ($this->updateFeedCallback)($data);
        }
    }

    /**
     * Set API layer
     */
    public function setLayer(int $layer): void
    {
        $this->layer = $layer;
    }

    /**
     * Get API layer
     */
    public function getLayer(): int
    {
        return $this->layer;
    }
}

/**
 * Represents a pending RPC message
 */
final class PendingMessage
{
    public function __construct(
        public readonly int    $msgId,
        public readonly string $method,
        public readonly array  $params,
        public readonly float  $sentAt,
        public int             $retries = 0,
    )
    {
    }
}
