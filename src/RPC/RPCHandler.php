<?php

declare(strict_types=1);

namespace LaraGram\MTProto\RPC;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\TransportInterface;
use LaraGram\MTProto\Contracts\CryptoInterface;
use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\TL\TLParser;
use LaraGram\MTProto\TL\TLSerializer;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Exceptions\SecurityException;

/**
 * RPC Handler
 * 
 * Manages MTProto message exchange including:
 * - Message construction and serialization
 * - Encryption/decryption
 * - Message acknowledgement
 * - Error handling
 * - Salt updates
 */
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

    /** @var array<int, PendingMessage> Pending RPC calls */
    private array $pendingMessages = [];

    /** @var array<int> Message IDs to acknowledge */
    private array $pendingAcks = [];

    /**
     * Sliding window of server msg_ids already seen, used to drop replays.
     * Keys are msg_ids; the array is trimmed once it exceeds the cap.
     *
     * @var array<int, true>
     */
    private array $seenMsgIds = [];

    /** @var int Maximum number of server msg_ids retained for replay detection */
    private const SEEN_MSG_ID_LIMIT = 1024;

    /** @var int Current layer (overridden by Client::LAYER; must match parsed schema/types) */
    private int $layer = 214;

    /** @var bool Whether connection has been initialized */
    private bool $initialized = false;

    /** @var int API ID */
    private int $apiId;

    /** @var string API Hash */
    private string $apiHash;

    /**
     * Callback to forward updates received during blocking receiveResponse().
     * Without this, any update arriving while waiting for an RPC response
     * would be silently discarded.
     *
     * @var callable(array): void|null
     */
    private $updateFeedCallback = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TransportInterface $transport,
        private readonly CryptoInterface $crypto,
        private readonly SessionInterface $session,
        private readonly TLParser $parser,
        int $apiId = 0,
        string $apiHash = '',
    ) {
        $this->serializer = new TLSerializer($parser);
        $this->apiId = $apiId;
        $this->apiHash = $apiHash;
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
     * blocking receiveResponse() calls.
     *
     * The UpdateLoop sets this so that updates are not lost when
     * user code calls $client->sendMessage() etc. inside a handler.
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
     * This follows the correct Telegram protocol flow:
     * 1. Send Ping (to establish connection)
     * 2. Send InvokeWithLayer(InitConnection(help.getConfig))
     * 
     * After this, all subsequent calls are sent WITHOUT any wrapper.
     * 
     * @return array help.getConfig response
     */
    public function initializeConnection(): array
    {
        if ($this->initialized) {
            return [];
        }

        // Step 1: Send Ping first (like Pyrogram does)
        $this->ping();

        // Step 2: Send InvokeWithLayer(InitConnection(help.getConfig))
        $getConfig = ['_' => 'help.getConfig'];

        $initConnection = [
            '_' => 'initConnection',
            'flags' => 0,
            'api_id' => $this->apiId,
            'device_model' => php_uname('s') . ' ' . php_uname('r'),
            'system_version' => php_uname('v'),
            'app_version' => '1.0.0',
            'system_lang_code' => 'en',
            'lang_pack' => '',
            'lang_code' => 'en',
            'query' => $getConfig,
        ];

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
            error_log("[MTProto] Retrying initializeConnection after salt update");
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
     * @param string $method Method name (e.g., 'messages.sendMessage')
     * @param array $params Method parameters
     * @param bool $contentRelated Whether this is content-related
     * @return array Response
     */
    public function call(string $method, array $params = [], bool $contentRelated = true): array
    {
        // Auto-initialize if not done yet
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
        // Build the message
        $methodDef = $this->parser->getMethod($method);
        if (!$methodDef) {
            throw new MTProtoException("Unknown method: {$method}");
        }

        $message = array_merge(['_' => $method], $params);
        
        $messageData = $this->serializer->serialize($message);

        // Send encrypted message
        $msgId = $this->sendEncrypted($messageData, $contentRelated);

        // Store pending message
        $this->pendingMessages[$msgId] = new PendingMessage(
            msgId: $msgId,
            method: $method,
            params: $params,
            sentAt: microtime(true),
        );

        // Read response
        $response = $this->receiveResponse($msgId);

        // Handle retry signals (bad_server_salt, etc.)
        if (isset($response['_retry']) && $maxRetries > 0) {
            error_log("[MTProto] Retrying {$method} (retries left: {$maxRetries})");
            return $this->callInternal($method, $params, $contentRelated, $maxRetries - 1);
        }

        return $response;
    }

    /**
     * Invoke a TL method (alias for call).
     * 
     * @param string $method Method name
     * @param array $params Method parameters
     * @return array Response
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

        // Handle retry signals (bad_server_salt, etc.)
        if (isset($response['_retry'])) {
            error_log("[MTProto] Retrying ping after salt update");
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
        $authKey = $this->session->getAuthKey();
        if (!$authKey) {
            throw new SecurityException('No auth key available');
        }

        $msgId = $this->session->generateMessageId();
        $seqNo = $this->session->getSeqNo($contentRelated);

        // Build inner message
        // salt (8) + session_id (8) + msg_id (8) + seq_no (4) + message_len (4) + message_data + padding
        $innerData = $this->session->getServerSalt()
            . $this->session->getSessionId()
            . pack('P', $msgId)
            . pack('V', $seqNo)
            . pack('V', strlen($messageData))
            . $messageData;

        // Add padding (12-1024 bytes, total length divisible by 16)
        $paddingLength = 16 - (strlen($innerData) % 16);
        if ($paddingLength < 12) {
            $paddingLength += 16;
        }
        $innerData .= $this->crypto->randomBytes($paddingLength);

        // Calculate msg_key and encrypt
        $msgKey = $this->crypto->calculateMsgKey($authKey, $innerData, true);
        $kdf = $this->crypto->kdf($authKey, $msgKey, true);
        $encryptedData = $this->crypto->aesIgeEncrypt($innerData, $kdf['aes_key'], $kdf['aes_iv']);

        // Build final message
        $authKeyId = $this->crypto->calculateAuthKeyId($authKey);
        $finalMessage = $authKeyId . $msgKey . $encryptedData;

        // Wrap with transport and send
        $packet = $this->transport->wrap($finalMessage);
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
            // Read packet
            $lengthData = $this->transport->readLength($this->connection);
            $packet = $this->connection->receive($lengthData);
            $data = $this->transport->unwrap($packet);

            // Process the message
            $result = $this->processReceivedData($data);

            // Check if this is the response we're waiting for
            if (isset($result['msg_id']) && $result['msg_id'] === $expectedMsgId) {
                // Check for retry signal (bad_server_salt with updated salt)
                if (isset($result['_retry'])) {
                    return $result;
                }
                unset($this->pendingMessages[$expectedMsgId]);
                return $result['result'] ?? $result;
            }

            // Check for result in container
            if (isset($result['results'])) {
                $found = false;
                foreach ($result['results'] as $r) {
                    if (isset($r['msg_id']) && $r['msg_id'] === $expectedMsgId) {
                        // Re-throw stored exceptions (RPC errors, bad_msg, etc.)
                        if (isset($r['_exception'])) {
                            unset($this->pendingMessages[$expectedMsgId]);
                            throw $r['_exception'];
                        }
                        // Check for retry signal
                        if (isset($r['_retry'])) {
                            return $r;
                        }
                        unset($this->pendingMessages[$expectedMsgId]);
                        $found = $r['result'] ?? $r;
                    } elseif ($this->updateFeedCallback !== null) {
                        // Forward any updates found in the container
                        $inner = $r['result'] ?? $r;
                        $this->forwardIfUpdate($inner);
                    }
                }
                if ($found !== false) {
                    return $found;
                }
            } else {
                // Single message that didn't match — forward if it's an update
                if ($this->updateFeedCallback !== null) {
                    $inner = $result['result'] ?? $result;
                    $this->forwardIfUpdate($inner);
                }
            }

            // Send acks if needed
            $this->sendPendingAcks();
        }

        throw new MTProtoException("Timeout waiting for response to message {$expectedMsgId}");
    }

    /**
     * Process received data
     */
    private function processReceivedData(string $data): array
    {
        // Check if unencrypted (auth_key_id = 0)
        $authKeyId = substr($data, 0, 8);

        if ($authKeyId === str_repeat("\x00", 8)) {
            // Only the auth-key handshake may use unencrypted frames. Once an
            // auth key exists, accepting plaintext frames opens a downgrade/
            // injection vector, so reject them.
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
     * Process encrypted message
     */
    private function processEncrypted(string $data): array
    {
        $authKey = $this->session->getAuthKey();
        if (!$authKey) {
            throw new SecurityException('No auth key for decryption');
        }

        $expectedAuthKeyId = $this->crypto->calculateAuthKeyId($authKey);
        $receivedAuthKeyId = substr($data, 0, 8);

        if ($receivedAuthKeyId !== $expectedAuthKeyId) {
            throw new SecurityException('Auth key ID mismatch');
        }

        $msgKey = substr($data, 8, 16);
        $encryptedData = substr($data, 24);

        // Decrypt
        $kdf = $this->crypto->kdf($authKey, $msgKey, false);
        $decrypted = $this->crypto->aesIgeDecrypt($encryptedData, $kdf['aes_key'], $kdf['aes_iv']);

        // Verify msg_key
        $expectedMsgKey = $this->crypto->calculateMsgKey($authKey, $decrypted, false);
        if ($msgKey !== $expectedMsgKey) {
            throw new SecurityException('Message key verification failed');
        }

        // Parse inner data
        // salt (8) + session_id (8) + msg_id (8) + seq_no (4) + length (4) + data
        $salt = substr($decrypted, 0, 8);
        $sessionId = substr($decrypted, 8, 8);
        $msgId = unpack('P', substr($decrypted, 16, 8))[1];
        $seqNo = unpack('V', substr($decrypted, 24, 4))[1];
        $length = unpack('V', substr($decrypted, 28, 4))[1];

        // Validate the inner length field before trusting it. The plaintext is
        // header(32) + message_data + padding(12..1024); a forged length could
        // otherwise drive an OOB read or padding-oracle-style probing.
        $decryptedLen = strlen($decrypted);
        if ($length < 0 || $length > $decryptedLen - 32) {
            throw new SecurityException('Invalid inner message length');
        }
        $padding = $decryptedLen - 32 - $length;
        if ($padding < 12 || $padding > 1024) {
            throw new SecurityException('Invalid padding length');
        }

        $messageData = substr($decrypted, 32, $length);

        // Verify session — if session was regenerated, old replies may arrive
        if ($sessionId !== $this->session->getSessionId()) {
            error_log('[MTProto] Ignoring message with stale session ID');
            return ['_' => 'stale_session'];
        }

        // Validate the server msg_id: must be odd (server messages are 1 or 3
        // mod 4), within the allowed time window, and not a replay. Per spec a
        // failing frame is *dropped* (not fatal) — throwing here would abort the
        // RPC currently waiting in receiveResponse(), so we return a benign
        // marker and let the read loop continue to the real response.
        $reason = $this->checkServerMsgId($msgId);
        if ($reason !== null) {
            error_log("[MTProto] Dropping server message {$msgId}: {$reason}");
            return ['_' => 'dropped_message'];
        }

        // Add to pending acks
        $this->pendingAcks[] = $msgId;

        // Parse and handle the message
        return $this->handleMessage($msgId, $messageData);
    }

    /**
     * Check an incoming server msg_id and record it for replay detection.
     *
     * Server message identifiers are odd (1 or 3 mod 4). The high 32 bits are a
     * unix timestamp; messages too far from the (delta-adjusted) local clock are
     * rejected, and duplicates are dropped via a sliding window.
     *
     * @return string|null  null if the message is acceptable, otherwise a short
     *                       reason the caller should log before dropping it.
     */
    private function checkServerMsgId(int $msgId): ?string
    {
        // Server msg_ids must be odd; even ids only originate from the client.
        if (($msgId & 1) === 0) {
            return 'msg_id is not odd';
        }

        // High 32 bits encode the server's unix time (msg_id = time << 32 + ...).
        $msgTime = ($msgId >> 32) & 0xFFFFFFFF;
        $now = time() + $this->session->getTimeDelta();

        // Per spec: reject ids more than 30s in the future or 300s in the past.
        if ($msgTime > $now + 30 || $msgTime < $now - 300) {
            return 'timestamp out of window (drift ' . ($msgTime - $now) . 's)';
        }

        // Replay protection: a msg_id already processed is a duplicate.
        if (isset($this->seenMsgIds[$msgId])) {
            return 'duplicate msg_id (replay)';
        }

        $this->seenMsgIds[$msgId] = true;

        // Trim the window. Keys are monotonically increasing msg_ids, so drop
        // the oldest (smallest) ones once over the cap.
        if (count($this->seenMsgIds) > self::SEEN_MSG_ID_LIMIT) {
            ksort($this->seenMsgIds);
            $this->seenMsgIds = array_slice($this->seenMsgIds, -self::SEEN_MSG_ID_LIMIT, null, true);
        }

        return null;
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
            error_log(sprintf(
                '[MTProto] Failed to deserialize message 0x%08x: %s',
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
        $packedData = $this->readTLBytes($data, 4);
        $unpacked = $this->gzdecodeChecked($packedData);

        return $this->handleMessage($msgId, $unpacked);
    }

    /** @var int Max allowed size of gzip-decompressed payloads (16 MiB) to guard against zip bombs */
    private const MAX_GZIP_OUTPUT = 16 * 1024 * 1024;

    /**
     * Decompress a gzip_packed payload, guarding against corrupt data and
     * decompression bombs. gzdecode() returns false on bad input, which would
     * otherwise reach handleMessage() and trigger a type error.
     */
    private function gzdecodeChecked(string $packedData): string
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
     * Read a TL-encoded bytes/string from binary data at given offset.
     * Returns the raw bytes value.
     */
    private function readTLBytes(string $data, int $offset): string
    {
        $firstByte = ord($data[$offset]);
        $offset++;

        if ($firstByte === 254) {
            // Long string: 3 bytes length
            $lengthBytes = substr($data, $offset, 3) . "\x00";
            $length = unpack('V', $lengthBytes)[1];
            $offset += 3;
        } else {
            $length = $firstByte;
        }

        return substr($data, $offset, $length);
    }

    /**
     * Handle message container
     * 
     * msg_container#73f1f8dc messages:vector<%Message> = MessageContainer;
     * message msg_id:long seqno:int bytes:int body:Object = Message;
     */
    private function handleContainer(string $data): array
    {
        // Skip constructor ID (4 bytes)
        $offset = 4;
        
        // Read message count
        $count = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        
        $results = [];
        $dataLen = strlen($data);
        
        for ($i = 0; $i < $count; $i++) {
            // Read message: msg_id (8) + seqno (4) + bytes (4) + body
            if ($offset + 16 > $dataLen) {
                // Not enough data for header
                break;
            }
            
            $msgId = unpack('P', substr($data, $offset, 8))[1];
            $offset += 8;
            
            $seqNo = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            
            $bodyLen = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            
            if ($offset + $bodyLen > $dataLen) {
                // Body would exceed data length - truncate
                $bodyLen = $dataLen - $offset;
            }
            
            $body = substr($data, $offset, $bodyLen);
            $offset += $bodyLen;
            
            $this->pendingAcks[] = $msgId;
            try {
                $results[] = $this->handleMessage($msgId, $body);
            } catch (MTProtoException $e) {
                // RPC errors and bad_msg must propagate — store as exception for receiveResponse
                $results[] = [
                    'msg_id' => $msgId,
                    '_exception' => $e,
                ];
            } catch (\Throwable $e) {
                // Only catch deserialization/parsing errors gracefully
                error_log(sprintf(
                    '[MTProto] Failed to handle container message %d: %s',
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

        // Check for gzip
        if ($constructorId === self::GZIP_PACKED) {
            $packedData = $this->readTLBytes($resultData, 4);
            $resultData = $this->gzdecodeChecked($packedData);
            $constructorId = unpack('V', substr($resultData, 0, 4))[1];
        }

        // Check for RPC error
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
            // Remove from pending if exists
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

        // Update server salt if needed
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

        // For incorrect server salt: salt already updated above, allow retry
        if ($errorCode === 48) {
            error_log("[MTProto] Bad message: {$errorMsg} — salt updated, will retry");
            return [
                '_' => 'bad_server_salt',
                'msg_id' => $badMsg['bad_msg_id'] ?? 0,
                'bad_msg_id' => $badMsg['bad_msg_id'] ?? 0,
                'error_code' => $errorCode,
                '_retry' => true,
            ];
        }

        // For seqno errors: reset session to fix the counter
        if ($errorCode >= 32 && $errorCode <= 35) {
            error_log("[MTProto] Bad message: {$errorMsg} — regenerating session");
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
        
        // Update server salt
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

        // Take up to 8192 acks
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
            'updates'              => true,
            'updatesCombined'      => true,
            'updateShort'          => true,
            'updateShortMessage'   => true,
            'updateShortChatMessage' => true,
            'updateShortSentMessage' => true,
            'updatesTooLong'       => true,
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
        public readonly int $msgId,
        public readonly string $method,
        public readonly array $params,
        public readonly float $sentAt,
        public int $retries = 0,
    ) {}
}
