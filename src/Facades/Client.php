<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Facades;

use LaraGram\Support\Facades\Facade;

/**
 * Resolves to the 'client.listener' binding (ClientListener instance).
 *
 * === Messages ===
 * @method static \LaraGram\Listening\ListenRegistrar incomming()
 * @method static \LaraGram\Listening\ListenRegistrar outgoing()
 * @method static \LaraGram\Listening\ListenRegistrar scope(string $scope)
 *
 * @method static \LaraGram\Listening\Listen onMessage(\Closure|array|string $action) Listen for all new messages
 * @method static \LaraGram\Listening\Listen onText(string $pattern, \Closure|array|string $action) Listen for messages matching a text pattern
 * @method static \LaraGram\Listening\Listen onCommand(string|array $command, \Closure|array|string $action) Listen for a slash command (optional $args captured)
 * @method static \LaraGram\Listening\Listen onEditedMessage(\Closure|array|string $action) Listen for edited messages
 * @method static \LaraGram\Listening\Listen onDeletedMessages(\Closure|array|string $action) Listen for deleted messages
 * @method static \LaraGram\Listening\Listen onPinnedMessages(\Closure|array|string $action) Listen for pinned message events
 * @method static \LaraGram\Listening\Listen onScheduledMessage(\Closure|array|string $action) Listen for scheduled messages
 *
 * === Media ===
 * @method static \LaraGram\Listening\Listen onPhoto(\Closure|array|string $action) Listen for photo messages
 * @method static \LaraGram\Listening\Listen onVideo(\Closure|array|string $action) Listen for video messages
 * @method static \LaraGram\Listening\Listen onAnimation(\Closure|array|string $action) Listen for animation (GIF) messages
 * @method static \LaraGram\Listening\Listen onSticker(\Closure|array|string $action) Listen for sticker messages
 * @method static \LaraGram\Listening\Listen onDocument(\Closure|array|string $action) Listen for document (file) messages
 * @method static \LaraGram\Listening\Listen onAudio(\Closure|array|string $action) Listen for audio messages
 * @method static \LaraGram\Listening\Listen onVoice(\Closure|array|string $action) Listen for voice messages
 * @method static \LaraGram\Listening\Listen onVideoNote(\Closure|array|string $action) Listen for video note (round video) messages
 * @method static \LaraGram\Listening\Listen onContact(\Closure|array|string $action) Listen for contact messages
 * @method static \LaraGram\Listening\Listen onLocation(\Closure|array|string $action) Listen for location messages
 * @method static \LaraGram\Listening\Listen onVenue(\Closure|array|string $action) Listen for venue messages
 * @method static \LaraGram\Listening\Listen onGame(\Closure|array|string $action) Listen for game messages
 * @method static \LaraGram\Listening\Listen onDice(\Closure|array|string $action, string|array $emoji = 'any', int|array $value = 0) Listen for dice messages (filter by emoji/value)
 *
 * === Callback / Inline ===
 * @method static \LaraGram\Listening\Listen onCallbackQuery(\Closure|array|string $action) Listen for callback queries (all)
 * @method static \LaraGram\Listening\Listen onCallbackQueryData(string $pattern, \Closure|array|string $action) Listen for callback queries matching a data pattern
 * @method static \LaraGram\Listening\Listen onInlineQuery(\Closure|array|string $action) Listen for inline bot queries
 * @method static \LaraGram\Listening\Listen onChosenInlineResult(\Closure|array|string $action) Listen for chosen inline results
 *
 * === Typing / Read ===
 * @method static \LaraGram\Listening\Listen onTyping(\Closure|array|string $action) Listen for typing indicators
 * @method static \LaraGram\Listening\Listen onReadHistory(\Closure|array|string $action) Listen for read history events
 *
 * === Reactions ===
 * @method static \LaraGram\Listening\Listen onReactions(\Closure|array|string $action) Listen for message reaction changes
 *
 * === Users ===
 * @method static \LaraGram\Listening\Listen onUserStatus(\Closure|array|string $action) Listen for user online/offline status changes
 *
 * === Participants ===
 * @method static \LaraGram\Listening\Listen onChatParticipant(\Closure|array|string $action) Listen for chat/channel participant changes
 * @method static \LaraGram\Listening\Listen onChatJoinRequest(\Closure|array|string $action) Listen for chat join requests
 * @method static \LaraGram\Listening\Listen onChatBoost(\Closure|array|string $action) Listen for chat boost events
 *
 * === Polls ===
 * @method static \LaraGram\Listening\Listen onPoll(\Closure|array|string $action) Listen for poll updates
 * @method static \LaraGram\Listening\Listen onPollVote(\Closure|array|string $action) Listen for poll votes
 *
 * === Payments ===
 * @method static \LaraGram\Listening\Listen onPreCheckoutQuery(\Closure|array|string $action) Listen for pre-checkout queries
 * @method static \LaraGram\Listening\Listen onShippingQuery(\Closure|array|string $action) Listen for shipping queries
 *
 * === Phone / Group Calls ===
 * @method static \LaraGram\Listening\Listen onPhoneCall(\Closure|array|string $action) Listen for phone call events
 * @method static \LaraGram\Listening\Listen onGroupCall(\Closure|array|string $action) Listen for group call events
 *
 * === Stories ===
 * @method static \LaraGram\Listening\Listen onStory(\Closure|array|string $action) Listen for story events
 *
 * === Encrypted ===
 * @method static \LaraGram\Listening\Listen onEncryptedMessage(\Closure|array|string $action) Listen for encrypted messages (secret chats)
 *
 * === Drafts ===
 * @method static \LaraGram\Listening\Listen onDraft(\Closure|array|string $action) Listen for draft message changes
 *
 * === Notifications ===
 * @method static \LaraGram\Listening\Listen onServiceNotification(\Closure|array|string $action) Listen for service notifications from Telegram
 *
 * === Peers ===
 * @method static \LaraGram\Listening\Listen onPeerBlocked(\Closure|array|string $action) Listen for peer blocked/unblocked events
 *
 * === Bots ===
 * @method static \LaraGram\Listening\Listen onBotStopped(\Closure|array|string $action) Listen for bot stopped/started events
 * @method static \LaraGram\Listening\Listen onBotCommands(\Closure|array|string $action) Listen for bot command updates
 * @method static \LaraGram\Listening\Listen onBotReaction(\Closure|array|string $action) Listen for bot reaction events
 *
 * === Bot Business ===
 * @method static \LaraGram\Listening\Listen onBusinessMessage(\Closure|array|string $action) Listen for business messages
 * @method static \LaraGram\Listening\Listen onBusinessConnect(\Closure|array|string $action) Listen for business connection events
 *
 * === Forum ===
 * @method static \LaraGram\Listening\Listen onForumTopic(\Closure|array|string $action) Listen for forum topic pin/unpin events
 *
 * === Catch-all ===
 * @method static \LaraGram\Listening\Listen onUpdate(\Closure|array|string $action) Listen for ANY update (catch-all)
 * @method static \LaraGram\Listening\Listen fallback(\Closure|array|string $action) Register a fallback handler
 *
 * === Routing ===
 * @method static \LaraGram\MTProto\Listening\ClientListenRegistrar middleware(array|string $middleware)
 * @method static \LaraGram\MTProto\Listening\ClientListenRegistrar forSessions(array|string $sessions) Scope the following listens to one or more sessions
 * @method static \LaraGram\MTProto\Listening\ClientListener group(array $attributes, \Closure|array|string $listens)
 *
 * === Sending ===
 * @method static \LaraGram\MTProto\Core\Client session(string $name = 'default') Get the live MTProto client for a session (to send outside a handler / via another account)
 *
 * @see \LaraGram\MTProto\Listening\ClientListener
 */
class Client extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'client.listener';
    }
}
