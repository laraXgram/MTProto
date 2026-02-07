<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Generated\Types\Message;
use LaraGram\MTProto\Generated\Types\Update;
use LaraGram\MTProto\TL\TLObject;

/**
 * High-level facade for the update listening system.
 *
 * This class wraps UpdateLoop + UpdateFeed + UpdateState and provides
 * a clean, simple API for receiving Telegram updates as TLObjects.
 *
 * Usage:
 *   $handler = new UpdatesHandler($client);
 *
 *   // Global handler — receives every update
 *   $handler->onUpdate(function (TLObject $update, string $type) {
 *       echo "[$type] " . json_encode($update) . "\n";
 *   });
 *
 *   // Typed handler — IDE auto-completes all Message properties
 *   $handler->onMessage(function (Message $message, Client $client) {
 *       echo $message->message . "\n";              // text
 *       echo $message->from_id->user_id . "\n";     // sender
 *       echo $message->peer_id->channel_id . "\n";  // channel
 *       echo $message->date . "\n";                 // timestamp
 *       echo $message->media . "\n";                // media attachment
 *   });
 *
 *   // Callback queries (typed as Update)
 *   $handler->onCallbackQuery(function (Update $query, Client $client) {
 *       echo $query->data . "\n";
 *   });
 *
 *   // Generic string-based — still works for raw constructors
 *   $handler->on('updateNewMessage', function (TLObject $data, Client $client) {
 *       // ...
 *   });
 *
 *   // Start listening (blocks)
 *   $handler->start();
 */
class UpdatesHandler
{
    private UpdateLoop $loop;
    private Client     $client;

    /**
     * @param Client                  $client     Connected MTProto client.
     * @param EventLoopInterface|null $eventLoop  Event loop driver (null = SyncEventLoop).
     * @param array                   $options    Forwarded to UpdateLoop.
     */
    public function __construct(Client $client, ?EventLoopInterface $eventLoop = null, array $options = [])
    {
        $this->client = $client;
        $this->loop   = new UpdateLoop($client, $eventLoop, $options);
    }

    // ════════════════════════════════════════════════════════════════════
    //  Handler registration
    // ════════════════════════════════════════════════════════════════════

    /**
     * Set the main update handler.
     *
     * @param callable(TLObject, string): void $callback
     *   Receives the update as a TLObject and the raw constructor name.
     */
    public function onUpdate(callable $callback): self
    {
        $this->loop->onUpdate($callback);
        return $this;
    }

    /**
     * Listen for a specific event.
     *
     * Semantic events:
     *   'message'         — new message (user/group/channel)
     *   'editedMessage'   — edited message
     *   'deletedMessages' — deleted messages
     *   'callbackQuery'   — inline button callback
     *   'inlineQuery'     — inline query
     *   'typing'          — user is typing
     *   'readHistory'     — messages were read
     *   'reactions'       — reactions changed
     *   'userStatus'      — user online/offline
     *   'chatParticipant' — member join/leave/promoted/banned
     *   '*'               — wildcard: every update
     *
     * Raw constructor names also work:
     *   'updateNewMessage', 'updateNewChannelMessage', etc.
     *
     * Callback signature:
     *   function(TLObject $data, Client $client): void
     *
     * @param string   $event    Event name.
     * @param callable $callback Handler function.
     */
    public function on(string $event, callable $callback): self
    {
        $this->loop->on($event, $callback);
        return $this;
    }

    /**
     * Remove listener(s).
     */
    public function off(string $event, ?callable $callback = null): self
    {
        $this->loop->off($event, $callback);
        return $this;
    }

    // ── Typed convenience listeners ────────────────────────────────────
    //  These provide full IDE auto-complete for callback parameters.

    /**
     * Listen for new messages (user/group/channel).
     *
     * The callback receives a fully-typed Message object with IDE auto-complete
     * for all properties: id, peer_id, from_id, date, message, media, entities,
     * views, replies, edit_date, fwd_from, reply_to, reply_markup, etc.
     *
     * Example:
     *   $handler->onMessage(function (Message $message, Client $client) {
     *       echo $message->message . "\n";            // text
     *       echo $message->from_id->user_id . "\n";   // sender
     *       echo $message->peer_id->channel_id . "\n"; // channel
     *       echo $message->date . "\n";                // timestamp
     *   });
     *
     * @param callable(Message, Client): void $callback
     */
    public function onMessage(callable $callback): self
    {
        $this->loop->onMessage($callback);
        return $this;
    }

