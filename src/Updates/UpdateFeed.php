<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\Generated\Types\Message;
use LaraGram\MTProto\Generated\Types\Update;
use LaraGram\MTProto\TL\TLObject;

class UpdateFeed
{
    private Client $client;
    private UpdateState $state;

    private ?Runtime $runtime = null;

    /**
     * The single callback that receives every individual update.
     * Signature: function(TLObject $update, string $type): void
     *
     * @var callable|null
     */
    private $onUpdate = null;

    /**
     * Named event callbacks: event_name -> callable[]
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

    /**
     * Channels currently fetching a channel difference (single-flight guard).
     * @var array<int, bool>
     */
    private array $fetchingChannelDifference = [];

    /**
     * Postponed updates per channel while that channel's difference is fetching.
     * @var array<int, array[]>
     */
    private array $channelPostponed = [];

    private ?\LaraGram\Log\LoggerInterface $logger;

    public function __construct(Client $client, UpdateState $state)
    {
        $this->client = $client;
        $this->state = $state;
        $this->logger = $client->getLogger();
    }

    public function setRuntime(Runtime $runtime): void
    {
        $this->runtime = $runtime;
    }

    /**
     * Run a handler in its own coroutine when a runtime is available.
     */
    private function runHandler(callable $run): void
    {
        if ($this->runtime !== null && $this->runtime->isSupported() && $this->runtime->inCoroutine()) {
            $this->runtime->spawn($run);
            return;
        }

        $run();
    }

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

    /**
     * Listen for new messages (user/group/channel).
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
     * Listen for poll updates.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPoll(callable $callback): void
    {
        $this->listeners['poll'][] = $callback;
    }

    /**
     * Listen for poll vote events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPollVote(callable $callback): void
    {
        $this->listeners['pollVote'][] = $callback;
    }

    /**
     * Listen for pinned message events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPinnedMessages(callable $callback): void
    {
        $this->listeners['pinnedMessages'][] = $callback;
    }

    /**
     * Listen for phone call events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPhoneCall(callable $callback): void
    {
        $this->listeners['phoneCall'][] = $callback;
    }

    /**
     * Listen for group call events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onGroupCall(callable $callback): void
    {
        $this->listeners['groupCall'][] = $callback;
    }

    /**
     * Listen for story events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onStory(callable $callback): void
    {
        $this->listeners['story'][] = $callback;
    }

    /**
     * Listen for new encrypted messages (secret chats).
     *
     * @param callable(Update, Client): void $callback
     */
    public function onEncryptedMessage(callable $callback): void
    {
        $this->listeners['encryptedMessage'][] = $callback;
    }

    /**
     * Listen for draft message changes.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onDraft(callable $callback): void
    {
        $this->listeners['draft'][] = $callback;
    }

    /**
     * Listen for service notifications from Telegram.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onServiceNotification(callable $callback): void
    {
        $this->listeners['serviceNotification'][] = $callback;
    }

    /**
     * Listen for bot stopped/started events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onBotStopped(callable $callback): void
    {
        $this->listeners['botStopped'][] = $callback;
    }

    /**
     * Listen for chat join request events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChatJoinRequest(callable $callback): void
    {
        $this->listeners['chatJoinRequest'][] = $callback;
    }

    /**
     * Listen for chat boost events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onChatBoost(callable $callback): void
    {
        $this->listeners['chatBoost'][] = $callback;
    }

    /**
     * Listen for peer blocked/unblocked events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onPeerBlocked(callable $callback): void
    {
        $this->listeners['peerBlocked'][] = $callback;
    }

    /**
     * Listen for new authorization events.
     *
     * @param callable(Update, Client): void $callback
     */
    public function onNewAuthorization(callable $callback): void
    {
        $this->listeners['newAuthorization'][] = $callback;
    }

    /**
     * Initialise/refresh state from server.
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

    /**
     * Process a raw Updates container received from the socket.
     *
     * @param array $data Deserialized TL Updates object (has '_' key).
     */
    public function feed(array $data): void
    {
        $type = $data['_'] ?? '';

        match ($type) {
            'updates' => $this->handleUpdates($data),
            'updatesCombined' => $this->handleUpdatesCombined($data),
            'updateShort' => $this->handleUpdateShort($data),
            'updateShortMessage' => $this->handleUpdateShortMessage($data),
            'updateShortChatMessage' => $this->handleUpdateShortChatMessage($data),
            'updateShortSentMessage' => $this->handleUpdateShortSentMessage($data),
            'updatesTooLong' => $this->fetchDifference(),
            default => null, // Ignore unknown containers
        };
    }

