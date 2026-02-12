<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;

/**
 * Convenience methods for registering MTProto client listens.
 *
 * These methods map to ClientType verbs, not Bot API verbs.
 * Covers all update types from the Telegram TL schema (Layer 199+).
 *
 * Media-filtered handlers (onPhoto, onSticker, etc.) use a dedicated verb
 * so the pattern validator can discriminate within NEW_MESSAGE updates
 * based on the message's media type.
 */
trait ClientHandlerTrait
{
    // ════════════════════════════════════════════════════════════════════
    //  Messages
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for new messages (user, group, channel) — all types.
     */
    public function onMessage(Closure|array|string $action)
    {
        return $this->addListen('NEW_MESSAGE', '{clientMessagePlaceholder}', $action)
            ->where('clientMessagePlaceholder', '.*');
    }

    /**
     * Listen for new messages matching a text pattern.
     */
    public function onText(string $pattern, Closure|array|string $action)
    {
        return $this->addListen('NEW_MESSAGE', $pattern, $action);
    }

    /**
     * Listen for edited messages.
     */
    public function onEditedMessage(Closure|array|string $action)
    {
        return $this->addListen('EDIT_MESSAGE', '{clientEditPlaceholder}', $action)
            ->where('clientEditPlaceholder', '.*');
    }

    /**
     * Listen for deleted messages.
     */
    public function onDeletedMessages(Closure|array|string $action)
    {
        return $this->addListen('DELETED_MESSAGES', '{clientDeletePlaceholder}', $action)
            ->where('clientDeletePlaceholder', '.*');
    }

    /**
     * Listen for pinned message events.
     */
    public function onPinnedMessages(Closure|array|string $action)
    {
        return $this->addListen('PINNED_MESSAGES', '{clientPinnedPlaceholder}', $action)
            ->where('clientPinnedPlaceholder', '.*');
    }

