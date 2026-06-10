<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

/**
 * Maps raw MTProto constructor names to simplified verbs
 * for the Listening/Matching system.
 *
 * This is the MTProto equivalent of LaraGram\Listening\Type,
 * but works with MTProto constructor names instead of Bot API update types.
 *
 * Covers all ~117 update constructors from the Telegram TL schema (Layer 199+).
 */
enum ClientType: string
{
    // ── Messages ───────────────────────────────────────────────────────
    case NEW_MESSAGE          = 'new_message';
    case EDIT_MESSAGE         = 'edit_message';
    case DELETED_MESSAGES     = 'deleted_messages';
    case MESSAGE_ID           = 'message_id';
    case PINNED_MESSAGES      = 'pinned_messages';
    case READ_CONTENTS        = 'read_contents';
    case MESSAGE_VIEWS        = 'message_views';
    case MESSAGE_FORWARDS     = 'message_forwards';
    case MESSAGE_EXTENDED_MEDIA = 'message_extended_media';
    case TRANSCRIBED_AUDIO    = 'transcribed_audio';
    case GEO_LIVE_VIEWED      = 'geo_live_viewed';
    case SCHEDULED_MESSAGE    = 'scheduled_message';
    case DELETE_SCHEDULED     = 'delete_scheduled';

    // ── Media-filtered messages (sub-types of NEW_MESSAGE) ─────────────
    case PHOTO                = 'photo';
    case VIDEO                = 'video';
    case ANIMATION            = 'animation';
    case STICKER              = 'sticker';
    case DOCUMENT             = 'document';
    case AUDIO                = 'audio';
    case VOICE                = 'voice';
    case VIDEO_NOTE           = 'video_note';
    case CONTACT_MEDIA        = 'contact_media';
    case LOCATION             = 'location';
    case VENUE                = 'venue';
    case GAME                 = 'game';
    case DICE                 = 'dice';

    // ── Read history ───────────────────────────────────────────────────
    case READ_HISTORY         = 'read_history';
    case READ_DISCUSSION      = 'read_discussion';

    // ── Callback / Inline ──────────────────────────────────────────────
    case CALLBACK_QUERY       = 'callback_query';
    case INLINE_QUERY         = 'inline_query';
    case CHOSEN_INLINE_RESULT = 'chosen_inline_result';

    // ── Typing ─────────────────────────────────────────────────────────
    case TYPING               = 'typing';

    // ── Reactions ──────────────────────────────────────────────────────
    case REACTIONS            = 'reactions';

    // ── Users ──────────────────────────────────────────────────────────
    case USER_STATUS          = 'user_status';
    case USER_NAME            = 'user_name';
    case USER_PHONE           = 'user_phone';
    case USER_EMOJI_STATUS    = 'user_emoji_status';
    case USER_UPDATE          = 'user_update';

    // ── Chats / Channels ───────────────────────────────────────────────
    case CHAT_PARTICIPANT     = 'chat_participant';
    case CHAT_PARTICIPANTS    = 'chat_participants';
    case CHAT_PARTICIPANT_ADD = 'chat_participant_add';
    case CHAT_PARTICIPANT_DELETE = 'chat_participant_delete';
    case CHAT_PARTICIPANT_ADMIN = 'chat_participant_admin';
    case CHAT_DEFAULT_BANNED  = 'chat_default_banned';
    case CHAT_UPDATE          = 'chat_update';
    case CHANNEL_UPDATE       = 'channel_update';
    case CHANNEL_TOO_LONG     = 'channel_too_long';
    case CHANNEL_AVAILABLE_MESSAGES = 'channel_available_messages';
    case CHANNEL_VIEW_FORUM_AS_MESSAGES = 'channel_view_forum_as_messages';

    // ── Polls ──────────────────────────────────────────────────────────
    case POLL                 = 'poll';
    case POLL_VOTE            = 'poll_vote';

