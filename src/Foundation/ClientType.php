<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

enum ClientType: string
{
    case UPDATE = 'update';
    case MESSAGE = 'message';
    case TEXT = 'text';
    case COMMAND = 'command';
    case REFERRAL = 'referral';
    case CALLBACK_DATA = 'callback_data';
    case DICE = 'dice';
    case ENTITIES = 'entities';

    const EVENTS = [
        'updateNewMessage' => 'new_message',
        'updateNewChannelMessage' => 'new_message',
        'updateEditMessage' => 'edited_message',
        'updateEditChannelMessage' => 'edited_message',
        'updateDeleteMessages' => 'deleted_messages',
        'updateDeleteChannelMessages' => 'deleted_messages',
        'updateMessageID' => 'message_id',
        'updatePinnedMessages' => 'pinned_messages',
        'updatePinnedChannelMessages' => 'pinned_messages',
        'updateReadMessagesContents' => 'read_contents',
        'updateChannelReadMessagesContents' => 'read_contents',
        'updateChannelMessageViews' => 'message_views',
        'updateChannelMessageForwards' => 'message_forwards',
        'updateMessageExtendedMedia' => 'message_extended_media',
        'updateTranscribedAudio' => 'transcribed_audio',
        'updateGeoLiveViewed' => 'geo_live_viewed',
        'updateNewScheduledMessage' => 'scheduled_message',
        'updateDeleteScheduledMessages' => 'delete_scheduled',
        'updateShortSentMessage' => 'sent_message',

        'updateMessagePoll' => 'poll',
        'updateMessagePollVote' => 'poll_vote',

        'updateWebPage' => 'web_page',
        'updateChannelWebPage' => 'web_page',

        'updateReadHistoryInbox' => 'read_history',
        'updateReadHistoryOutbox' => 'read_history',
        'updateReadChannelInbox' => 'read_history',
        'updateReadChannelOutbox' => 'read_history',
        'updateReadChannelDiscussionInbox' => 'read_discussion',
        'updateReadChannelDiscussionOutbox' => 'read_discussion',
        'updateReadMonoForumInbox' => 'mono_forum_read',
        'updateReadMonoForumOutbox' => 'mono_forum_read',
        'updateMonoForumNoPaidException' => 'mono_forum_no_paid',

        'updateBotCallbackQuery' => 'callback_query',
        'updateInlineBotCallbackQuery' => 'callback_query',
        'updateBotInlineQuery' => 'inline_query',
        'updateBotInlineSend' => 'chosen_inline_result',

        'updateUserTyping' => 'typing',
        'updateChatUserTyping' => 'typing',
        'updateChannelUserTyping' => 'typing',

        'updateMessageReactions' => 'reactions',
        'updateBotMessageReaction' => 'bot_reaction',
        'updateBotMessageReactions' => 'bot_reactions',

        'updateUserStatus' => 'user_status',
        'updateUserName' => 'user_name',
        'updateUserPhone' => 'user_phone',
        'updateUserEmojiStatus' => 'user_emoji_status',
        'updateUser' => 'user_update',

        'updateChatParticipant' => 'chat_participant',
        'updateChannelParticipant' => 'chat_participant',
        'updateChatParticipants' => 'chat_participants',
        'updateChatParticipantAdd' => 'chat_participant_add',
        'updateChatParticipantDelete' => 'chat_participant_delete',
        'updateChatParticipantAdmin' => 'chat_participant_admin',
        'updateChatParticipantRank' => 'chat_participant_rank',
        'updateChatDefaultBannedRights' => 'chat_default_banned',
        'updateChat' => 'chat_update',
        'updateChannel' => 'channel_update',
        'updateChannelTooLong' => 'channel_too_long',
        'updateChannelAvailableMessages' => 'channel_available_messages',
        'updateChannelViewForumAsMessages' => 'channel_view_forum_as_messages',

        'updateBotPrecheckoutQuery' => 'pre_checkout',
        'updateBotShippingQuery' => 'shipping',
        'updateBotPurchasedPaidMedia' => 'bot_purchased_paid',

        'updatePhoneCall' => 'phone_call',
        'updatePhoneCallSignalingData' => 'phone_call_signaling',

        'updateGroupCall' => 'group_call',
        'updateGroupCallParticipants' => 'group_call_participants',
        'updateGroupCallConnection' => 'group_call_connection',
        'updateGroupCallMessage' => 'group_call_message',
        'updateDeleteGroupCallMessages' => 'group_call_delete',
        'updateGroupCallChainBlocks' => 'group_call_chain',
        'updateGroupCallEncryptedMessage' => 'group_call_encrypted',

        'updateStory' => 'story',
        'updateReadStories' => 'read_stories',
        'updateStoryID' => 'story_id',
        'updateStoriesStealthMode' => 'stories_stealth_mode',
        'updateSentStoryReaction' => 'story_reaction',
        'updateNewStoryReaction' => 'story_reaction',

        'updateNewEncryptedMessage' => 'encrypted_message',
        'updateEncryptedChatTyping' => 'encrypted_chat_typing',
        'updateEncryption' => 'encryption',
        'updateEncryptedMessagesRead' => 'encrypted_read',

        'updateDraftMessage' => 'draft',

        'updateNotifySettings' => 'notify_settings',
        'updateServiceNotification' => 'service_notification',
        'updatePrivacy' => 'privacy',

        'updateDialogPinned' => 'dialog_pinned',
        'updatePinnedDialogs' => 'pinned_dialogs',
        'updateDialogUnreadMark' => 'dialog_unread_mark',
        'updateDialogFilter' => 'dialog_filter',
        'updateDialogFilterOrder' => 'dialog_filter_order',
        'updateDialogFilters' => 'dialog_filters',
        'updateFolderPeers' => 'folder_peers',
        'updateSavedDialogPinned' => 'saved_dialog_pinned',
        'updatePinnedSavedDialogs' => 'pinned_saved_dialogs',

        'updateBotStopped' => 'bot_stopped',
        'updateBotCommands' => 'bot_commands',
        'updateBotMenuButton' => 'bot_menu',
        'updateBotChatInviteRequester' => 'chat_join_request',
        'updateBotChatBoost' => 'chat_boost',
        'updateBotWebhookJSON' => 'bot_webhook',
        'updateBotWebhookJSONQuery' => 'bot_webhook_query',
        'updateWebViewResultSent' => 'webview_result_sent',
        'updateAttachMenuBots' => 'attach_menu_bots',
        'updateNewBotConnection' => 'bot_connection',
        'updateBotGuestChatQuery' => 'bot_guest_chat',
        'updateManagedBot' => 'managed_bot',
        'updateJoinChatWebViewDecision' => 'join_chat_webview',

        'updateBotBusinessConnect' => 'bot_business_connect',
        'updateBotNewBusinessMessage' => 'bot_business_message',
        'updateBotEditBusinessMessage' => 'bot_business_edit',
        'updateBotDeleteBusinessMessage' => 'bot_business_delete',
        'updateBusinessBotCallbackQuery' => 'business_callback',

        'updateNewStickerSet' => 'new_sticker_set',
        'updateStickerSetsOrder' => 'sticker_sets_order',
        'updateMoveStickerSetToTop' => 'sticker_sets_order',
        'updateStickerSets' => 'sticker_sets',
        'updateSavedGifs' => 'saved_gifs',
        'updateFavedStickers' => 'faved_stickers',
        'updateRecentStickers' => 'recent_stickers',
        'updateReadFeaturedStickers' => 'recent_stickers',
        'updateReadFeaturedEmojiStickers' => 'recent_stickers',
        'updateRecentEmojiStatuses' => 'recent_stickers',
        'updateRecentReactions' => 'recent_stickers',
        'updateSavedReactionTags' => 'recent_stickers',
        'updateSavedRingtones' => 'recent_stickers',
        'updateEmojiGameInfo' => 'emoji_game',

        'updatePinnedForumTopic' => 'pinned_forum_topic',
        'updatePinnedForumTopics' => 'pinned_forum_topics',

        'updatePeerSettings' => 'peer_settings',
        'updatePeerLocated' => 'peer_located',
        'updatePeerBlocked' => 'peer_blocked',
        'updatePeerHistoryTTL' => 'peer_history_ttl',
        'updatePeerWallpaper' => 'peer_wallpaper',
        'updateContactsReset' => 'contacts_reset',
        'updatePendingJoinRequests' => 'pending_join_requests',

        'updateNewAuthorization' => 'new_authorization',
        'updateLoginToken' => 'login_token',
        'updateSentPhoneCode' => 'login_token',

        'updateStarsBalance' => 'stars_balance',
        'updateStarsRevenueStatus' => 'stars_revenue',
        'updatePaidReactionPrivacy' => 'paid_reaction_privacy',
        'updateStarGiftAuctionState' => 'star_gift_auction',
        'updateStarGiftAuctionUserState' => 'star_gift_auction_user',
        'updateStarGiftCraftFail' => 'star_gift_craft_fail',

        'updateQuickReplies' => 'quick_replies',
        'updateNewQuickReply' => 'new_quick_reply',
        'updateDeleteQuickReply' => 'delete_quick_reply',
        'updateQuickReplyMessage' => 'quick_reply_message',
        'updateDeleteQuickReplyMessages' => 'delete_quick_reply_messages',

        'updateConfig' => 'config',
        'updateDcOptions' => 'dc_options',
        'updatePtsChanged' => 'pts_changed',
        'updateLangPack' => 'lang_pack',
        'updateLangPackTooLong' => 'lang_pack',
        'updateAutoSaveSettings' => 'auto_save_settings',
        'updateSmsJob' => 'sms_job',
        'updateAiComposeTones' => 'ai_compose_tones',
        'updateWebBrowserException' => 'web_browser_exception',
        'updateWebBrowserSettings' => 'web_browser_settings',

        'updateTheme' => 'theme',
    ];

