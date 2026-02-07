<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Generated\Types\Message;
use LaraGram\MTProto\Generated\Types\Update;
use LaraGram\MTProto\TL\TLObject;

/**
 * Core update feed — processes raw Updates containers from the socket,
 * handles gap detection, difference fetching, state management, and
 * dispatches individual Update objects to registered handlers.
 *
 * Every dispatched update is wrapped in a TLObject so the consumer gets
 * property-based access:  $update->message->text, $update->message->from_id, etc.
 */
class UpdateFeed
{
    private Client      $client;
    private UpdateState $state;

    /**
     * Event loop driver for non-blocking handler dispatch.
     * On amphp/swoole, handlers run in separate fibers/coroutines
     * so that sleep() or blocking RPC calls don't freeze the loop.
     */
    private ?EventLoopInterface $eventLoop = null;

    /**
     * The single callback that receives every individual update.
     * Signature: function(TLObject $update, string $type): void
     *
     * @var callable|null
     */
    private $onUpdate = null;

    /**
     * Named event callbacks: event_name → callable[]
     * @var array<string, callable[]>
     */
    private array $listeners = [];

    /**
     * Whether we are currently fetching difference (to avoid recursion).
     */
    private bool $fetchingDifference = false;

    /**
     * Postponed updates while fetching difference.
     * @var array[]
     */
    private array $postponed = [];

    public function __construct(Client $client, UpdateState $state)
    {
        $this->client = $client;
        $this->state  = $state;
    }

