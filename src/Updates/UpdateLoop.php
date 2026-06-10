<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Driver\Sync\SyncEventLoop;
use LaraGram\MTProto\Generated\Types\Message;
use LaraGram\MTProto\Generated\Types\Update;
use LaraGram\MTProto\TL\TLObject;

/**
 * High-level update loop — keeps the MTProto connection alive and feeds
 * incoming updates to the UpdateFeed for processing + dispatching.
 *
 * Usage:
 *   $loop = new UpdateLoop($client);
 *   $loop->onUpdate(function (TLObject $update, string $type) {
 *       echo $update->message->text;
 *   });
 *   $loop->on('message', function (TLObject $message) {
 *       echo $message->text;
 *   });
 *   $loop->run();  // blocks forever
 *
 * The loop automatically:
 *   - Initialises update state from the server
 *   - Reads pushed updates from the persistent TCP connection
 *   - Sends periodic pings to keep the connection alive
 *   - Detects gaps and fetches difference
 *   - Wraps every update in TLObject for property-based access
 */
class UpdateLoop
{
    private Client            $client;
    private UpdateFeed        $feed;
    private UpdateState       $state;
    private EventLoopInterface $eventLoop;

    /** Ping interval (seconds). Telegram closes idle connections after ~60s. */
    private float $pingInterval = 25.0;

    /** State auto-save interval (seconds). */
    private float $saveInterval = 30.0;

    /** Whether the loop is running. */
    private bool $running = false;

    /**
     * @param Client              $client     Connected MTProto client.
     * @param EventLoopInterface|null $eventLoop  Event loop driver (defaults to SyncEventLoop).
     * @param array               $options    {
     *     @type float $ping_interval  Seconds between pings (default 25).
     *     @type float $save_interval  Seconds between state saves (default 30).
     *     @type float $poll_timeout   Socket poll timeout in sync mode (default 0.5).
     * }
     */
    public function __construct(Client $client, ?EventLoopInterface $eventLoop = null, array $options = [])
    {
        $this->client = $client;

        $this->pingInterval = $options['ping_interval'] ?? 25.0;
        $this->saveInterval = $options['save_interval'] ?? 30.0;
        $pollTimeout        = $options['poll_timeout']  ?? 0.5;

        // Initialise state (persistent alongside session)
        $this->state = new UpdateState(
            $client->getSessionDir(),
            $client->getSessionName(),
        );

        $this->feed = new UpdateFeed($client, $this->state);
        $this->eventLoop = $eventLoop ?? new SyncEventLoop($pollTimeout);
        $this->feed->setEventLoop($this->eventLoop);
    }

    // ════════════════════════════════════════════════════════════════════
    //  Public API — mirror UpdateFeed's handler registration
    // ════════════════════════════════════════════════════════════════════

    /**
     * Set the main update handler.
     *
     * The callback receives every individual update as a TLObject:
     *   function(TLObject $update, string $type): void
     *
     * Example:
     *   $loop->onUpdate(function (TLObject $update, string $type) {
     *       if ($type === 'updateNewMessage') {
     *           echo $update->message->text . "\n";
     *       }
     *   });
     */
    public function onUpdate(callable $callback): self
    {
        $this->feed->setUpdateHandler($callback);
        return $this;
    }

    /**
     * Listen for a specific event.
     *
     * Built-in semantic events:
     *   'message'        — new message (user/group/channel)
     *   'editedMessage'  — edited message
     *   'deletedMessages'— deleted messages
     *   'callbackQuery'  — inline button callback
     *   'inlineQuery'    — inline query
     *   'typing'         — user is typing
     *   'readHistory'    — messages were read
     *   'reactions'      — message reactions changed
     *   'userStatus'     — user online/offline status
     *   'chatParticipant'— chat/channel member change
     *   '*'              — wildcard: every update
     *
     * Raw constructor names are also supported:
     *   'updateNewMessage', 'updateNewChannelMessage', etc.
     *
     * Callback signature: function(TLObject $data, Client $client): void
     */
    public function on(string $event, callable $callback): self
    {
        $this->feed->on($event, $callback);
        return $this;
    }