    // ── Web pages ──────────────────────────────────────────────────────
    case WEB_PAGE             = 'web_page';

    // ── Payments ───────────────────────────────────────────────────────
    case PRE_CHECKOUT         = 'pre_checkout';
    case SHIPPING             = 'shipping';

    // ── Phone calls ────────────────────────────────────────────────────
    case PHONE_CALL           = 'phone_call';
    case PHONE_CALL_SIGNALING = 'phone_call_signaling';

    // ── Group calls ────────────────────────────────────────────────────
    case GROUP_CALL           = 'group_call';
    case GROUP_CALL_PARTICIPANTS = 'group_call_participants';
    case GROUP_CALL_CONNECTION = 'group_call_connection';

    // ── Stories ─────────────────────────────────────────────────────────
    case STORY                = 'story';
    case READ_STORIES         = 'read_stories';
    case STORY_ID             = 'story_id';
    case STORIES_STEALTH_MODE = 'stories_stealth_mode';
    case STORY_REACTION       = 'story_reaction';

    // ── Encrypted (Secret chats) ───────────────────────────────────────
    case ENCRYPTED_MESSAGE    = 'encrypted_message';
    case ENCRYPTED_CHAT_TYPING = 'encrypted_chat_typing';
    case ENCRYPTION           = 'encryption';
    case ENCRYPTED_READ       = 'encrypted_read';

    // ── Drafts ─────────────────────────────────────────────────────────
    case DRAFT                = 'draft';

    // ── Notifications & Settings ───────────────────────────────────────
    case NOTIFY_SETTINGS      = 'notify_settings';
    case SERVICE_NOTIFICATION = 'service_notification';
    case PRIVACY              = 'privacy';

    // ── Dialogs & Folders ──────────────────────────────────────────────
    case DIALOG_PINNED        = 'dialog_pinned';
    case PINNED_DIALOGS       = 'pinned_dialogs';
    case DIALOG_UNREAD_MARK   = 'dialog_unread_mark';
    case DIALOG_FILTER        = 'dialog_filter';
    case DIALOG_FILTER_ORDER  = 'dialog_filter_order';
    case DIALOG_FILTERS       = 'dialog_filters';
    case FOLDER_PEERS         = 'folder_peers';
    case SAVED_DIALOG_PINNED  = 'saved_dialog_pinned';
    case PINNED_SAVED_DIALOGS = 'pinned_saved_dialogs';

    // ── Bots ───────────────────────────────────────────────────────────
    case BOT_STOPPED          = 'bot_stopped';
    case BOT_COMMANDS         = 'bot_commands';
    case BOT_MENU             = 'bot_menu';
    case CHAT_JOIN_REQUEST    = 'chat_join_request';
    case CHAT_BOOST           = 'chat_boost';
    case BOT_REACTION         = 'bot_reaction';
    case BOT_REACTIONS        = 'bot_reactions';
    case BOT_PURCHASED_PAID   = 'bot_purchased_paid';
    case BOT_WEBHOOK          = 'bot_webhook';
    case BOT_WEBHOOK_QUERY    = 'bot_webhook_query';
    case WEBVIEW_RESULT_SENT  = 'webview_result_sent';
    case ATTACH_MENU_BOTS     = 'attach_menu_bots';

    // ── Bot Business ───────────────────────────────────────────────────
    case BOT_BUSINESS_CONNECT = 'bot_business_connect';
    case BOT_BUSINESS_MESSAGE = 'bot_business_message';
    case BOT_BUSINESS_EDIT    = 'bot_business_edit';
    case BOT_BUSINESS_DELETE  = 'bot_business_delete';
    case BUSINESS_CALLBACK    = 'business_callback';

    // ── Stickers & Emoji ───────────────────────────────────────────────
    case NEW_STICKER_SET      = 'new_sticker_set';
    case STICKER_SETS_ORDER   = 'sticker_sets_order';
    case STICKER_SETS         = 'sticker_sets';
    case SAVED_GIFS           = 'saved_gifs';
    case FAVED_STICKERS       = 'faved_stickers';
    case RECENT_STICKERS      = 'recent_stickers';

