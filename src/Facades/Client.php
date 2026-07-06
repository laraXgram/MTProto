<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Facades;

use LaraGram\Support\Facades\Facade;

/**
 * Resolves to the 'client.listener' binding (ClientListener instance).

 * @method static \LaraGram\MTProto\Listening\ClientListenRegistrar incomming() Only incoming (received) updates
 * @method static \LaraGram\MTProto\Listening\ClientListenRegistrar outgoing() Only outgoing (own) updates
 * @method static \LaraGram\Listening\ListenRegistrar scope(string $scope)
 *
 * @method static \LaraGram\Listening\Listen on(string $pattern, \Closure|array|string $action) Alias of onText
 * @method static \LaraGram\Listening\Listen onText(string $pattern, \Closure|array|string $action) Message text pattern
 * @method static \LaraGram\Listening\Listen onCommand(string|array $command, \Closure|array|string $action) Slash command (optional $args captured)
 * @method static \LaraGram\Listening\Listen onReferral(string $pattern, \Closure|array|string $action) /start deep-link payload
 * @method static \LaraGram\Listening\Listen onMessageType(string|array $type, \Closure|array|string $action) One or more content types (photo|voice|…)
 * @method static \LaraGram\Listening\Listen onStep(string $step, \Closure|array|string $action, ?string $pattern = null, array|string|null $method = null) Conversational step listen
 *
 * @method static \LaraGram\Listening\Listen onMessage(\Closure|array|string $action) All new messages
 * @method static \LaraGram\Listening\Listen onEditedMessage(\Closure|array|string $action) Edited messages
 * @method static \LaraGram\Listening\Listen onSentMessage(\Closure|array|string $action) Own sent-message confirmations
 * @method static \LaraGram\Listening\Listen onDeletedMessages(\Closure|array|string $action) Deleted messages
 * @method static \LaraGram\Listening\Listen onPinnedMessages(\Closure|array|string $action) Pinned-message events
 * @method static \LaraGram\Listening\Listen onScheduledMessage(\Closure|array|string $action) New scheduled messages
 * @method static \LaraGram\Listening\Listen onDeleteScheduled(\Closure|array|string $action) Deleted scheduled messages
 * @method static \LaraGram\Listening\Listen onMessageId(\Closure|array|string $action) Assigned message id after sending
 * @method static \LaraGram\Listening\Listen onMessageViews(\Closure|array|string $action) Channel view-count changes
 * @method static \LaraGram\Listening\Listen onMessageForwards(\Closure|array|string $action) Channel forward-count changes
 * @method static \LaraGram\Listening\Listen onMessageExtendedMedia(\Closure|array|string $action) Extended (paid) media reveal
 * @method static \LaraGram\Listening\Listen onTranscribedAudio(\Closure|array|string $action) Audio transcription results
 * @method static \LaraGram\Listening\Listen onGeoLiveViewed(\Closure|array|string $action) Live-location viewed
 *
 * @method static \LaraGram\Listening\Listen onPhoto(\Closure|array|string $action) Photo messages
 * @method static \LaraGram\Listening\Listen onVideo(\Closure|array|string $action) Video messages
 * @method static \LaraGram\Listening\Listen onAnimation(\Closure|array|string $action) Animation (GIF) messages
 * @method static \LaraGram\Listening\Listen onSticker(\Closure|array|string $action) Sticker messages
 * @method static \LaraGram\Listening\Listen onDocument(\Closure|array|string $action) Document (file) messages
 * @method static \LaraGram\Listening\Listen onAudio(\Closure|array|string $action) Audio messages
 * @method static \LaraGram\Listening\Listen onVoice(\Closure|array|string $action) Voice messages
 * @method static \LaraGram\Listening\Listen onVideoNote(\Closure|array|string $action) Video note (round video) messages
 * @method static \LaraGram\Listening\Listen onContact(\Closure|array|string $action) Contact messages
 * @method static \LaraGram\Listening\Listen onLocation(\Closure|array|string $action) Location (and live-location) messages
 * @method static \LaraGram\Listening\Listen onVenue(\Closure|array|string $action) Venue messages
 * @method static \LaraGram\Listening\Listen onGame(\Closure|array|string $action) Game messages
 * @method static \LaraGram\Listening\Listen onInvoice(\Closure|array|string $action) Invoice messages
 * @method static \LaraGram\Listening\Listen onPoll(\Closure|array|string $action) Poll messages
 * @method static \LaraGram\Listening\Listen onGiveaway(\Closure|array|string $action) Giveaway (and results) messages
 * @method static \LaraGram\Listening\Listen onPaidMedia(\Closure|array|string $action) Paid-media messages
 * @method static \LaraGram\Listening\Listen onDice(\Closure|array|string $action, string|array $emoji = 'any', int|array $value = 0) Dice messages (filter by emoji/value)
 *
 * @method static \LaraGram\Listening\Listen onHashtag(\Closure|array|string $action) #hashtag entity
 * @method static \LaraGram\Listening\Listen onCashtag(\Closure|array|string $action) $cashtag entity
 * @method static \LaraGram\Listening\Listen onMention(\Closure|array|string $action) @mention entity
 * @method static \LaraGram\Listening\Listen onUrl(\Closure|array|string $action) URL entity
 * @method static \LaraGram\Listening\Listen onEmail(\Closure|array|string $action) Email entity
 * @method static \LaraGram\Listening\Listen onBotCommandEntity(\Closure|array|string $action) /bot_command entity
 *
 * @method static \LaraGram\Listening\Listen onCallbackQuery(\Closure|array|string $action) Callback queries (all)
 * @method static \LaraGram\Listening\Listen onCallbackQueryData(string|array $pattern, \Closure|array|string $action) Callback queries matching a data pattern
 * @method static \LaraGram\Listening\Listen onInlineQuery(\Closure|array|string $action) Inline bot queries
 * @method static \LaraGram\Listening\Listen onChosenInlineResult(\Closure|array|string $action) Chosen inline results
 *
 * @method static \LaraGram\Listening\Listen onTyping(\Closure|array|string $action) Typing indicators
 * @method static \LaraGram\Listening\Listen onReadHistory(\Closure|array|string $action) Read-history events
 * @method static \LaraGram\Listening\Listen onReadDiscussion(\Closure|array|string $action) Read-discussion events
 * @method static \LaraGram\Listening\Listen onReadContents(\Closure|array|string $action) Read message-contents events
 * @method static \LaraGram\Listening\Listen onMonoForumRead(\Closure|array|string $action) Mono-forum read events
 * @method static \LaraGram\Listening\Listen onMonoForumNoPaid(\Closure|array|string $action) Mono-forum no-paid-exception events
 *
 * @method static \LaraGram\Listening\Listen onReactions(\Closure|array|string $action) Message reaction changes
 * @method static \LaraGram\Listening\Listen onBotReaction(\Closure|array|string $action) Bot message reaction (single)
 * @method static \LaraGram\Listening\Listen onBotReactions(\Closure|array|string $action) Bot message reactions (aggregate)
 * @method static \LaraGram\Listening\Listen onPollVote(\Closure|array|string $action) Poll votes
 * @method static \LaraGram\Listening\Listen onPollResults(\Closure|array|string $action) Poll result (vote-count) updates
 *
 * @method static \LaraGram\Listening\Listen onUserStatus(\Closure|array|string $action) Online/offline status changes
 * @method static \LaraGram\Listening\Listen onUserName(\Closure|array|string $action) Username changes
 * @method static \LaraGram\Listening\Listen onUserPhone(\Closure|array|string $action) Phone changes
 * @method static \LaraGram\Listening\Listen onUserEmojiStatus(\Closure|array|string $action) Emoji-status changes
 * @method static \LaraGram\Listening\Listen onUser(\Closure|array|string $action) Generic user object updates
 *
 * @method static \LaraGram\Listening\Listen onChatParticipant(\Closure|array|string $action) Participant changes (join/leave/promote/ban)
 * @method static \LaraGram\Listening\Listen onChatParticipants(\Closure|array|string $action) Full participant list changes
 * @method static \LaraGram\Listening\Listen onChatParticipantAdd(\Closure|array|string $action) Participant added
 * @method static \LaraGram\Listening\Listen onChatParticipantDelete(\Closure|array|string $action) Participant removed
 * @method static \LaraGram\Listening\Listen onChatParticipantAdmin(\Closure|array|string $action) Admin rights changed
 * @method static \LaraGram\Listening\Listen onChatParticipantRank(\Closure|array|string $action) Custom rank changed
 * @method static \LaraGram\Listening\Listen onChatDefaultBanned(\Closure|array|string $action) Default banned-rights changed
 * @method static \LaraGram\Listening\Listen onChatJoinRequest(\Closure|array|string $action) Chat join requests
 * @method static \LaraGram\Listening\Listen onChatBoost(\Closure|array|string $action) Chat boost events
 * @method static \LaraGram\Listening\Listen onChat(\Closure|array|string $action) Chat object updates
 * @method static \LaraGram\Listening\Listen onChannel(\Closure|array|string $action) Channel object updates
 * @method static \LaraGram\Listening\Listen onChannelTooLong(\Closure|array|string $action) Channel-too-long (gap) events
 * @method static \LaraGram\Listening\Listen onChannelAvailableMessages(\Closure|array|string $action) Channel available-messages events
 * @method static \LaraGram\Listening\Listen onChannelViewForumAsMessages(\Closure|array|string $action) View-forum-as-messages toggle
 *
 * @method static \LaraGram\Listening\Listen onPreCheckoutQuery(\Closure|array|string $action) Pre-checkout queries
 * @method static \LaraGram\Listening\Listen onShippingQuery(\Closure|array|string $action) Shipping queries
 * @method static \LaraGram\Listening\Listen onBotPurchasedPaid(\Closure|array|string $action) Purchased paid-media events
 * @method static \LaraGram\Listening\Listen onStarsBalance(\Closure|array|string $action) Stars balance changes
 * @method static \LaraGram\Listening\Listen onStarsRevenue(\Closure|array|string $action) Stars revenue status changes
 * @method static \LaraGram\Listening\Listen onPaidReactionPrivacy(\Closure|array|string $action) Paid-reaction privacy changes
 * @method static \LaraGram\Listening\Listen onStarGiftAuction(\Closure|array|string $action) Star-gift auction state
 * @method static \LaraGram\Listening\Listen onStarGiftAuctionUser(\Closure|array|string $action) User star-gift auction state
 * @method static \LaraGram\Listening\Listen onStarGiftCraftFail(\Closure|array|string $action) Star-gift craft failure
 *
 * @method static \LaraGram\Listening\Listen onPhoneCall(\Closure|array|string $action) Phone call events
 * @method static \LaraGram\Listening\Listen onPhoneCallSignaling(\Closure|array|string $action) Phone-call signaling data
 * @method static \LaraGram\Listening\Listen onGroupCall(\Closure|array|string $action) Group call events
 * @method static \LaraGram\Listening\Listen onGroupCallParticipants(\Closure|array|string $action) Group call participant changes
 * @method static \LaraGram\Listening\Listen onGroupCallConnection(\Closure|array|string $action) Group call connection updates
 * @method static \LaraGram\Listening\Listen onGroupCallMessage(\Closure|array|string $action) Group call messages
 * @method static \LaraGram\Listening\Listen onGroupCallDelete(\Closure|array|string $action) Deleted group call messages
 * @method static \LaraGram\Listening\Listen onGroupCallChain(\Closure|array|string $action) Group call chain-block updates
 * @method static \LaraGram\Listening\Listen onGroupCallEncrypted(\Closure|array|string $action) Encrypted group call messages
 *
 * @method static \LaraGram\Listening\Listen onStory(\Closure|array|string $action) New/updated stories
 * @method static \LaraGram\Listening\Listen onReadStories(\Closure|array|string $action) Read-stories events
 * @method static \LaraGram\Listening\Listen onStoryId(\Closure|array|string $action) Assigned story-id events
 * @method static \LaraGram\Listening\Listen onStoriesStealthMode(\Closure|array|string $action) Stories stealth-mode changes
 * @method static \LaraGram\Listening\Listen onStoryReaction(\Closure|array|string $action) Story reactions (sent/new)
 *
 * @method static \LaraGram\Listening\Listen onEncryptedMessage(\Closure|array|string $action) New encrypted messages
 * @method static \LaraGram\Listening\Listen onEncryptedChatTyping(\Closure|array|string $action) Encrypted-chat typing
 * @method static \LaraGram\Listening\Listen onEncryption(\Closure|array|string $action) Encryption (secret chat) state changes
 * @method static \LaraGram\Listening\Listen onEncryptedRead(\Closure|array|string $action) Encrypted-messages read events
 *
 * @method static \LaraGram\Listening\Listen onDraft(\Closure|array|string $action) Draft message changes
 * @method static \LaraGram\Listening\Listen onDialogPinned(\Closure|array|string $action) Dialog pinned/unpinned
 * @method static \LaraGram\Listening\Listen onPinnedDialogs(\Closure|array|string $action) Pinned-dialogs set changed
 * @method static \LaraGram\Listening\Listen onDialogUnreadMark(\Closure|array|string $action) Dialog unread-mark changed
 * @method static \LaraGram\Listening\Listen onDialogFilter(\Closure|array|string $action) Dialog filter (folder) changed
 * @method static \LaraGram\Listening\Listen onDialogFilterOrder(\Closure|array|string $action) Dialog-filter ordering changed
 * @method static \LaraGram\Listening\Listen onDialogFilters(\Closure|array|string $action) Full dialog-filters set changed
 * @method static \LaraGram\Listening\Listen onFolderPeers(\Closure|array|string $action) Folder-peers changes
 * @method static \LaraGram\Listening\Listen onSavedDialogPinned(\Closure|array|string $action) Saved dialog pinned/unpinned
 * @method static \LaraGram\Listening\Listen onPinnedSavedDialogs(\Closure|array|string $action) Pinned saved-dialogs set changed
 *
 * @method static \LaraGram\Listening\Listen onNotifySettings(\Closure|array|string $action) Notify-settings changes
 * @method static \LaraGram\Listening\Listen onServiceNotification(\Closure|array|string $action) Service notifications from Telegram
 * @method static \LaraGram\Listening\Listen onPrivacy(\Closure|array|string $action) Privacy-rule changes
 * @method static \LaraGram\Listening\Listen onPeerSettings(\Closure|array|string $action) Peer-settings changes
 * @method static \LaraGram\Listening\Listen onPeerLocated(\Closure|array|string $action) Peers-located (nearby) updates
 * @method static \LaraGram\Listening\Listen onPeerBlocked(\Closure|array|string $action) Peer blocked/unblocked
 * @method static \LaraGram\Listening\Listen onPeerHistoryTtl(\Closure|array|string $action) Peer history-TTL changes
 * @method static \LaraGram\Listening\Listen onPeerWallpaper(\Closure|array|string $action) Peer wallpaper changes
 * @method static \LaraGram\Listening\Listen onContactsReset(\Closure|array|string $action) Contacts reset
 * @method static \LaraGram\Listening\Listen onPendingJoinRequests(\Closure|array|string $action) Pending join-request count changes
 *
 * @method static \LaraGram\Listening\Listen onBotStopped(\Closure|array|string $action) Bot stopped/started events
 * @method static \LaraGram\Listening\Listen onBotCommands(\Closure|array|string $action) Bot command list updates
 * @method static \LaraGram\Listening\Listen onBotMenu(\Closure|array|string $action) Bot menu-button changes
 * @method static \LaraGram\Listening\Listen onWebviewResultSent(\Closure|array|string $action) Web-view result-sent events
 * @method static \LaraGram\Listening\Listen onAttachMenuBots(\Closure|array|string $action) Attach-menu bot changes
 * @method static \LaraGram\Listening\Listen onBotWebhook(\Closure|array|string $action) Bot webhook JSON events
 * @method static \LaraGram\Listening\Listen onBotWebhookQuery(\Closure|array|string $action) Bot webhook JSON query events
 * @method static \LaraGram\Listening\Listen onBotConnection(\Closure|array|string $action) New bot connection
 * @method static \LaraGram\Listening\Listen onBotGuestChat(\Closure|array|string $action) Bot guest-chat queries
 * @method static \LaraGram\Listening\Listen onManagedBot(\Closure|array|string $action) Managed-bot updates
 * @method static \LaraGram\Listening\Listen onJoinChatWebview(\Closure|array|string $action) Join-chat web-view decisions
 *
 * @method static \LaraGram\Listening\Listen onBusinessMessage(\Closure|array|string $action) New business messages
 * @method static \LaraGram\Listening\Listen onBusinessConnect(\Closure|array|string $action) Business connection events
 * @method static \LaraGram\Listening\Listen onBusinessEdit(\Closure|array|string $action) Edited business messages
 * @method static \LaraGram\Listening\Listen onBusinessDelete(\Closure|array|string $action) Deleted business messages
 * @method static \LaraGram\Listening\Listen onBusinessCallback(\Closure|array|string $action) Business callback queries
 *
 * @method static \LaraGram\Listening\Listen onForumTopic(\Closure|array|string $action) Forum topic pinned/unpinned
 * @method static \LaraGram\Listening\Listen onForumTopics(\Closure|array|string $action) Pinned forum-topics set changed
 *
 * @method static \LaraGram\Listening\Listen onNewStickerSet(\Closure|array|string $action) Newly installed sticker set
 * @method static \LaraGram\Listening\Listen onStickerSetsOrder(\Closure|array|string $action) Sticker-set ordering changes
 * @method static \LaraGram\Listening\Listen onStickerSets(\Closure|array|string $action) Full sticker-sets list changed
 * @method static \LaraGram\Listening\Listen onSavedGifs(\Closure|array|string $action) Saved-GIFs changes
 * @method static \LaraGram\Listening\Listen onFavedStickers(\Closure|array|string $action) Faved-stickers changes
 * @method static \LaraGram\Listening\Listen onRecentStickers(\Closure|array|string $action) Recent stickers/reactions/ringtones/emoji-statuses
 * @method static \LaraGram\Listening\Listen onEmojiGame(\Closure|array|string $action) Emoji-game info updates
 *
 * @method static \LaraGram\Listening\Listen onQuickReplies(\Closure|array|string $action) Quick-replies set changed
 * @method static \LaraGram\Listening\Listen onNewQuickReply(\Closure|array|string $action) New quick reply
 * @method static \LaraGram\Listening\Listen onDeleteQuickReply(\Closure|array|string $action) Deleted quick reply
 * @method static \LaraGram\Listening\Listen onQuickReplyMessage(\Closure|array|string $action) Quick-reply message
 * @method static \LaraGram\Listening\Listen onDeleteQuickReplyMessages(\Closure|array|string $action) Deleted quick-reply messages
 *
 * @method static \LaraGram\Listening\Listen onNewAuthorization(\Closure|array|string $action) New authorization (login on the account)
 * @method static \LaraGram\Listening\Listen onLoginToken(\Closure|array|string $action) Login-token / sent-phone-code events
 * @method static \LaraGram\Listening\Listen onWebPage(\Closure|array|string $action) Web-page (link preview) updates
 * @method static \LaraGram\Listening\Listen onConfig(\Closure|array|string $action) Config updates
 * @method static \LaraGram\Listening\Listen onDcOptions(\Closure|array|string $action) DC-options updates
 * @method static \LaraGram\Listening\Listen onPtsChanged(\Closure|array|string $action) pts-changed events
 * @method static \LaraGram\Listening\Listen onLangPack(\Closure|array|string $action) Language-pack updates
 * @method static \LaraGram\Listening\Listen onAutoSaveSettings(\Closure|array|string $action) Auto-save-settings changes
 * @method static \LaraGram\Listening\Listen onTheme(\Closure|array|string $action) Theme updates
 * @method static \LaraGram\Listening\Listen onSmsJob(\Closure|array|string $action) SMS-job updates
 * @method static \LaraGram\Listening\Listen onAiComposeTones(\Closure|array|string $action) AI compose-tones updates
 * @method static \LaraGram\Listening\Listen onWebBrowserException(\Closure|array|string $action) Web-browser exception updates
 * @method static \LaraGram\Listening\Listen onWebBrowserSettings(\Closure|array|string $action) Web-browser settings updates
 *
 * @method static \LaraGram\Listening\Listen onUpdate(\Closure|array|string $action) ANY update (catch-all fallback)
 * @method static \LaraGram\Listening\Listen fallback(\Closure|array|string $action) Register a fallback handler
 *
 * @method static \LaraGram\MTProto\Listening\ClientListenRegistrar middleware(array|string $middleware)
 * @method static \LaraGram\MTProto\Listening\ClientListenRegistrar forSessions(array|string $sessions) Scope the following listens to one or more sessions
 * @method static \LaraGram\MTProto\Listening\ClientListener group(array $attributes, \Closure|array|string $listens)
 *
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