    /**
     * Remove listener(s).
     */
    public function off(string $event, ?callable $callback = null): self
    {
        $this->feed->off($event, $callback);
        return $this;
    }

    // ── Typed convenience listeners ────────────────────────────────────

    /**
     * Listen for new messages (user/group/channel).
     *
     * @param callable(Message, Client): void $callback
     */
    public function onMessage(callable $callback): self
    {
        $this->feed->onMessage($callback);
        return $this;
    }

    /**
     * Listen for edited messages.
     *
     * @param callable(Message, Client): void $callback
     */
    public function onEditedMessage(callable $callback): self
    {
        $this->feed->onEditedMessage($callback);
        return $this;
    }

    /**
     * Listen for deleted messages.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onDeletedMessages(callable $callback): self
    {
        $this->feed->onDeletedMessages($callback);
        return $this;
    }

    /**
     * Listen for inline button callback queries.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onCallbackQuery(callable $callback): self
    {
        $this->feed->onCallbackQuery($callback);
        return $this;
    }

    /**
     * Listen for inline bot queries.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onInlineQuery(callable $callback): self
    {
        $this->feed->onInlineQuery($callback);
        return $this;
    }

    /**
     * Listen for typing indicators.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onTyping(callable $callback): self
    {
        $this->feed->onTyping($callback);
        return $this;
    }

    /**
     * Listen for read history events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onReadHistory(callable $callback): self
    {
        $this->feed->onReadHistory($callback);
        return $this;
    }

    /**
     * Listen for message reaction changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onReactions(callable $callback): self
    {
        $this->feed->onReactions($callback);
        return $this;
    }

    /**
     * Listen for user online/offline status changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onUserStatus(callable $callback): self
    {
        $this->feed->onUserStatus($callback);
        return $this;
    }

    /**
     * Listen for chat/channel participant changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChatParticipant(callable $callback): self
    {
        $this->feed->onChatParticipant($callback);
        return $this;
    }

    /**
     * Listen for chosen inline results.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChosenInlineResult(callable $callback): self
    {
        $this->feed->onChosenInlineResult($callback);
        return $this;
    }

    /**
     * Listen for pre-checkout queries (payments).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPrecheckoutQuery(callable $callback): self
    {
        $this->feed->onPrecheckoutQuery($callback);
        return $this;
    }

    /**
     * Listen for shipping queries (payments).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onShippingQuery(callable $callback): self
    {
        $this->feed->onShippingQuery($callback);
        return $this;
    }

    /**
     * Get the UpdateFeed for advanced access.
     */
    public function getFeed(): UpdateFeed
    {
        return $this->feed;
    }