    // ── Forum Topics ───────────────────────────────────────────────────
    case PINNED_FORUM_TOPIC   = 'pinned_forum_topic';
    case PINNED_FORUM_TOPICS  = 'pinned_forum_topics';

    // ── Peers ──────────────────────────────────────────────────────────
    case PEER_SETTINGS        = 'peer_settings';
    case PEER_LOCATED         = 'peer_located';
    case PEER_BLOCKED         = 'peer_blocked';
    case PEER_HISTORY_TTL     = 'peer_history_ttl';
    case PEER_WALLPAPER       = 'peer_wallpaper';
    case CONTACTS_RESET       = 'contacts_reset';
    case PENDING_JOIN_REQUESTS = 'pending_join_requests';

    // ── Auth & Security ────────────────────────────────────────────────
    case NEW_AUTHORIZATION    = 'new_authorization';
    case LOGIN_TOKEN          = 'login_token';

    // ── Stars & Payments ───────────────────────────────────────────────
    case STARS_BALANCE        = 'stars_balance';
    case STARS_REVENUE        = 'stars_revenue';
    case PAID_REACTION_PRIVACY = 'paid_reaction_privacy';

    // ── Quick Replies ──────────────────────────────────────────────────
    case QUICK_REPLIES        = 'quick_replies';
    case NEW_QUICK_REPLY      = 'new_quick_reply';
    case DELETE_QUICK_REPLY   = 'delete_quick_reply';
    case QUICK_REPLY_MESSAGE  = 'quick_reply_message';
    case DELETE_QUICK_REPLY_MESSAGES = 'delete_quick_reply_messages';

    // ── Config & System ────────────────────────────────────────────────
    case CONFIG               = 'config';
    case DC_OPTIONS           = 'dc_options';
    case PTS_CHANGED          = 'pts_changed';
    case LANG_PACK            = 'lang_pack';
    case LANG_PACK_TOO_LONG   = 'lang_pack_too_long';
    case AUTO_SAVE_SETTINGS   = 'auto_save_settings';

    // ── Themes ─────────────────────────────────────────────────────────
    case THEME                = 'theme';

    // ── Catch-all ──────────────────────────────────────────────────────
    case UPDATE               = 'update';