    /**
     * Listen for scheduled messages.
     */
    public function onScheduledMessage(Closure|array|string $action)
    {
        return $this->addListen('SCHEDULED_MESSAGE', '{clientSchedPlaceholder}', $action)
            ->where('clientSchedPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Media-filtered message handlers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for photo messages.
     */
    public function onPhoto(Closure|array|string $action)
    {
        return $this->addListen('PHOTO', '{clientPhotoPlaceholder}', $action)
            ->where('clientPhotoPlaceholder', '.*');
    }

    /**
     * Listen for video messages.
     */
    public function onVideo(Closure|array|string $action)
    {
        return $this->addListen('VIDEO', '{clientVideoPlaceholder}', $action)
            ->where('clientVideoPlaceholder', '.*');
    }

    /**
     * Listen for animation (GIF) messages.
     */
    public function onAnimation(Closure|array|string $action)
    {
        return $this->addListen('ANIMATION', '{clientAnimPlaceholder}', $action)
            ->where('clientAnimPlaceholder', '.*');
    }

    /**
     * Listen for sticker messages.
     */
    public function onSticker(Closure|array|string $action)
    {
        return $this->addListen('STICKER', '{clientStickerPlaceholder}', $action)
            ->where('clientStickerPlaceholder', '.*');
    }

    /**
     * Listen for document (file) messages.
     */
    public function onDocument(Closure|array|string $action)
    {
        return $this->addListen('DOCUMENT', '{clientDocPlaceholder}', $action)
            ->where('clientDocPlaceholder', '.*');
    }

    /**
     * Listen for audio messages.
     */
    public function onAudio(Closure|array|string $action)
    {
        return $this->addListen('AUDIO', '{clientAudioPlaceholder}', $action)
            ->where('clientAudioPlaceholder', '.*');
    }

    /**
     * Listen for voice messages.
     */
    public function onVoice(Closure|array|string $action)
    {
        return $this->addListen('VOICE', '{clientVoicePlaceholder}', $action)
            ->where('clientVoicePlaceholder', '.*');
    }

    /**
     * Listen for video note (round video) messages.
     */
    public function onVideoNote(Closure|array|string $action)
    {
        return $this->addListen('VIDEO_NOTE', '{clientVideoNotePlaceholder}', $action)
            ->where('clientVideoNotePlaceholder', '.*');
    }

    /**
     * Listen for contact messages.
     */
    public function onContact(Closure|array|string $action)
    {
        return $this->addListen('CONTACT_MEDIA', '{clientContactPlaceholder}', $action)
            ->where('clientContactPlaceholder', '.*');
    }

    /**
     * Listen for location messages.
     */
    public function onLocation(Closure|array|string $action)
    {
        return $this->addListen('LOCATION', '{clientLocationPlaceholder}', $action)
            ->where('clientLocationPlaceholder', '.*');
    }

    /**
     * Listen for venue messages.
     */
    public function onVenue(Closure|array|string $action)
    {
        return $this->addListen('VENUE', '{clientVenuePlaceholder}', $action)
            ->where('clientVenuePlaceholder', '.*');
    }

    /**
     * Listen for game messages.
     */
    public function onGame(Closure|array|string $action)
    {
        return $this->addListen('GAME', '{clientGamePlaceholder}', $action)
            ->where('clientGamePlaceholder', '.*');
    }

    /**
     * Listen for dice messages.
     *
     * @param Closure|array|string $action
     * @param string|array $emoji Emoji filter ('any' = all, or specific emoji/array)
     * @param int|array $value Value filter (0 = any, or specific value/array)
     */
    public function onDice(Closure|array|string $action, string|array $emoji = 'any', int|array $value = 0)
    {
        $emojiStr = is_array($emoji) ? implode('|', $emoji) : $emoji;
        $valueStr = is_array($value) ? implode('|', $value) : (string) $value;

        return $this->addListen('DICE', "{$emojiStr},{$valueStr}", $action);
    }

    // ════════════════════════════════════════════════════════════════════
    //  Callback / Inline
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for callback queries (all).
     */
    public function onCallbackQuery(Closure|array|string $action)
    {
        return $this->addListen('CALLBACK_QUERY', '{clientCbPlaceholder}', $action)
            ->where('clientCbPlaceholder', '.*');
    }

    /**
     * Listen for callback queries matching a data pattern.
     */
    public function onCallbackQueryData(string $pattern, Closure|array|string $action)
    {
        return $this->addListen('CALLBACK_QUERY', $pattern, $action);
    }

    /**
     * Listen for inline bot queries.
     */
    public function onInlineQuery(Closure|array|string $action)
    {
        return $this->addListen('INLINE_QUERY', '{clientInlinePlaceholder}', $action)
            ->where('clientInlinePlaceholder', '.*');
    }

    /**
     * Listen for chosen inline results (user selected an inline result).
     */
    public function onChosenInlineResult(Closure|array|string $action)
    {
        return $this->addListen('CHOSEN_INLINE_RESULT', '{clientChosenInlinePlaceholder}', $action)
            ->where('clientChosenInlinePlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Typing
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for typing indicators (user/chat/channel).
     */
    public function onTyping(Closure|array|string $action)
    {
        return $this->addListen('TYPING', '{clientTypingPlaceholder}', $action)
            ->where('clientTypingPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Read history
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for read history events.
     */
    public function onReadHistory(Closure|array|string $action)
    {
        return $this->addListen('READ_HISTORY', '{clientReadPlaceholder}', $action)
            ->where('clientReadPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Reactions
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for message reaction changes.
     */
    public function onReactions(Closure|array|string $action)
    {
        return $this->addListen('REACTIONS', '{clientReactPlaceholder}', $action)
            ->where('clientReactPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Users
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for user online/offline status changes.
     */
    public function onUserStatus(Closure|array|string $action)
    {
        return $this->addListen('USER_STATUS', '{clientStatusPlaceholder}', $action)
            ->where('clientStatusPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Chat participants
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for chat/channel participant changes (join/leave/promoted/banned).
     */
    public function onChatParticipant(Closure|array|string $action)
    {
        return $this->addListen('CHAT_PARTICIPANT', '{clientParticipantPlaceholder}', $action)
            ->where('clientParticipantPlaceholder', '.*');
    }

    /**
     * Listen for chat join requests (via invite link).
     */
    public function onChatJoinRequest(Closure|array|string $action)
    {
        return $this->addListen('CHAT_JOIN_REQUEST', '{clientJoinReqPlaceholder}', $action)
            ->where('clientJoinReqPlaceholder', '.*');
    }

    /**
     * Listen for chat boost events.
     */
    public function onChatBoost(Closure|array|string $action)
    {
        return $this->addListen('CHAT_BOOST', '{clientBoostPlaceholder}', $action)
            ->where('clientBoostPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Polls
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for poll updates (results changed).
     */
    public function onPoll(Closure|array|string $action)
    {
        return $this->addListen('POLL', '{clientPollPlaceholder}', $action)
            ->where('clientPollPlaceholder', '.*');
    }

    /**
     * Listen for poll votes.
     */
    public function onPollVote(Closure|array|string $action)
    {
        return $this->addListen('POLL_VOTE', '{clientPollVotePlaceholder}', $action)
            ->where('clientPollVotePlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Payments
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for pre-checkout queries.
     */
    public function onPreCheckoutQuery(Closure|array|string $action)
    {
        return $this->addListen('PRE_CHECKOUT', '{clientPreCheckoutPlaceholder}', $action)
            ->where('clientPreCheckoutPlaceholder', '.*');
    }

    /**
     * Listen for shipping queries.
     */
    public function onShippingQuery(Closure|array|string $action)
    {
        return $this->addListen('SHIPPING', '{clientShippingPlaceholder}', $action)
            ->where('clientShippingPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Phone calls & Group calls
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for phone call events (incoming/outgoing).
     */
    public function onPhoneCall(Closure|array|string $action)
    {
        return $this->addListen('PHONE_CALL', '{clientPhoneCallPlaceholder}', $action)
            ->where('clientPhoneCallPlaceholder', '.*');
    }

    /**
     * Listen for group call events.
     */
    public function onGroupCall(Closure|array|string $action)
    {
        return $this->addListen('GROUP_CALL', '{clientGroupCallPlaceholder}', $action)
            ->where('clientGroupCallPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Stories
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for new/updated stories.
     */
    public function onStory(Closure|array|string $action)
    {
        return $this->addListen('STORY', '{clientStoryPlaceholder}', $action)
            ->where('clientStoryPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Encrypted (Secret chats)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for new encrypted messages.
     */
    public function onEncryptedMessage(Closure|array|string $action)
    {
        return $this->addListen('ENCRYPTED_MESSAGE', '{clientEncPlaceholder}', $action)
            ->where('clientEncPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Drafts
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for draft message changes.
     */
    public function onDraft(Closure|array|string $action)
    {
        return $this->addListen('DRAFT', '{clientDraftPlaceholder}', $action)
            ->where('clientDraftPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Notifications & Settings
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for service notifications from Telegram.
     */
    public function onServiceNotification(Closure|array|string $action)
    {
        return $this->addListen('SERVICE_NOTIFICATION', '{clientServiceNotifPlaceholder}', $action)
            ->where('clientServiceNotifPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Peers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for peer blocked/unblocked events.
     */
    public function onPeerBlocked(Closure|array|string $action)
    {
        return $this->addListen('PEER_BLOCKED', '{clientBlockedPlaceholder}', $action)
            ->where('clientBlockedPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Bots
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for bot stopped/started events.
     */
    public function onBotStopped(Closure|array|string $action)
    {
        return $this->addListen('BOT_STOPPED', '{clientBotStoppedPlaceholder}', $action)
            ->where('clientBotStoppedPlaceholder', '.*');
    }

    /**
     * Listen for bot command updates.
     */
    public function onBotCommands(Closure|array|string $action)
    {
        return $this->addListen('BOT_COMMANDS', '{clientBotCmdPlaceholder}', $action)
            ->where('clientBotCmdPlaceholder', '.*');
    }

    /**
     * Listen for bot reaction events.
     */
    public function onBotReaction(Closure|array|string $action)
    {
        return $this->addListen('BOT_REACTION', '{clientBotReactPlaceholder}', $action)
            ->where('clientBotReactPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Bot Business
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for new business messages (bot business connection).
     */
    public function onBusinessMessage(Closure|array|string $action)
    {
        return $this->addListen('BOT_BUSINESS_MESSAGE', '{clientBizMsgPlaceholder}', $action)
            ->where('clientBizMsgPlaceholder', '.*');
    }

    /**
     * Listen for business connection events.
     */
    public function onBusinessConnect(Closure|array|string $action)
    {
        return $this->addListen('BOT_BUSINESS_CONNECT', '{clientBizConnPlaceholder}', $action)
            ->where('clientBizConnPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Forum Topics
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for forum topic pin/unpin events.
     */
    public function onForumTopic(Closure|array|string $action)
    {
        return $this->addListen('PINNED_FORUM_TOPIC', '{clientForumPlaceholder}', $action)
            ->where('clientForumPlaceholder', '.*');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Catch-all
    // ════════════════════════════════════════════════════════════════════

    /**
     * Listen for any update (catch-all).
     * Fires for EVERY update that doesn't match a more specific handler.
     */
    public function onUpdate(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', '{clientUpdatePlaceholder}', $action)
            ->where('clientUpdatePlaceholder', '.*');
    }
}