    /**
     * Get the UpdateState.
     */
    public function getState(): UpdateState
    {
        return $this->state;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Run / Stop
    // ════════════════════════════════════════════════════════════════════

    /**
     * Start the update loop. Blocks until stop() is called.
     */
    public function run(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        // 1. Initialise update state from server
        $this->feed->initialise();

        // 2. Wire up the RPC handler to forward updates received
        //    during blocking receiveResponse() calls (e.g. when user
        //    code calls $client->sendMessage() inside a handler).
        $rpc = $this->client->getRpcHandler();
        if ($rpc !== null) {
            $rpc->setUpdateFeedCallback(function (array $data): void {
                $this->feed->feed($data);
            });
        }

        // 3. Register socket reader
        $this->eventLoop->onReadable(
            $this->client->getConnection(),
            fn(ConnectionInterface $conn) => $this->onSocketData($conn),
        );

        // 4. Periodic ping to keep connection alive
        $this->eventLoop->addTimer($this->pingInterval, function () {
            $this->sendPing();
        });

        // 5. Periodic state save
        $this->eventLoop->addTimer($this->saveInterval, function () {
            $this->state->save();
        });

        echo "[UpdateLoop] Listening for updates…\n";

        // 6. Run the event loop (blocks)
        $this->eventLoop->run();

        $this->running = false;
    }

    /**
     * Stop the update loop.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->eventLoop->stop();
        $this->state->save();
    }

    /**
     * Whether the loop is currently running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Internals
    // ════════════════════════════════════════════════════════════════════

    /**
     * Called when the socket has data available. Reads one MTProto message
     * and feeds it to the UpdateFeed.
     */
    private function onSocketData(ConnectionInterface $connection): void
    {
        try {
            $transport = $this->client->getTransport();

            // Read length prefix
            $length = $transport->readLength($connection);
            $packet = $connection->receive($length);

            if ($packet === null) {
                return;
            }

            $data = $transport->unwrap($packet);

            // Decrypt
            $decrypted = $this->decryptMessage($data);
            if ($decrypted === null) {
                return;
            }

            // Check if this is an update (not an RPC response)
            $constructor = $decrypted['_'] ?? '';

            // Updates containers
            if (in_array($constructor, [
                'updates', 'updatesCombined', 'updateShort',
                'updateShortMessage', 'updateShortChatMessage',
                'updateShortSentMessage', 'updatesTooLong',
            ], true)) {
                $this->feed->feed($decrypted);
                return;
            }

            // Service messages (new_session_created, pong, etc.) — handle silently
            match ($constructor) {
                'new_session_created' => $this->handleNewSession($decrypted),
                'pong'                => null, // Expected response to our pings
                'msgs_ack'            => null, // Acknowledgements — ignore
                default               => null, // Other messages during loop — ignore
            };
        } catch (\Throwable $e) {
            error_log("[UpdateLoop] Socket read error: {$e->getMessage()}");

            // If connection dropped, try to reconnect
            if (!$connection->isConnected()) {
                $this->handleDisconnect();
            }
        }
    }

    /**
     * Decrypt an incoming MTProto message.
     *
     * @return array|null  Deserialized TL object or null on failure.
     */
    private function decryptMessage(string $data): ?array
    {
        $authKeyId = substr($data, 0, 8);

        // Unencrypted message (shouldn't happen during updates, but handle gracefully)
        if ($authKeyId === str_repeat("\x00", 8)) {
            return null;
        }

        $authKey = $this->client->getSession()->getAuthKey();
        if ($authKey === null) {
            return null;
        }

        $crypto = $this->client->getCrypto();

        // Verify auth key ID
        $expectedKeyId = $crypto->calculateAuthKeyId($authKey);
        if (substr($data, 0, 8) !== $expectedKeyId) {
            return null;
        }

        $msgKey       = substr($data, 8, 16);
        $encryptedData = substr($data, 24);

        // Decrypt
        $kdf       = $crypto->kdf($authKey, $msgKey, false);
        $decrypted = $crypto->aesIgeDecrypt($encryptedData, $kdf['aes_key'], $kdf['aes_iv']);

        // Verify msg_key
        $expectedMsgKey = $crypto->calculateMsgKey($authKey, $decrypted, false);
        if ($msgKey !== $expectedMsgKey) {
            error_log('[UpdateLoop] Message key verification failed');
            return null;
        }

        // Parse inner: salt(8) + session_id(8) + msg_id(8) + seq_no(4) + length(4) + data
        $length     = unpack('V', substr($decrypted, 28, 4))[1];
        $messageData = substr($decrypted, 32, $length);

        // Deserialize
        try {
            return $this->deserializeMessage($messageData);
        } catch (\Throwable $e) {
            error_log("[UpdateLoop] Deserialize error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Deserialize a TL message body, handling containers and gzip.
     */
    private function deserializeMessage(string $data): ?array
    {
        $constructorId = unpack('V', substr($data, 0, 4))[1];

        // gzip_packed — decompress and re-parse
        if ($constructorId === 0x3072cfa1) {
            $packed = $this->readTLBytes($data, 4);
            $data   = gzdecode($packed);
            return $this->deserializeMessage($data);
        }

        // msg_container — process inner messages, return updates only
        if ($constructorId === 0x73f1f8dc) {
            return $this->deserializeContainer($data);
        }

        // Use TLParser for all other constructors
        $tlParser = $this->client->getTlParser();
        if ($tlParser === null) {
            return null;
        }

        $serializer = new \LaraGram\MTProto\TL\TLSerializer($tlParser);
        return $serializer->deserialize($data);
    }

    /**
     * Deserialize a msg_container and return the first update-like message found.
     */
    private function deserializeContainer(string $data): ?array
    {
        $offset = 4;
        $count  = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        $updates = [];

        for ($i = 0; $i < $count; $i++) {
            if ($offset + 16 > strlen($data)) {
                break;
            }

            // msg_id(8) + seqno(4) + bytes(4)
            $offset += 8; // skip msg_id
            $offset += 4; // skip seqno
            $bodyLen = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if ($offset + $bodyLen > strlen($data)) {
                break;
            }

            $body = substr($data, $offset, $bodyLen);
            $offset += $bodyLen;

            try {
                $deserialized = $this->deserializeMessage($body);
                if ($deserialized !== null) {
                    $constructor = $deserialized['_'] ?? '';
                    // Collect update-like messages
                    if (in_array($constructor, [
                        'updates', 'updatesCombined', 'updateShort',
                        'updateShortMessage', 'updateShortChatMessage',
                        'updateShortSentMessage', 'updatesTooLong',
                    ], true)) {
                        $updates[] = $deserialized;
                    }
                }
            } catch (\Throwable) {
                // Skip unparseable messages in container
            }
        }

        // Feed each update found in container
        foreach ($updates as $upd) {
            $this->feed->feed($upd);
        }

        return null; // Already fed directly
    }

    /**
     * Read TL-encoded bytes.
     */
    private function readTLBytes(string $data, int $offset): string
    {
        $firstByte = ord($data[$offset]);
        $offset++;

        if ($firstByte === 254) {
            $lengthBytes = substr($data, $offset, 3) . "\x00";
            $length = unpack('V', $lengthBytes)[1];
            $offset += 3;
        } else {
            $length = $firstByte;
        }

        return substr($data, $offset, $length);
    }

    /**
     * Send a ping to keep the connection alive.
     *
     * During the update loop we send the ping as a fire-and-forget
     * encrypted message (no blocking receiveResponse). The pong is
     * handled silently in onSocketData.
     */
    private function sendPing(): void
    {
        try {
            $rpc = $this->client->getRpcHandler();
            $tlParser = $this->client->getTlParser();
            if ($rpc === null || $tlParser === null) {
                return;
            }

            $serializer = new \LaraGram\MTProto\TL\TLSerializer($tlParser);
            $msgData = $serializer->serialize([
                '_'                => 'ping_delay_disconnect',
                'ping_id'          => random_int(PHP_INT_MIN, PHP_INT_MAX),
                'disconnect_delay' => (int) ($this->pingInterval * 2.5),
            ]);

            $rpc->sendEncrypted($msgData, false);
        } catch (\Throwable $e) {
            error_log("[UpdateLoop] Ping failed: {$e->getMessage()}");
        }
    }

    /**
     * Handle new_session_created — update server salt.
     */
    private function handleNewSession(array $data): void
    {
        if (isset($data['server_salt'])) {
            $salt = pack('P', $data['server_salt']);
            $this->client->getSession()->setServerSalt($salt);
        }
    }

    /**
     * Handle connection drop — attempt reconnection.
     */
    private function handleDisconnect(): void
    {
        error_log('[UpdateLoop] Connection lost. Attempting reconnect…');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                sleep(min($attempt * 2, 10)); // Exponential backoff (2, 4, 6, 8, 10)

                // Reconnect the client
                $this->client->connect($this->getSessionName());

                // Re-register socket reader
                $this->eventLoop->onReadable(
                    $this->client->getConnection(),
                    fn(ConnectionInterface $conn) => $this->onSocketData($conn),
                );

                echo "[UpdateLoop] Reconnected (attempt {$attempt}).\n";

                // Fetch missed updates
                $this->feed->fetchDifference();

                return;
            } catch (\Throwable $e) {
                error_log("[UpdateLoop] Reconnect attempt {$attempt} failed: {$e->getMessage()}");
            }
        }

        error_log('[UpdateLoop] Failed to reconnect after 5 attempts. Stopping loop.');
        $this->stop();
    }

    // ════════════════════════════════════════════════════════════════════
    //  Session path helpers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Derive session directory from client.
     */
    private function getSessionDir(): string
    {
        return $this->client->getSessionDir();
    }

    /**
     * Derive session name from client.
     */
    private function getSessionName(): string
    {
        return $this->client->getSessionName();
    }
}