    /**
     * All MTProto constructor → verb mappings.
     *
     * Every update constructor from the TL schema (Layer 199+) is mapped here.
     */
    const CONSTRUCTORS = [
        // ── Messages ───────────────────────────────────────────────────
        'updateNewMessage'              => 'new_message',
        'updateNewChannelMessage'       => 'new_message',
        'updateEditMessage'             => 'edit_message',
        'updateEditChannelMessage'      => 'edit_message',
        'updateDeleteMessages'          => 'deleted_messages',
        'updateDeleteChannelMessages'   => 'deleted_messages',
        'updateMessageID'               => 'message_id',
        'updatePinnedMessages'          => 'pinned_messages',
        'updatePinnedChannelMessages'   => 'pinned_messages',
        'updateReadMessagesContents'    => 'read_contents',
        'updateChannelReadMessagesContents' => 'read_contents',
        'updateChannelMessageViews'     => 'message_views',
        'updateChannelMessageForwards'  => 'message_forwards',
        'updateMessageExtendedMedia'    => 'message_extended_media',
        'updateTranscribedAudio'        => 'transcribed_audio',
        'updateGeoLiveViewed'           => 'geo_live_viewed',
        'updateNewScheduledMessage'     => 'scheduled_message',
        'updateDeleteScheduledMessages' => 'delete_scheduled',

        // ── Polls ──────────────────────────────────────────────────────
        'updateMessagePoll'             => 'poll',
        'updateMessagePollVote'         => 'poll_vote',

        // ── Web pages ──────────────────────────────────────────────────
        'updateWebPage'                 => 'web_page',
        'updateChannelWebPage'          => 'web_page',

        // ── Read history ───────────────────────────────────────────────
        'updateReadHistoryInbox'        => 'read_history',
        'updateReadHistoryOutbox'       => 'read_history',
        'updateReadChannelInbox'        => 'read_history',
        'updateReadChannelOutbox'       => 'read_history',
        'updateReadChannelDiscussionInbox'  => 'read_discussion',
        'updateReadChannelDiscussionOutbox' => 'read_discussion',

        // ── Callback / Inline ──────────────────────────────────────────
        'updateBotCallbackQuery'        => 'callback_query',
        'updateInlineBotCallbackQuery'  => 'callback_query',
        'updateBotInlineQuery'          => 'inline_query',
        'updateBotInlineSend'           => 'chosen_inline_result',

        // ── Typing ─────────────────────────────────────────────────────
        'updateUserTyping'              => 'typing',
        'updateChatUserTyping'          => 'typing',
        'updateChannelUserTyping'       => 'typing',

        // ── Reactions ──────────────────────────────────────────────────
        'updateMessageReactions'        => 'reactions',

        // ── Users ──────────────────────────────────────────────────────
        'updateUserStatus'              => 'user_status',
        'updateUserName'                => 'user_name',
        'updateUserPhone'               => 'user_phone',
        'updateUserEmojiStatus'         => 'user_emoji_status',
        'updateUser'                    => 'user_update',

        // ── Chats / Channels ───────────────────────────────────────────
        'updateChatParticipant'         => 'chat_participant',
        'updateChannelParticipant'      => 'chat_participant',
        'updateChatParticipants'        => 'chat_participants',
        'updateChatParticipantAdd'      => 'chat_participant_add',
        'updateChatParticipantDelete'   => 'chat_participant_delete',
        'updateChatParticipantAdmin'    => 'chat_participant_admin',
        'updateChatDefaultBannedRights' => 'chat_default_banned',
        'updateChat'                    => 'chat_update',
        'updateChannel'                 => 'channel_update',
        'updateChannelTooLong'          => 'channel_too_long',
        'updateChannelAvailableMessages' => 'channel_available_messages',
        'updateChannelViewForumAsMessages' => 'channel_view_forum_as_messages',

        // ── Payments ───────────────────────────────────────────────────
        'updateBotPrecheckoutQuery'     => 'pre_checkout',
        'updateBotShippingQuery'        => 'shipping',

        // ── Phone calls ────────────────────────────────────────────────
        'updatePhoneCall'               => 'phone_call',
        'updatePhoneCallSignalingData'  => 'phone_call_signaling',

        // ── Group calls ────────────────────────────────────────────────
        'updateGroupCall'               => 'group_call',
        'updateGroupCallParticipants'   => 'group_call_participants',
        'updateGroupCallConnection'     => 'group_call_connection',

        // ── Stories ────────────────────────────────────────────────────
        'updateStory'                   => 'story',
        'updateReadStories'             => 'read_stories',
        'updateStoryID'                 => 'story_id',
        'updateStoriesStealthMode'      => 'stories_stealth_mode',
        'updateSentStoryReaction'       => 'story_reaction',
        'updateNewStoryReaction'        => 'story_reaction',

        // ── Encrypted (Secret chats) ───────────────────────────────────
        'updateNewEncryptedMessage'     => 'encrypted_message',
        'updateEncryptedChatTyping'     => 'encrypted_chat_typing',
        'updateEncryption'              => 'encryption',
        'updateEncryptedMessagesRead'   => 'encrypted_read',

        // ── Drafts ─────────────────────────────────────────────────────
        'updateDraftMessage'            => 'draft',

        // ── Notifications & Settings ───────────────────────────────────
        'updateNotifySettings'          => 'notify_settings',
        'updateServiceNotification'     => 'service_notification',
        'updatePrivacy'                 => 'privacy',

        // ── Dialogs & Folders ──────────────────────────────────────────
        'updateDialogPinned'            => 'dialog_pinned',
        'updatePinnedDialogs'           => 'pinned_dialogs',
        'updateDialogUnreadMark'        => 'dialog_unread_mark',
        'updateDialogFilter'            => 'dialog_filter',
        'updateDialogFilterOrder'       => 'dialog_filter_order',
        'updateDialogFilters'           => 'dialog_filters',
        'updateFolderPeers'             => 'folder_peers',
        'updateSavedDialogPinned'       => 'saved_dialog_pinned',
        'updatePinnedSavedDialogs'      => 'pinned_saved_dialogs',

        // ── Bots ───────────────────────────────────────────────────────
        'updateBotStopped'              => 'bot_stopped',
        'updateBotCommands'             => 'bot_commands',
        'updateBotMenuButton'           => 'bot_menu',
        'updateBotChatInviteRequester'  => 'chat_join_request',
        'updateBotChatBoost'            => 'chat_boost',
        'updateBotMessageReaction'      => 'bot_reaction',
        'updateBotMessageReactions'     => 'bot_reactions',
        'updateBotPurchasedPaidMedia'   => 'bot_purchased_paid',
        'updateBotWebhookJSON'          => 'bot_webhook',
        'updateBotWebhookJSONQuery'     => 'bot_webhook_query',
        'updateWebViewResultSent'       => 'webview_result_sent',
        'updateAttachMenuBots'          => 'attach_menu_bots',

        // ── Bot Business ───────────────────────────────────────────────
        'updateBotBusinessConnect'      => 'bot_business_connect',
        'updateBotNewBusinessMessage'   => 'bot_business_message',
        'updateBotEditBusinessMessage'  => 'bot_business_edit',
        'updateBotDeleteBusinessMessage' => 'bot_business_delete',
        'updateBusinessBotCallbackQuery' => 'business_callback',

        // ── Stickers & Emoji ───────────────────────────────────────────
        'updateNewStickerSet'           => 'new_sticker_set',
        'updateStickerSetsOrder'        => 'sticker_sets_order',
        'updateStickerSets'             => 'sticker_sets',
        'updateSavedGifs'               => 'saved_gifs',
        'updateFavedStickers'           => 'faved_stickers',
        'updateRecentStickers'          => 'recent_stickers',
        'updateReadFeaturedStickers'    => 'recent_stickers',
        'updateReadFeaturedEmojiStickers' => 'recent_stickers',
        'updateRecentEmojiStatuses'     => 'recent_stickers',
        'updateRecentReactions'         => 'recent_stickers',
        'updateMoveStickerSetToTop'     => 'sticker_sets_order',
        'updateSavedReactionTags'       => 'recent_stickers',
        'updateSavedRingtones'          => 'recent_stickers',

        // ── Forum Topics ───────────────────────────────────────────────
        'updatePinnedForumTopic'        => 'pinned_forum_topic',
        'updatePinnedForumTopics'       => 'pinned_forum_topics',

        // ── Peers ──────────────────────────────────────────────────────
        'updatePeerSettings'            => 'peer_settings',
        'updatePeerLocated'             => 'peer_located',
        'updatePeerBlocked'             => 'peer_blocked',
        'updatePeerHistoryTTL'          => 'peer_history_ttl',
        'updatePeerWallpaper'           => 'peer_wallpaper',
        'updateContactsReset'           => 'contacts_reset',
        'updatePendingJoinRequests'     => 'pending_join_requests',

        // ── Auth & Security ────────────────────────────────────────────
        'updateNewAuthorization'        => 'new_authorization',
        'updateLoginToken'              => 'login_token',
        'updateSentPhoneCode'           => 'login_token',

        // ── Stars & Payments ───────────────────────────────────────────
        'updateStarsBalance'            => 'stars_balance',
        'updateStarsRevenueStatus'      => 'stars_revenue',
        'updatePaidReactionPrivacy'     => 'paid_reaction_privacy',

        // ── Quick Replies ──────────────────────────────────────────────
        'updateQuickReplies'            => 'quick_replies',
        'updateNewQuickReply'           => 'new_quick_reply',
        'updateDeleteQuickReply'        => 'delete_quick_reply',
        'updateQuickReplyMessage'       => 'quick_reply_message',
        'updateDeleteQuickReplyMessages' => 'delete_quick_reply_messages',

        // ── Config & System ────────────────────────────────────────────
        'updateConfig'                  => 'config',
        'updateDcOptions'               => 'dc_options',
        'updatePtsChanged'              => 'pts_changed',
        'updateLangPack'                => 'lang_pack',
        'updateLangPackTooLong'         => 'lang_pack_too_long',
        'updateAutoSaveSettings'        => 'auto_save_settings',

        // ── Themes ─────────────────────────────────────────────────────
        'updateTheme'                   => 'theme',
    ];