    const MESSAGE_CONSTRUCTORS = [
        'updateNewMessage',
        'updateNewChannelMessage',
        'updateEditMessage',
        'updateEditChannelMessage',
        'updateNewScheduledMessage',
        'updateBotNewBusinessMessage',
        'updateBotEditBusinessMessage',
    ];

    const CALLBACK_CONSTRUCTORS = [
        'updateBotCallbackQuery',
        'updateInlineBotCallbackQuery',
        'updateBusinessBotCallbackQuery',
    ];

    const MEDIA_TYPES = [
        'messageMediaPhoto' => 'photo',
        'messageMediaDocument' => 'document',  // further refined by document attributes
        'messageMediaGeo' => 'location',
        'messageMediaGeoLive' => 'location',
        'messageMediaContact' => 'contact',
        'messageMediaVenue' => 'venue',
        'messageMediaGame' => 'game',
        'messageMediaInvoice' => 'invoice',
        'messageMediaWebPage' => 'web_page',
        'messageMediaPoll' => 'poll',
        'messageMediaDice' => 'dice',
        'messageMediaStory' => 'story',
        'messageMediaGiveaway' => 'giveaway',
        'messageMediaGiveawayResults' => 'giveaway',
        'messageMediaPaidMedia' => 'paid_media',
    ];