    /**
     * Inject the event loop driver for non-blocking handler dispatch.
     */
    public function setEventLoop(EventLoopInterface $eventLoop): void
    {
        $this->eventLoop = $eventLoop;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Public API
    // ════════════════════════════════════════════════════════════════════

    /**
     * Register the main update handler.
     *
     * @param callable(TLObject, string): void $callback
     */
    public function setUpdateHandler(callable $callback): void
    {
        $this->onUpdate = $callback;
    }

    /**
     * Register a handler for a specific event type.
     *
     * Supported built-in events:
     *   'message', 'editedMessage', 'deletedMessages', 'callbackQuery',
     *   'inlineQuery', 'typing', 'readHistory', 'reactions',
     *   'userStatus', 'chatParticipant', '*' (wildcard)
     *
     * You can also listen to raw constructor names:
     *   'updateNewMessage', 'updateNewChannelMessage', etc.
     */
    public function on(string $event, callable $callback): void
    {
        $this->listeners[$event][] = $callback;
    }

    /**
     * Remove listener(s).
     */
    public function off(string $event, ?callable $callback = null): void
    {
        if ($callback === null) {
            unset($this->listeners[$event]);
        } else {
            $this->listeners[$event] = array_filter(
                $this->listeners[$event] ?? [],
                static fn($h) => $h !== $callback,
            );
        }
    }

    // ── Typed convenience listeners ────────────────────────────────────

    /**
     * Listen for new messages (user/group/channel).
     *
     * The callback receives a fully-typed Message object with IDE auto-complete
     * for all properties (id, peer_id, from_id, date, message, media, entities, etc.).
     *
     * @param callable(Message, Client): void $callback
     */
    public function onMessage(callable $callback): void
    {
        $this->listeners['message'][] = $callback;
    }

    /**
     * Listen for edited messages.
     *
     * @param callable(Message, Client): void $callback
     */
    public function onEditedMessage(callable $callback): void
    {
        $this->listeners['editedMessage'][] = $callback;
    }

    /**
     * Listen for deleted messages.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onDeletedMessages(callable $callback): void
    {
        $this->listeners['deletedMessages'][] = $callback;
    }

    /**
     * Listen for inline button callback queries.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onCallbackQuery(callable $callback): void
    {
        $this->listeners['callbackQuery'][] = $callback;
    }

    /**
     * Listen for inline bot queries.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onInlineQuery(callable $callback): void
    {
        $this->listeners['inlineQuery'][] = $callback;
    }

    /**
     * Listen for typing indicators.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onTyping(callable $callback): void
    {
        $this->listeners['typing'][] = $callback;
    }

    /**
     * Listen for read history events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onReadHistory(callable $callback): void
    {
        $this->listeners['readHistory'][] = $callback;
    }

    /**
     * Listen for message reaction changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onReactions(callable $callback): void
    {
        $this->listeners['reactions'][] = $callback;
    }

    /**
     * Listen for user online/offline status changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onUserStatus(callable $callback): void
    {
        $this->listeners['userStatus'][] = $callback;
    }

    /**
     * Listen for chat/channel participant changes (join/leave/promoted/banned).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChatParticipant(callable $callback): void
    {
        $this->listeners['chatParticipant'][] = $callback;
    }

    /**
     * Listen for chosen inline results.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChosenInlineResult(callable $callback): void
    {
        $this->listeners['chosenInlineResult'][] = $callback;
    }

    /**
     * Listen for pre-checkout queries (payments).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPrecheckoutQuery(callable $callback): void
    {
        $this->listeners['precheckoutQuery'][] = $callback;
    }

    /**
     * Listen for shipping queries (payments).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onShippingQuery(callable $callback): void
    {
        $this->listeners['shippingQuery'][] = $callback;
    }

    /**
     * Initialise/refresh state from server.
     *
     * Always re-syncs with the server so that stale pts/seq values from
     * a previous run don't cause false gap detection (which would trigger
     * blocking fetchDifference calls and lose updates).
     */
    public function initialise(): void
    {
        $serverState = $this->client->invoke('updates.getState', []);
        $this->state->applyState($serverState);
        $this->state->save();
    }

    /**
     * Get the UpdateState instance.
     */
    public function getState(): UpdateState
    {
        return $this->state;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Feed entry-point — receives raw Updates container from socket
    // ════════════════════════════════════════════════════════════════════

    /**
     * Process a raw Updates container received from the socket.
     *
     * @param array $data  Deserialized TL Updates object (has '_' key).
     */
    public function feed(array $data): void
    {
        $type = $data['_'] ?? '';

        match ($type) {
            'updates'              => $this->handleUpdates($data),
            'updatesCombined'      => $this->handleUpdatesCombined($data),
            'updateShort'          => $this->handleUpdateShort($data),
            'updateShortMessage'   => $this->handleUpdateShortMessage($data),
            'updateShortChatMessage' => $this->handleUpdateShortChatMessage($data),
            'updateShortSentMessage' => $this->handleUpdateShortSentMessage($data),
            'updatesTooLong'       => $this->fetchDifference(),
            default                => null, // Ignore unknown containers
        };
    }

    // ════════════════════════════════════════════════════════════════════
    //  Container handlers
    // ════════════════════════════════════════════════════════════════════

    /**
     * updates#74ae4240
     */
    private function handleUpdates(array $data): void
    {
        $seq = $data['seq'] ?? 0;

        // seq-based ordering check (skip obvious duplicates only)
        if ($seq > 0 && $this->state->getSeq() > 0) {
            if ($seq <= $this->state->getSeq()) {
                return; // Duplicate — we already processed this seq
            }
            // If seq is ahead (gap), we still process the update
            // to avoid losing it. State will be corrected later.
        }

        // Cache entities
        $this->cacheEntities($data);

        // Update seq/date
        if ($seq > 0) {
            $this->state->setSeq($seq);
        }
        if (isset($data['date'])) {
            $this->state->setDate(max($this->state->getDate(), $data['date']));
        }

        // Process each update
        foreach ($data['updates'] ?? [] as $update) {
            $this->processUpdate($update);
        }

        $this->state->save();
    }

    /**
     * updatesCombined#725b04c3
     */
    private function handleUpdatesCombined(array $data): void
    {
        $seqStart = $data['seq_start'] ?? $data['seq'] ?? 0;

        if ($seqStart > 0 && $this->state->getSeq() > 0) {
            if ($seqStart <= $this->state->getSeq()) {
                return; // Duplicate
            }
        }

        // Same logic as updates
        $this->handleUpdates($data);
    }

    /**
     * updateShort#78d4dec1 — single stateless update
     */
    private function handleUpdateShort(array $data): void
    {
        if (isset($data['date'])) {
            $this->state->setDate(max($this->state->getDate(), $data['date']));
        }

        $this->processUpdate($data['update']);
        $this->state->save();
    }

    /**
     * updateShortMessage#313bc7f8 — incoming DM, convert to full message
     */
    private function handleUpdateShortMessage(array $data): void
    {
        $message = $this->shortMessageToFull($data, isChat: false);
        $this->processUpdate([
            '_'         => 'updateNewMessage',
            'message'   => $message,
            'pts'       => $data['pts'],
            'pts_count' => $data['pts_count'],
        ]);
        $this->state->save();
    }

    /**
     * updateShortChatMessage#4d6deea5 — incoming group message
     */
    private function handleUpdateShortChatMessage(array $data): void
    {
        $message = $this->shortMessageToFull($data, isChat: true);
        $this->processUpdate([
            '_'         => 'updateNewMessage',
            'message'   => $message,
            'pts'       => $data['pts'],
            'pts_count' => $data['pts_count'],
        ]);
        $this->state->save();
    }

    /**
     * updateShortSentMessage — our own sent message confirmed
     */
    private function handleUpdateShortSentMessage(array $data): void
    {
        $this->dispatchEvent('sentMessage', TLObject::fromArray($data));
    }

    // ════════════════════════════════════════════════════════════════════
    //  Individual update processing
    // ════════════════════════════════════════════════════════════════════

    /**
     * Process a single Update constructor.
     *
     * Skips obvious duplicates (pts we've already seen) but always
     * delivers new updates — never triggers blocking RPC from here.
     */
    private function processUpdate(array $update): void
    {
        $type = $update['_'] ?? '';

        // ── pts-based update ───────────────────────────────────────────
        if (isset($update['pts']) && $update['pts'] > 0) {
            $ptsCount  = $update['pts_count'] ?? 0;
            $channelId = $this->extractChannelId($update);

            if ($channelId !== null) {
                $currentPts = $this->state->getChannelPts($channelId);
                if ($currentPts > 0 && $update['pts'] <= $currentPts) {
                    return; // Duplicate — already seen
                }
                $this->state->setChannelPts($channelId, $update['pts']);
            } else {
                $currentPts = $this->state->getPts();
                if ($currentPts > 0 && $update['pts'] <= $currentPts) {
                    return; // Duplicate — already seen
                }
                $this->state->setPts($update['pts']);
            }
        }

        // ── qts-based update ───────────────────────────────────────────
        if (isset($update['qts']) && $update['qts'] > 0) {
            $currentQts = $this->state->getQts();
            if ($currentQts > 0 && $update['qts'] <= $currentQts) {
                return; // Duplicate
            }
            $this->state->setQts($update['qts']);
        }

        // ── Wrap and dispatch ──────────────────────────────────────────
        $this->dispatch($update);
    }

    /**
     * Wrap raw array into TLObject and invoke handlers.
     */
    private function dispatch(array $update): void
    {
        $type    = $update['_'] ?? 'unknown';
        $wrapped = TLObject::fromArray($update);

        // ── Filter out outgoing messages (echo-loop prevention) ────────
        // When a handler sends a message via $client->sendMessage(), the
        // server delivers it back as an update with out=true. Without this
        // filter, the handler would trigger on its own outgoing messages,
        // causing an infinite echo loop: send → receive → send → …
        if ($this->isOutgoingMessage($update)) {
            return;
        }

        // 1. Main handler (non-blocking on async drivers)
        if ($this->onUpdate !== null) {
            $handler = $this->onUpdate;
            $isFork  = $this->eventLoop !== null && $this->eventLoop->getName() === 'fork';
            $client  = $this->client;

            $run = function () use ($handler, $wrapped, $type, $client, $isFork): void {
                try {
                    if ($isFork) {
                        $client->reconnect();
                    }
                    $handler($wrapped, $type);
                } catch (\Throwable $e) {
                    $this->dispatchEvent('error', TLObject::fromArray([
                        '_'     => 'error',
                        'event' => $type,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]));
                }
            };

            if ($this->eventLoop !== null) {
                $this->eventLoop->queueCallback($run);
            } else {
                $run();
            }
        }

        // 2. Specific raw-constructor listeners
        $this->dispatchEvent($type, $wrapped);

        // 3. Semantic aliases
        match ($type) {
            'updateNewMessage',
            'updateNewChannelMessage'       => $this->dispatchEvent('message', $this->extractMessage($wrapped)),

            'updateEditMessage',
            'updateEditChannelMessage'      => $this->dispatchEvent('editedMessage', $this->extractMessage($wrapped)),

            'updateDeleteMessages',
            'updateDeleteChannelMessages'   => $this->dispatchEvent('deletedMessages', $wrapped),

            'updateBotCallbackQuery',
            'updateInlineBotCallbackQuery'  => $this->dispatchEvent('callbackQuery', $wrapped),

            'updateBotInlineQuery'          => $this->dispatchEvent('inlineQuery', $wrapped),

            'updateUserTyping',
            'updateChatUserTyping',
            'updateChannelUserTyping'       => $this->dispatchEvent('typing', $wrapped),

            'updateReadHistoryInbox',
            'updateReadHistoryOutbox',
            'updateReadChannelInbox',
            'updateReadChannelOutbox'       => $this->dispatchEvent('readHistory', $wrapped),

            'updateMessageReactions'        => $this->dispatchEvent('reactions', $wrapped),
            'updateUserStatus'              => $this->dispatchEvent('userStatus', $wrapped),

            'updateChatParticipant',
            'updateChannelParticipant'      => $this->dispatchEvent('chatParticipant', $wrapped),

            'updateBotInlineSend'           => $this->dispatchEvent('chosenInlineResult', $wrapped),
            'updateBotPrecheckoutQuery'     => $this->dispatchEvent('precheckoutQuery', $wrapped),
            'updateBotShippingQuery'        => $this->dispatchEvent('shippingQuery', $wrapped),

            default => null,
        };

        // 4. Wildcard
        $this->dispatchEvent('*', $wrapped);
    }

    /**
     * Fire event callbacks.
     *
     * On async drivers each handler is queued as a separate
     * fiber/coroutine/process so that blocking code inside the handler
     * does not freeze the event loop.
     *
     * For the Fork driver specifically, the child process reconnects
     * the Client to get its own dedicated TCP socket.
     */
    private function dispatchEvent(string $event, TLObject $data): void
    {
        foreach ($this->listeners[$event] ?? [] as $handler) {
            $client = $this->client;
            $isFork = $this->eventLoop !== null && $this->eventLoop->getName() === 'fork';

            $run = function () use ($handler, $data, $event, $client, $isFork): void {
                try {
                    // In fork mode, the child must get its own TCP socket
                    if ($isFork) {
                        $client->reconnect();
                    }
                    $handler($data, $client);
                } catch (\Throwable $e) {
                    if ($event !== 'error') {
                        $this->dispatchEvent('error', TLObject::fromArray([
                            '_'     => 'error',
                            'event' => $event,
                            'error' => $e->getMessage(),
                        ]));
                    }
                }
            };

            if ($this->eventLoop !== null) {
                $this->eventLoop->queueCallback($run);
            } else {
                $run();
            }
        }
    }

    /**
     * Extract the inner `message` from an updateNewMessage-style update.
     *
     * @return Message The extracted Message object with full property access.
     */
    private function extractMessage(TLObject $update): Message
    {
        $msg = $update->message;
        if ($msg instanceof Message) {
            return $msg;
        }
        if ($msg instanceof TLObject) {
            /** @var Message $msg */
            return $msg;
        }
        // Fallback: wrap raw array
        $raw = $update->toArray();
        if (is_array($raw['message'] ?? null)) {
            /** @var Message */
            return TLObject::fromArray($raw['message']);
        }
        /** @var Message $update */
        return $update;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Difference fetching
    // ════════════════════════════════════════════════════════════════════

    /**
     * Fetch common updates difference from server.
     */
    public function fetchDifference(): void
    {
        if ($this->fetchingDifference) {
            return;
        }
        $this->fetchingDifference = true;

        try {
            $this->doFetchDifference();
        } finally {
            $this->fetchingDifference = false;

            // Re-process postponed
            $postponed = $this->postponed;
            $this->postponed = [];
            foreach ($postponed as $upd) {
                $this->feed($upd);
            }
        }
    }

    private function doFetchDifference(): void
    {
        $result = $this->client->invoke('updates.getDifference', [
            'pts'  => $this->state->getPts(),
            'date' => $this->state->getDate(),
            'qts'  => $this->state->getQts(),
        ]);

        $type = $result['_'] ?? '';

        switch ($type) {
            case 'updates.differenceEmpty':
                $this->state->setDate($result['date'] ?? $this->state->getDate());
                $this->state->setSeq($result['seq'] ?? $this->state->getSeq());
                break;

            case 'updates.difference':
                $this->applyDifference($result);
                $this->state->applyState($result['state']);
                break;

            case 'updates.differenceSlice':
                $this->applyDifference($result);
                $this->state->applyState($result['intermediate_state']);
                // More data available — recurse
                $this->doFetchDifference();
                break;

            case 'updates.differenceTooLong':
                $this->state->setPts($result['pts']);
                break;
        }

        $this->state->save();
    }

    /**
     * Fetch channel-specific difference.
     */
    public function fetchChannelDifference(int $channelId): void
    {
        $pts = $this->state->getChannelPts($channelId);
        if ($pts === 0) {
            return;
        }

        try {
            $result = $this->client->invoke('updates.getChannelDifference', [
                'force'   => false,
                'channel' => $channelId, // Pass as int — preprocessor resolves via PeerDatabase
                'filter'  => ['_' => 'channelMessagesFilterEmpty'],
                'pts'     => $pts,
                'limit'   => 100,
            ]);
        } catch (\Throwable $e) {
            // Channel might not be accessible
            error_log("[UpdateFeed] Failed to get channel difference for {$channelId}: {$e->getMessage()}");
            return;
        }

        $type = $result['_'] ?? '';

        switch ($type) {
            case 'updates.channelDifferenceEmpty':
                // Nothing to do
                break;

            case 'updates.channelDifference':
                $this->cacheEntities($result);
                foreach ($result['new_messages'] ?? [] as $msg) {
                    $this->dispatch([
                        '_'       => 'updateNewChannelMessage',
                        'message' => $msg,
                        'pts'     => $result['pts'] ?? $pts,
                        'pts_count' => 0,
                    ]);
                }
                foreach ($result['other_updates'] ?? [] as $upd) {
                    $this->dispatch($upd);
                }
                $this->state->setChannelPts($channelId, $result['pts']);
                break;

            case 'updates.channelDifferenceTooLong':
                $this->state->setChannelPts($channelId, $result['pts'] ?? $pts);
                break;
        }

        $this->state->save();
    }

    /**
     * Apply a difference result (messages + other_updates).
     */
    private function applyDifference(array $data): void
    {
        // Cache entities
        $this->cacheEntities($data);

        // New messages → dispatch as updateNewMessage
        foreach ($data['new_messages'] ?? [] as $msg) {
            $this->dispatch([
                '_'         => 'updateNewMessage',
                'message'   => $msg,
                'pts'       => 0,
                'pts_count' => 0,
            ]);
        }

        // New encrypted messages
        foreach ($data['new_encrypted_messages'] ?? [] as $msg) {
            $this->dispatch([
                '_'       => 'updateNewEncryptedMessage',
                'message' => $msg,
                'qts'     => 0,
            ]);
        }

        // Other updates
        foreach ($data['other_updates'] ?? [] as $upd) {
            $this->dispatch($upd);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Convert updateShortMessage / updateShortChatMessage to a full message array.
     */
    private function shortMessageToFull(array $data, bool $isChat): array
    {
        $message = [
            '_'            => 'message',
            'id'           => $data['id'],
            'message'      => $data['message'],
            'date'         => $data['date'],
            'out'          => $data['out'] ?? false,
            'mentioned'    => $data['mentioned'] ?? false,
            'media_unread' => $data['media_unread'] ?? false,
            'silent'       => $data['silent'] ?? false,
            'fwd_from'     => $data['fwd_from'] ?? null,
            'via_bot_id'   => $data['via_bot_id'] ?? null,
            'reply_to'     => $data['reply_to'] ?? null,
            'entities'     => $data['entities'] ?? [],
            'ttl_period'   => $data['ttl_period'] ?? null,
        ];

        if ($isChat) {
            $message['from_id'] = ['_' => 'peerUser', 'user_id' => $data['from_id']];
            $message['peer_id'] = ['_' => 'peerChat', 'chat_id' => $data['chat_id']];
        } else {
            $selfId = $data['out'] ?? false
                ? $this->getSelfId()
                : $data['user_id'];
            $peerId = $data['out'] ?? false
                ? $data['user_id']
                : $data['user_id'];

            $message['from_id'] = ['_' => 'peerUser', 'user_id' => $selfId];
            $message['peer_id'] = ['_' => 'peerUser', 'user_id' => $peerId];
        }

        return $message;
    }

    /**
     * Extract channel ID from a channel-specific update, if applicable.
     */
    private function extractChannelId(array $update): ?int
    {
        $type = $update['_'] ?? '';

        // Updates that carry channel_id directly
        if (isset($update['channel_id'])) {
            return $update['channel_id'];
        }

        // Updates with a message that has a peer_id.channel_id
        if (isset($update['message']['peer_id']['channel_id'])) {
            return $update['message']['peer_id']['channel_id'];
        }

        // Known channel-specific update constructors
        return match ($type) {
            'updateNewChannelMessage',
            'updateEditChannelMessage',
            'updateDeleteChannelMessages',
            'updateChannelTooLong',
            'updateReadChannelInbox',
            'updateReadChannelOutbox',
            'updateChannelUserTyping',
            'updateChannelParticipant',
            'updateChannelMessageViews',
            'updateChannelMessageForwards',
            'updateChannelAvailableMessages',
            'updatePinnedChannelMessages' => $update['channel_id'] ?? null,
            default => null,
        };
    }

    /**
     * Cache users/chats from response into PeerDatabase.
     */
    private function cacheEntities(array $data): void
    {
        $peerDb = $this->client->getPeerDatabase();
        if ($peerDb === null) {
            return;
        }

        foreach ($data['users'] ?? [] as $user) {
            if (is_array($user)) {
                $peerDb->addFromTL($user);
            }
        }
        foreach ($data['chats'] ?? [] as $chat) {
            if (is_array($chat)) {
                $peerDb->addFromTL($chat);
            }
        }
    }

    /**
     * Get the current user's ID (best-effort).
     *
     * For outgoing updateShortMessage, we need the self user_id to build
     * the from_id field. If unknown, we fall back to 0 — the message will
     * still be delivered, just without the correct from_id.
     */
    private function getSelfId(): int
    {
        return 0; // Will be enhanced when self-user caching is added
    }

    /**
     * Check whether an update represents an outgoing message.
     *
     * When a handler calls $client->sendMessage(), the server delivers
     * that message back as an update with out=true (or the constructor
     * updateShortSentMessage). Without filtering these out, we get an
     * infinite echo loop:  handler sends → update arrives → handler fires
     * → handler sends → …
     *
     * This checks:
     *   - updateNewMessage / updateNewChannelMessage with message.out=true
     *   - updateShortMessage with out=true
     *   - updateShortChatMessage with out=true
     *   - updateShortSentMessage (always outgoing by definition)
     */
    private function isOutgoingMessage(array $update): bool
    {
        $type = $update['_'] ?? '';

        // updateShortSentMessage is ALWAYS our own sent message
        if ($type === 'updateShortSentMessage') {
            return true;
        }

        // updateShortMessage / updateShortChatMessage have a top-level 'out' flag
        if ($type === 'updateShortMessage' || $type === 'updateShortChatMessage') {
            return !empty($update['out']);
        }

        // updateNewMessage / updateNewChannelMessage carry message.out
        if ($type === 'updateNewMessage' || $type === 'updateNewChannelMessage') {
            return !empty($update['message']['out']);
        }

        // updateEditMessage / updateEditChannelMessage — also filter outgoing edits
        if ($type === 'updateEditMessage' || $type === 'updateEditChannelMessage') {
            return !empty($update['message']['out']);
        }

        return false;
    }
}