    /**
     * MTProto media constructor → media type mappings.
     *
     * Used by media-filtered handlers (onPhoto, onVideo, etc.) to
     * discriminate within NEW_MESSAGE updates.
     */
    const MEDIA_TYPES = [
        'messageMediaPhoto'    => 'photo',
        'messageMediaDocument' => 'document',  // further refined by document attributes
        'messageMediaGeo'      => 'location',
        'messageMediaGeoLive'  => 'location',
        'messageMediaContact'  => 'contact_media',
        'messageMediaVenue'    => 'venue',
        'messageMediaGame'     => 'game',
        'messageMediaInvoice'  => 'invoice',
        'messageMediaWebPage'  => 'web_page',
        'messageMediaPoll'     => 'poll',
        'messageMediaDice'     => 'dice',
        'messageMediaStory'    => 'story',
        'messageMediaGiveaway' => 'giveaway',
        'messageMediaGiveawayResults' => 'giveaway',
        'messageMediaPaidMedia' => 'paid_media',
    ];

    /**
     * Document attribute → refined media type.
     *
     * When media is messageMediaDocument, check the document's attributes
     * to determine if it's a sticker, video, audio, voice, etc.
     */
    const DOCUMENT_ATTRIBUTES = [
        'documentAttributeSticker'      => 'sticker',
        'documentAttributeCustomEmoji'  => 'sticker',
        'documentAttributeVideo'        => 'video',       // further: round_message → video_note
        'documentAttributeAudio'        => 'audio',       // further: voice → voice
        'documentAttributeAnimated'     => 'animation',
        'documentAttributeImageSize'    => 'document',
        'documentAttributeFilename'     => 'document',
        'documentAttributeHasStickers'  => 'document',
    ];

    /**
     * All supported verbs (auto-generated from enum cases).
     */
    public static function verbs(): array
    {
        return array_map(
            static fn(self $case) => $case->name,
            self::cases(),
        );
    }

    /**
     * Map a raw constructor name to its verb.
     */
    public static function verbFromConstructor(string $constructor): string
    {
        $mapped = self::CONSTRUCTORS[$constructor] ?? null;

        if ($mapped !== null) {
            return strtoupper($mapped);
        }

        // Unknown constructor — fallback to generic UPDATE
        return 'UPDATE';
    }

    /**
     * Find the ClientType case from a verb value.
     */
    public static function fromVerb(string $verb): ?self
    {
        $lower = strtolower($verb);
        return self::tryFrom($lower);
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
     *
     * Order matters — a sticker is also a document with an image size,
     * so we check sticker first.
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
                'documentAttributeAnimated'    => $hasAnimated = true,
                'documentAttributeVideo'       => (function () use ($attr, &$hasVideo, &$isRoundVideo) {
                    $hasVideo = true;
                    $isRoundVideo = !empty($attr['round_message']);
                })(),
                'documentAttributeAudio'       => (function () use ($attr, &$hasAudio, &$isVoice) {
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