    /**
     * Listen for edited messages.
     *
     * @param callable(Message, Client): void $callback
     */
    public function onEditedMessage(callable $callback): self
    {
        $this->loop->onEditedMessage($callback);
        return $this;
    }

    /**
     * Listen for deleted messages.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onDeletedMessages(callable $callback): self
    {
        $this->loop->onDeletedMessages($callback);
        return $this;
    }

    /**
     * Listen for inline button callback queries.
     *
     * Properties: user_id, peer, msg_id, chat_instance, data, game_short_name.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onCallbackQuery(callable $callback): self
    {
        $this->loop->onCallbackQuery($callback);
        return $this;
    }

    /**
     * Listen for inline bot queries.
     *
     * Properties: query_id, user_id, query, geo, peer_type, offset.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onInlineQuery(callable $callback): self
    {
        $this->loop->onInlineQuery($callback);
        return $this;
    }

    /**
     * Listen for typing indicators.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onTyping(callable $callback): self
    {
        $this->loop->onTyping($callback);
        return $this;
    }

    /**
     * Listen for read history events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onReadHistory(callable $callback): self
    {
        $this->loop->onReadHistory($callback);
        return $this;
    }

    /**
     * Listen for message reaction changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onReactions(callable $callback): self
    {
        $this->loop->onReactions($callback);
        return $this;
    }

    /**
     * Listen for user online/offline status changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onUserStatus(callable $callback): self
    {
        $this->loop->onUserStatus($callback);
        return $this;
    }

    /**
     * Listen for chat/channel participant changes (join/leave/promoted/banned).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChatParticipant(callable $callback): self
    {
        $this->loop->onChatParticipant($callback);
        return $this;
    }

    /**
     * Listen for chosen inline results.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChosenInlineResult(callable $callback): self
    {
        $this->loop->onChosenInlineResult($callback);
        return $this;
    }

    /**
     * Listen for pre-checkout queries (payments).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPrecheckoutQuery(callable $callback): self
    {
        $this->loop->onPrecheckoutQuery($callback);
        return $this;
    }

    /**
     * Listen for shipping queries (payments).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onShippingQuery(callable $callback): self
    {
        $this->loop->onShippingQuery($callback);
        return $this;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Lifecycle
    // ════════════════════════════════════════════════════════════════════

    /**
     * Start listening for updates. Blocks until stop() is called.
     */
    public function start(): void
    {
        $this->loop->run();
    }

    /**
     * Stop listening.
     */
    public function stop(): void
    {
        $this->loop->stop();
    }

    /**
     * Whether the loop is currently running.
     */
    public function isRunning(): bool
    {
        return $this->loop->isRunning();
    }

    // ════════════════════════════════════════════════════════════════════
    //  Advanced access
    // ════════════════════════════════════════════════════════════════════

    /**
     * Get the underlying UpdateLoop.
     */
    public function getLoop(): UpdateLoop
    {
        return $this->loop;
    }

    /**
     * Get the UpdateFeed.
     */
    public function getFeed(): UpdateFeed
    {
        return $this->loop->getFeed();
    }

    /**
     * Get the UpdateState.
     */
    public function getState(): UpdateState
    {
        return $this->loop->getState();
    }

    /**
     * Get current state as array (pts, qts, date, seq).
     */
    public function getStateArray(): array
    {
        return $this->loop->getState()->toArray();
    }

    /**
     * Manually trigger a difference fetch (gap recovery).
     */
    public function fetchDifference(): void
    {
        $this->loop->getFeed()->fetchDifference();
    }

    /**
     * Process a raw Updates container manually (useful for testing or
     * feeding updates from a different source).
     *
     * @param array $data Raw deserialized Updates TL object.
     */
    public function processUpdates(array $data): void
    {
        $this->loop->getFeed()->feed($data);
    }

    /**
     * Initialise state from server without starting the loop.
     */
    public function initialize(): void
    {
        $this->loop->getFeed()->initialise();
    }
}