    const DOCUMENT_ATTRIBUTES = [
        'documentAttributeSticker' => 'sticker',
        'documentAttributeCustomEmoji' => 'sticker',
        'documentAttributeVideo' => 'video',       // further: round_message → video_note
        'documentAttributeAudio' => 'audio',       // further: voice → voice
        'documentAttributeAnimated' => 'animation',
        'documentAttributeImageSize' => 'document',
        'documentAttributeFilename' => 'document',
        'documentAttributeHasStickers' => 'document',
    ];

    public static function verbs(): array
    {
        return array_map(
            static fn(self $case) => $case->name,
            self::cases(),
        );
    }

    public static function eventKey(string $constructor): string
    {
        return self::EVENTS[$constructor] ?? 'update';
    }

    /**
     * Whether the constructor carries a message body (→ fans out content passes).
     */
    public static function isMessage(string $constructor): bool
    {
        return in_array($constructor, self::MESSAGE_CONSTRUCTORS, true);
    }

    /**
     * Whether the constructor carries callback payload data.
     */
    public static function isCallback(string $constructor): bool
    {
        return in_array($constructor, self::CALLBACK_CONSTRUCTORS, true);
    }

    /**
     * Resolve the media type from a message's media field.
     *
     * @param array $message The raw message array
     * @return string|null The media type verb (photo, video, sticker, etc.) or null if text-only
     */
    public static function mediaTypeFromMessage(array $message): ?string
    {
        $media = $message['media'] ?? null;
        if (!is_array($media)) {
            return null;
        }

        $mediaConstructor = $media['_'] ?? '';

        // Empty media
        if ($mediaConstructor === 'messageMediaEmpty' || $mediaConstructor === '') {
            return null;
        }

        // Direct media type mapping
        $baseType = self::MEDIA_TYPES[$mediaConstructor] ?? null;

        // For documents, refine by attributes
        if ($baseType === 'document' && isset($media['document']['attributes'])) {
            return self::refineDocumentType($media['document']['attributes']);
        }

        return $baseType;
    }

    /**
     * Refine document type based on its attributes.
     */
    private static function refineDocumentType(array $attributes): string
    {
        $hasSticker = false;
        $hasVideo = false;
        $hasAudio = false;
        $hasAnimated = false;
        $isRoundVideo = false;
        $isVoice = false;

        foreach ($attributes as $attr) {
            $attrType = $attr['_'] ?? '';
            match ($attrType) {
                'documentAttributeSticker',
                'documentAttributeCustomEmoji' => $hasSticker = true,
                'documentAttributeAnimated' => $hasAnimated = true,
                'documentAttributeVideo' => (function () use ($attr, &$hasVideo, &$isRoundVideo) {
                    $hasVideo = true;
                    $isRoundVideo = !empty($attr['round_message']);
                })(),
                'documentAttributeAudio' => (function () use ($attr, &$hasAudio, &$isVoice) {
                    $hasAudio = true;
                    $isVoice = !empty($attr['voice']);
                })(),
                default => null,
            };
        }

        // Priority order
        if ($hasSticker) return 'sticker';
        if ($hasAnimated) return 'animation';
        if ($isRoundVideo) return 'video_note';
        if ($hasVideo) return 'video';
        if ($isVoice) return 'voice';
        if ($hasAudio) return 'audio';

        return 'document';
    }
}