    /**
     * updates#74ae4240
     */
    private function handleUpdates(array $data): void
    {
        $seq = $data['seq'] ?? 0;

        if ($seq > 0 && $this->state->getSeq() > 0) {
            if ($seq <= $this->state->getSeq()) {
                return;
            }
        }

        $this->cacheEntities($data);

        if ($seq > 0) {
            $this->state->setSeq($seq);
        }
        if (isset($data['date'])) {
            $this->state->setDate(max($this->state->getDate(), $data['date']));
        }

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
                return;
            }
        }

        $this->handleUpdates($data);
    }

    /**
     * updateShort#78d4dec1 - single stateless update
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
     * updateShortMessage#313bc7f8 - incoming DM, convert to full message
     */
    private function handleUpdateShortMessage(array $data): void
    {
        $message = $this->shortMessageToFull($data, isChat: false);
        $this->processUpdate([
            '_' => 'updateNewMessage',
            'message' => $message,
            'pts' => $data['pts'],
            'pts_count' => $data['pts_count'],
        ]);
        $this->state->save();
    }

    /**
     * updateShortChatMessage#4d6deea5 - incoming group message
     */
    private function handleUpdateShortChatMessage(array $data): void
    {
        $message = $this->shortMessageToFull($data, isChat: true);
        $this->processUpdate([
            '_' => 'updateNewMessage',
            'message' => $message,
            'pts' => $data['pts'],
            'pts_count' => $data['pts_count'],
        ]);
        $this->state->save();
    }

    /**
     * updateShortSentMessage - our own sent message confirmed
     */
    private function handleUpdateShortSentMessage(array $data): void
    {
        $this->dispatchEvent('sentMessage', TLObject::fromArray($data));
    }

    /**
     * Process a single Update constructor.
     */
    private function processUpdate(array $update): void
    {
        $type = $update['_'] ?? '';

        if ($type === 'updateChannelTooLong') {
            $channelId = (int)($update['channel_id'] ?? 0);
            if ($channelId > 0) {
                if ($this->state->getChannelPts($channelId) > 0) {
                    $this->fetchChannelDifference($channelId);
                } elseif (isset($update['pts'])) {
                    $this->state->setChannelPts($channelId, (int)$update['pts']);
                    $this->state->save();
                }
            }
            $this->dispatch($update);
            return;
        }

        if (isset($update['pts']) && $update['pts'] > 0) {
            $ptsCount = $update['pts_count'] ?? 0;
            $channelId = $this->extractChannelId($update);

            if ($channelId !== null) {
                $gap = $this->state->checkChannelPtsGap($channelId, $update['pts'], $ptsCount);
                if ($gap > 0) {
                    return;
                }
                if ($gap < 0) {
                    $this->channelPostponed[$channelId][] = $update;
                    $this->fetchChannelDifference($channelId);
                    return;
                }
                $this->state->setChannelPts($channelId, $update['pts']);
            } else {
                $gap = $this->state->checkPtsGap($update['pts'], $ptsCount);
                if ($gap > 0) {
                    return;
                }
                if ($gap < 0) {
                    $this->postponed[] = $update;
                    $this->fetchDifference();
                    return;
                }
                $this->state->setPts($update['pts']);
            }
        }

        if (isset($update['qts']) && $update['qts'] > 0) {
            $currentQts = $this->state->getQts();
            if ($currentQts > 0 && $update['qts'] <= $currentQts) {
                return;
            }
            $this->state->setQts($update['qts']);
        }

        $this->dispatch($update);
    }

    /**
     * Wrap raw array into TLObject and invoke handlers.
     */
    private function dispatch(array $update): void
    {
        $type = $update['_'] ?? 'unknown';
        $wrapped = TLObject::fromArray($update);

        if ($this->isOutgoingMessage($update)) {
            return;
        }

        if ($this->onUpdate !== null) {
            $handler = $this->onUpdate;

            $run = function () use ($handler, $wrapped, $type): void {
                try {
                    $handler($wrapped, $type);
                } catch (\Throwable $e) {
                    $this->dispatchEvent('error', TLObject::fromArray([
                        '_' => 'error',
                        'event' => $type,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]));
                }
            };

            $this->runHandler($run);
        }

        $this->dispatchEvent($type, $wrapped);

        match ($type) {
            // ── Messages ───────────────────────────────────────────────
            'updateNewMessage',
            'updateNewChannelMessage' => $this->dispatchEvent('message', $this->extractMessage($wrapped)),

            'updateEditMessage',
            'updateEditChannelMessage' => $this->dispatchEvent('editedMessage', $this->extractMessage($wrapped)),

            'updateDeleteMessages',
            'updateDeleteChannelMessages' => $this->dispatchEvent('deletedMessages', $wrapped),

            'updateMessageID' => $this->dispatchEvent('messageId', $wrapped),

            'updatePinnedMessages',
            'updatePinnedChannelMessages' => $this->dispatchEvent('pinnedMessages', $wrapped),

            'updateReadMessagesContents',
            'updateChannelReadMessagesContents' => $this->dispatchEvent('readContents', $wrapped),

            'updateChannelMessageViews' => $this->dispatchEvent('messageViews', $wrapped),
            'updateChannelMessageForwards' => $this->dispatchEvent('messageForwards', $wrapped),
            'updateMessageExtendedMedia' => $this->dispatchEvent('messageExtendedMedia', $wrapped),
            'updateTranscribedAudio' => $this->dispatchEvent('transcribedAudio', $wrapped),
            'updateGeoLiveViewed' => $this->dispatchEvent('geoLiveViewed', $wrapped),

            'updateNewScheduledMessage' => $this->dispatchEvent('scheduledMessage', $wrapped),
            'updateDeleteScheduledMessages' => $this->dispatchEvent('deleteScheduled', $wrapped),

            // ── Polls ──────────────────────────────────────────────────
            'updateMessagePoll' => $this->dispatchEvent('poll', $wrapped),
            'updateMessagePollVote' => $this->dispatchEvent('pollVote', $wrapped),

            // ── Web pages ──────────────────────────────────────────────
            'updateWebPage',
            'updateChannelWebPage' => $this->dispatchEvent('webPage', $wrapped),

            // ── Read history ───────────────────────────────────────────
            'updateReadHistoryInbox',
            'updateReadHistoryOutbox',
            'updateReadChannelInbox',
            'updateReadChannelOutbox' => $this->dispatchEvent('readHistory', $wrapped),

            'updateReadChannelDiscussionInbox',
            'updateReadChannelDiscussionOutbox' => $this->dispatchEvent('readDiscussion', $wrapped),

            // ── Callback / Inline ──────────────────────────────────────
            'updateBotCallbackQuery',
            'updateInlineBotCallbackQuery' => $this->dispatchEvent('callbackQuery', $wrapped),

            'updateBotInlineQuery' => $this->dispatchEvent('inlineQuery', $wrapped),
            'updateBotInlineSend' => $this->dispatchEvent('chosenInlineResult', $wrapped),

            // ── Typing ─────────────────────────────────────────────────
            'updateUserTyping',
            'updateChatUserTyping',
            'updateChannelUserTyping' => $this->dispatchEvent('typing', $wrapped),

            // ── Reactions ──────────────────────────────────────────────
            'updateMessageReactions' => $this->dispatchEvent('reactions', $wrapped),

            // ── Users ──────────────────────────────────────────────────
            'updateUserStatus' => $this->dispatchEvent('userStatus', $wrapped),
            'updateUserName' => $this->dispatchEvent('userName', $wrapped),
            'updateUserPhone' => $this->dispatchEvent('userPhone', $wrapped),
            'updateUserEmojiStatus' => $this->dispatchEvent('userEmojiStatus', $wrapped),
            'updateUser' => $this->dispatchEvent('userUpdate', $wrapped),

            // ── Chats / Channels ───────────────────────────────────────
            'updateChatParticipant',
            'updateChannelParticipant' => $this->dispatchEvent('chatParticipant', $wrapped),

            'updateChatParticipants' => $this->dispatchEvent('chatParticipants', $wrapped),
            'updateChatParticipantAdd' => $this->dispatchEvent('chatParticipantAdd', $wrapped),
            'updateChatParticipantDelete' => $this->dispatchEvent('chatParticipantDelete', $wrapped),
            'updateChatParticipantAdmin' => $this->dispatchEvent('chatParticipantAdmin', $wrapped),
            'updateChatDefaultBannedRights' => $this->dispatchEvent('chatDefaultBanned', $wrapped),
            'updateChat' => $this->dispatchEvent('chatUpdate', $wrapped),
            'updateChannel' => $this->dispatchEvent('channelUpdate', $wrapped),
            'updateChannelTooLong' => $this->dispatchEvent('channelTooLong', $wrapped),
            'updateChannelAvailableMessages' => $this->dispatchEvent('channelAvailableMessages', $wrapped),
            'updateChannelViewForumAsMessages' => $this->dispatchEvent('channelViewForumAsMessages', $wrapped),

            // ── Payments ───────────────────────────────────────────────
            'updateBotPrecheckoutQuery' => $this->dispatchEvent('precheckoutQuery', $wrapped),
            'updateBotShippingQuery' => $this->dispatchEvent('shippingQuery', $wrapped),

            // ── Phone calls ────────────────────────────────────────────
            'updatePhoneCall' => $this->dispatchEvent('phoneCall', $wrapped),
            'updatePhoneCallSignalingData' => $this->dispatchEvent('phoneCallSignaling', $wrapped),

            // ── Group calls ────────────────────────────────────────────
            'updateGroupCall' => $this->dispatchEvent('groupCall', $wrapped),
            'updateGroupCallParticipants' => $this->dispatchEvent('groupCallParticipants', $wrapped),
            'updateGroupCallConnection' => $this->dispatchEvent('groupCallConnection', $wrapped),

            // ── Stories ────────────────────────────────────────────────
            'updateStory' => $this->dispatchEvent('story', $wrapped),
            'updateReadStories' => $this->dispatchEvent('readStories', $wrapped),
            'updateStoryID' => $this->dispatchEvent('storyId', $wrapped),
            'updateStoriesStealthMode' => $this->dispatchEvent('storiesStealthMode', $wrapped),
            'updateSentStoryReaction',
            'updateNewStoryReaction' => $this->dispatchEvent('storyReaction', $wrapped),

            // ── Encrypted (Secret chats) ───────────────────────────────
            'updateNewEncryptedMessage' => $this->dispatchEvent('encryptedMessage', $wrapped),
            'updateEncryptedChatTyping' => $this->dispatchEvent('encryptedChatTyping', $wrapped),
            'updateEncryption' => $this->dispatchEvent('encryption', $wrapped),
            'updateEncryptedMessagesRead' => $this->dispatchEvent('encryptedRead', $wrapped),

            // ── Drafts ─────────────────────────────────────────────────
            'updateDraftMessage' => $this->dispatchEvent('draft', $wrapped),

            // ── Notifications & Settings ───────────────────────────────
            'updateNotifySettings' => $this->dispatchEvent('notifySettings', $wrapped),
            'updateServiceNotification' => $this->dispatchEvent('serviceNotification', $wrapped),
            'updatePrivacy' => $this->dispatchEvent('privacy', $wrapped),

            // ── Dialogs & Folders ──────────────────────────────────────
            'updateDialogPinned' => $this->dispatchEvent('dialogPinned', $wrapped),
            'updatePinnedDialogs' => $this->dispatchEvent('pinnedDialogs', $wrapped),
            'updateDialogUnreadMark' => $this->dispatchEvent('dialogUnreadMark', $wrapped),
            'updateDialogFilter' => $this->dispatchEvent('dialogFilter', $wrapped),
            'updateDialogFilterOrder' => $this->dispatchEvent('dialogFilterOrder', $wrapped),
            'updateDialogFilters' => $this->dispatchEvent('dialogFilters', $wrapped),
            'updateFolderPeers' => $this->dispatchEvent('folderPeers', $wrapped),
            'updateSavedDialogPinned' => $this->dispatchEvent('savedDialogPinned', $wrapped),
            'updatePinnedSavedDialogs' => $this->dispatchEvent('pinnedSavedDialogs', $wrapped),

            // ── Bots ───────────────────────────────────────────────────
            'updateBotStopped' => $this->dispatchEvent('botStopped', $wrapped),
            'updateBotCommands' => $this->dispatchEvent('botCommands', $wrapped),
            'updateBotMenuButton' => $this->dispatchEvent('botMenu', $wrapped),
            'updateBotChatInviteRequester' => $this->dispatchEvent('chatJoinRequest', $wrapped),
            'updateBotChatBoost' => $this->dispatchEvent('chatBoost', $wrapped),
            'updateBotMessageReaction' => $this->dispatchEvent('botReaction', $wrapped),
            'updateBotMessageReactions' => $this->dispatchEvent('botReactions', $wrapped),
            'updateBotPurchasedPaidMedia' => $this->dispatchEvent('botPurchasedPaid', $wrapped),
            'updateBotWebhookJSON' => $this->dispatchEvent('botWebhook', $wrapped),
            'updateBotWebhookJSONQuery' => $this->dispatchEvent('botWebhookQuery', $wrapped),
            'updateWebViewResultSent' => $this->dispatchEvent('webviewResultSent', $wrapped),
            'updateAttachMenuBots' => $this->dispatchEvent('attachMenuBots', $wrapped),

            // ── Bot Business ───────────────────────────────────────────
            'updateBotBusinessConnect' => $this->dispatchEvent('botBusinessConnect', $wrapped),
            'updateBotNewBusinessMessage' => $this->dispatchEvent('botBusinessMessage', $wrapped),
            'updateBotEditBusinessMessage' => $this->dispatchEvent('botBusinessEdit', $wrapped),
            'updateBotDeleteBusinessMessage' => $this->dispatchEvent('botBusinessDelete', $wrapped),
            'updateBusinessBotCallbackQuery' => $this->dispatchEvent('businessCallback', $wrapped),

            // ── Stickers & Emoji ───────────────────────────────────────
            'updateNewStickerSet' => $this->dispatchEvent('newStickerSet', $wrapped),
            'updateStickerSetsOrder',
            'updateMoveStickerSetToTop' => $this->dispatchEvent('stickerSetsOrder', $wrapped),
            'updateStickerSets' => $this->dispatchEvent('stickerSets', $wrapped),
            'updateSavedGifs' => $this->dispatchEvent('savedGifs', $wrapped),
            'updateFavedStickers' => $this->dispatchEvent('favedStickers', $wrapped),
            'updateRecentStickers',
            'updateReadFeaturedStickers',
            'updateReadFeaturedEmojiStickers',
            'updateRecentEmojiStatuses',
            'updateRecentReactions',
            'updateSavedReactionTags',
            'updateSavedRingtones' => $this->dispatchEvent('recentStickers', $wrapped),

            // ── Forum Topics ───────────────────────────────────────────
            'updatePinnedForumTopic' => $this->dispatchEvent('pinnedForumTopic', $wrapped),
            'updatePinnedForumTopics' => $this->dispatchEvent('pinnedForumTopics', $wrapped),

            // ── Peers ──────────────────────────────────────────────────
            'updatePeerSettings' => $this->dispatchEvent('peerSettings', $wrapped),
            'updatePeerLocated' => $this->dispatchEvent('peerLocated', $wrapped),
            'updatePeerBlocked' => $this->dispatchEvent('peerBlocked', $wrapped),
            'updatePeerHistoryTTL' => $this->dispatchEvent('peerHistoryTTL', $wrapped),
            'updatePeerWallpaper' => $this->dispatchEvent('peerWallpaper', $wrapped),
            'updateContactsReset' => $this->dispatchEvent('contactsReset', $wrapped),
            'updatePendingJoinRequests' => $this->dispatchEvent('pendingJoinRequests', $wrapped),

            // ── Auth & Security ────────────────────────────────────────
            'updateNewAuthorization' => $this->dispatchEvent('newAuthorization', $wrapped),
            'updateLoginToken',
            'updateSentPhoneCode' => $this->dispatchEvent('loginToken', $wrapped),

            // ── Stars & Payments ───────────────────────────────────────
            'updateStarsBalance' => $this->dispatchEvent('starsBalance', $wrapped),
            'updateStarsRevenueStatus' => $this->dispatchEvent('starsRevenue', $wrapped),
            'updatePaidReactionPrivacy' => $this->dispatchEvent('paidReactionPrivacy', $wrapped),

            // ── Quick Replies ──────────────────────────────────────────
            'updateQuickReplies' => $this->dispatchEvent('quickReplies', $wrapped),
            'updateNewQuickReply' => $this->dispatchEvent('newQuickReply', $wrapped),
            'updateDeleteQuickReply' => $this->dispatchEvent('deleteQuickReply', $wrapped),
            'updateQuickReplyMessage' => $this->dispatchEvent('quickReplyMessage', $wrapped),
            'updateDeleteQuickReplyMessages' => $this->dispatchEvent('deleteQuickReplyMessages', $wrapped),

            // ── Config & System ────────────────────────────────────────
            'updateConfig' => $this->dispatchEvent('config', $wrapped),
            'updateDcOptions' => $this->dispatchEvent('dcOptions', $wrapped),
            'updatePtsChanged' => $this->dispatchEvent('ptsChanged', $wrapped),
            'updateLangPack' => $this->dispatchEvent('langPack', $wrapped),
            'updateLangPackTooLong' => $this->dispatchEvent('langPackTooLong', $wrapped),
            'updateAutoSaveSettings' => $this->dispatchEvent('autoSaveSettings', $wrapped),

            // ── Themes ─────────────────────────────────────────────────
            'updateTheme' => $this->dispatchEvent('theme', $wrapped),

            default => null,
        };

        // 4. Wildcard
        $this->dispatchEvent('*', $wrapped);
    }

    /**
     * Fire event callbacks.
     */
    private function dispatchEvent(string $event, TLObject $data): void
    {
        foreach ($this->listeners[$event] ?? [] as $handler) {
            $client = $this->client;

            $run = function () use ($handler, $data, $event, $client): void {
                try {
                    $handler($data, $client);
                } catch (\Throwable $e) {
                    if ($event !== 'error') {
                        $this->dispatchEvent('error', TLObject::fromArray([
                            '_' => 'error',
                            'event' => $event,
                            'error' => $e->getMessage(),
                        ]));
                    }
                }
            };

            $this->runHandler($run);
        }
    }

    /**
     * Extract the inner `message` from an updateNewMessage-style update.
     *
     * @return Message
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

    /**
     * Fetch common updates difference from server.
     */
    public function fetchDifference(): void
    {
        if ($this->fetchingDifference) {
            return;
        }
        $this->fetchingDifference = true;
        $ptsBefore = $this->state->getPts();

        try {
            $this->doFetchDifference();
        } finally {
            $this->fetchingDifference = false;

            $postponed = $this->postponed;
            $this->postponed = [];

            if ($postponed !== []) {
                if ($ptsBefore !== 0 && $this->state->getPts() <= $ptsBefore) {
                    $this->logger?->warning(
                        'fetchDifference made no pts progress - dropping ' . count($postponed) . ' postponed update(s)'
                    );
                } else {
                    foreach ($postponed as $upd) {
                        $this->processUpdate($upd);
                    }
                }
            }
        }
    }

    private function doFetchDifference(): void
    {
        $result = $this->client->invoke('updates.getDifference', [
            'pts' => $this->state->getPts(),
            'date' => $this->state->getDate(),
            'qts' => $this->state->getQts(),
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
                // More data available - recurse
                $this->doFetchDifference();
                break;

            case 'updates.differenceTooLong':
                $this->state->setPts($result['pts']);
                break;
        }

        $this->state->save();
    }

    /**
     * Resync a channel after a detected pts gap (or `updateChannelTooLong`).
     */
    public function fetchChannelDifference(int $channelId): void
    {
        if (!empty($this->fetchingChannelDifference[$channelId])) {
            return;
        }

        $pts = $this->state->getChannelPts($channelId);
        if ($pts === 0) {
            unset($this->channelPostponed[$channelId]);
            return;
        }

        $this->fetchingChannelDifference[$channelId] = true;

        try {
            $this->doFetchChannelDifference($channelId, $pts);
        } finally {
            unset($this->fetchingChannelDifference[$channelId]);

            $postponed = $this->channelPostponed[$channelId] ?? [];
            unset($this->channelPostponed[$channelId]);

            if ($postponed !== []) {
                if ($this->state->getChannelPts($channelId) <= $pts) {
                    $this->logger?->warning(
                        "getChannelDifference for {$channelId} made no pts progress - dropping " . count($postponed) . ' postponed update(s)'
                    );
                } else {
                    foreach ($postponed as $upd) {
                        $this->processUpdate($upd);
                    }
                }
            }
        }
    }

    private function doFetchChannelDifference(int $channelId, int $pts): void
    {
        try {
            $result = $this->client->invoke('updates.getChannelDifference', [
                'force' => false,
                'channel' => $channelId,
                'filter' => ['_' => 'channelMessagesFilterEmpty'],
                'pts' => $pts,
                'limit' => 100,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning("Failed to get channel difference for {$channelId}: {$e->getMessage()}");
            return;
        }

        $type = $result['_'] ?? '';

        switch ($type) {
            case 'updates.channelDifferenceEmpty':
                if (isset($result['pts'])) {
                    $this->state->setChannelPts($channelId, $result['pts']);
                }
                break;

            case 'updates.channelDifference':
                $this->cacheEntities($result);
                foreach ($result['new_messages'] ?? [] as $msg) {
                    $this->dispatch([
                        '_' => 'updateNewChannelMessage',
                        'message' => $msg,
                        'pts' => $result['pts'] ?? $pts,
                        'pts_count' => 0,
                    ]);
                }
                foreach ($result['other_updates'] ?? [] as $upd) {
                    $this->dispatch($upd);
                }
                $this->state->setChannelPts($channelId, $result['pts']);
                $this->state->save();

                if (empty($result['final'])) {
                    $this->doFetchChannelDifference($channelId, $result['pts']);
                    return;
                }
                break;

            case 'updates.channelDifferenceTooLong':
                $this->cacheEntities($result);
                $newPts = $result['dialog']['pts'] ?? $result['pts'] ?? $pts;
                $this->state->setChannelPts($channelId, $newPts);
                break;
        }

        $this->state->save();
    }

    /**
     * Apply a difference result (messages + other_updates).
     */
    private function applyDifference(array $data): void
    {
        $this->cacheEntities($data);

        foreach ($data['new_messages'] ?? [] as $msg) {
            $this->dispatch([
                '_' => 'updateNewMessage',
                'message' => $msg,
                'pts' => 0,
                'pts_count' => 0,
            ]);
        }

        foreach ($data['new_encrypted_messages'] ?? [] as $msg) {
            $this->dispatch([
                '_' => 'updateNewEncryptedMessage',
                'message' => $msg,
                'qts' => 0,
            ]);
        }

        foreach ($data['other_updates'] ?? [] as $upd) {
            $this->dispatch($upd);
        }
    }

    /**
     * Convert updateShortMessage / updateShortChatMessage to a full message array.
     */
    private function shortMessageToFull(array $data, bool $isChat): array
    {
        $message = [
            '_' => 'message',
            'id' => $data['id'],
            'message' => $data['message'],
            'date' => $data['date'],
            'out' => $data['out'] ?? false,
            'mentioned' => $data['mentioned'] ?? false,
            'media_unread' => $data['media_unread'] ?? false,
            'silent' => $data['silent'] ?? false,
            'fwd_from' => $data['fwd_from'] ?? null,
            'via_bot_id' => $data['via_bot_id'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'entities' => $data['entities'] ?? [],
            'ttl_period' => $data['ttl_period'] ?? null,
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

        if (isset($update['channel_id'])) {
            return $update['channel_id'];
        }

        if (isset($update['message']['peer_id']['channel_id'])) {
            return $update['message']['peer_id']['channel_id'];
        }

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

        $peerDb->save();
    }

    /**
     * Get the current user's ID (best-effort).
     */
    private function getSelfId(): int
    {
        return 0;
    }

    /**
     * Check whether an update represents an outgoing message.
     */
    private function isOutgoingMessage(array $update): bool
    {
        $type = $update['_'] ?? '';

        if ($type === 'updateShortSentMessage') {
            return true;
        }

        if ($type === 'updateShortMessage' || $type === 'updateShortChatMessage') {
            return !empty($update['out']);
        }

        if ($type === 'updateNewMessage' || $type === 'updateNewChannelMessage') {
            return !empty($update['message']['out']);
        }

        if ($type === 'updateEditMessage' || $type === 'updateEditChannelMessage') {
            return !empty($update['message']['out']);
        }

        return false;
    }
}
