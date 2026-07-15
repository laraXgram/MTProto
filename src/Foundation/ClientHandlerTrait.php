<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;

trait ClientHandlerTrait
{
    public function onMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'new_message', $action);
    }

    public function onCommand(string|array $pattern, Closure|array|string $action)
    {
        $listen = null;

        foreach ((array) $pattern as $cmd) {
            $cmd = ltrim((string) $cmd, '/');
            $listen = $this->addListen('COMMAND', $cmd . ' {args?}', $action)
                ->where('args', '.*');
        }

        return $listen;
    }

    public function onPreCheckoutQuery(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pre_checkout', $action);
    }

    public function onShippingQuery(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'shipping', $action);
    }

    public function onHashtag(Closure|array|string $action)
    {
        return $this->addListen('ENTITIES', 'messageEntityHashtag', $action);
    }

    public function onCashtag(Closure|array|string $action)
    {
        return $this->addListen('ENTITIES', 'messageEntityCashtag', $action);
    }

    public function onMention(Closure|array|string $action)
    {
        return $this->addListen('ENTITIES', 'messageEntityMention|messageEntityMentionName', $action);
    }

    public function onUrl(Closure|array|string $action)
    {
        return $this->addListen('ENTITIES', 'messageEntityUrl|messageEntityTextUrl', $action);
    }

    public function onEmail(Closure|array|string $action)
    {
        return $this->addListen('ENTITIES', 'messageEntityEmail', $action);
    }

    public function onBotCommandEntity(Closure|array|string $action)
    {
        return $this->addListen('ENTITIES', 'messageEntityBotCommand', $action);
    }

    public function onSentMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'sent_message', $action);
    }

    public function onDeletedMessages(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'deleted_messages', $action);
    }

    public function onPinnedMessages(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pinned_messages', $action);
    }

    public function onScheduledMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'scheduled_message', $action);
    }

    public function onMessageId(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'message_id', $action);
    }

    public function onMessageViews(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'message_views', $action);
    }

    public function onMessageForwards(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'message_forwards', $action);
    }

    public function onMessageExtendedMedia(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'message_extended_media', $action);
    }

    public function onTranscribedAudio(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'transcribed_audio', $action);
    }

    public function onGeoLiveViewed(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'geo_live_viewed', $action);
    }

    public function onDeleteScheduled(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'delete_scheduled', $action);
    }

    public function onContact(Closure|array|string $action)
    {
        return $this->addListen('MESSAGE', 'contact', $action);
    }

    public function onGiveaway(Closure|array|string $action)
    {
        return $this->addListen('MESSAGE', 'giveaway', $action);
    }

    public function onPaidMedia(Closure|array|string $action)
    {
        return $this->addListen('MESSAGE', 'paid_media', $action);
    }

    public function onTyping(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'typing', $action);
    }

    public function onReadHistory(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'read_history', $action);
    }

    public function onReadDiscussion(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'read_discussion', $action);
    }

    public function onReadContents(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'read_contents', $action);
    }

    public function onMonoForumRead(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'mono_forum_read', $action);
    }

    public function onMonoForumNoPaid(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'mono_forum_no_paid', $action);
    }

    public function onReactions(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'reactions', $action);
    }

    public function onBotReaction(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_reaction', $action);
    }

    public function onBotReactions(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_reactions', $action);
    }

    public function onPollVote(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'poll_vote', $action);
    }

    public function onPollResults(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'poll', $action);
    }

    public function onUserStatus(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'user_status', $action);
    }

    public function onUserName(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'user_name', $action);
    }

    public function onUserPhone(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'user_phone', $action);
    }

    public function onUserEmojiStatus(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'user_emoji_status', $action);
    }

    public function onUser(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'user_update', $action);
    }

    public function onChatParticipant(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_participant', $action);
    }

    public function onChatParticipants(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_participants', $action);
    }

    public function onChatParticipantAdd(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_participant_add', $action);
    }

    public function onChatParticipantDelete(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_participant_delete', $action);
    }

    public function onChatParticipantAdmin(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_participant_admin', $action);
    }

    public function onChatParticipantRank(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_participant_rank', $action);
    }

    public function onChatDefaultBanned(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_default_banned', $action);
    }

    public function onChatBoost(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_boost', $action);
    }

    public function onChat(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'chat_update', $action);
    }

    public function onChannel(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'channel_update', $action);
    }

    public function onChannelTooLong(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'channel_too_long', $action);
    }

    public function onChannelAvailableMessages(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'channel_available_messages', $action);
    }

    public function onChannelViewForumAsMessages(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'channel_view_forum_as_messages', $action);
    }

    public function onBotPurchasedPaid(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_purchased_paid', $action);
    }

    public function onStarsBalance(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'stars_balance', $action);
    }

    public function onStarsRevenue(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'stars_revenue', $action);
    }

    public function onPaidReactionPrivacy(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'paid_reaction_privacy', $action);
    }

    public function onStarGiftAuction(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'star_gift_auction', $action);
    }

    public function onStarGiftAuctionUser(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'star_gift_auction_user', $action);
    }

    public function onStarGiftCraftFail(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'star_gift_craft_fail', $action);
    }

    public function onPhoneCall(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'phone_call', $action);
    }

    public function onPhoneCallSignaling(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'phone_call_signaling', $action);
    }

    public function onGroupCall(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'group_call', $action);
    }

    public function onGroupCallParticipants(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'group_call_participants', $action);
    }

    public function onGroupCallConnection(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'group_call_connection', $action);
    }

    public function onGroupCallMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'group_call_message', $action);
    }

    public function onGroupCallDelete(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'group_call_delete', $action);
    }

    public function onGroupCallChain(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'group_call_chain', $action);
    }

    public function onGroupCallEncrypted(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'group_call_encrypted', $action);
    }

    public function onStory(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'story', $action);
    }

    public function onReadStories(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'read_stories', $action);
    }

    public function onStoryId(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'story_id', $action);
    }

    public function onStoriesStealthMode(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'stories_stealth_mode', $action);
    }

    public function onStoryReaction(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'story_reaction', $action);
    }

    public function onEncryptedMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'encrypted_message', $action);
    }

    public function onEncryptedChatTyping(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'encrypted_chat_typing', $action);
    }

    public function onEncryption(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'encryption', $action);
    }

    public function onEncryptedRead(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'encrypted_read', $action);
    }

    public function onDraft(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'draft', $action);
    }

    public function onDialogPinned(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'dialog_pinned', $action);
    }

    public function onPinnedDialogs(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pinned_dialogs', $action);
    }

    public function onDialogUnreadMark(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'dialog_unread_mark', $action);
    }

    public function onDialogFilter(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'dialog_filter', $action);
    }

    public function onDialogFilterOrder(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'dialog_filter_order', $action);
    }

    public function onDialogFilters(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'dialog_filters', $action);
    }

    public function onFolderPeers(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'folder_peers', $action);
    }

    public function onSavedDialogPinned(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'saved_dialog_pinned', $action);
    }

    public function onPinnedSavedDialogs(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pinned_saved_dialogs', $action);
    }

    public function onNotifySettings(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'notify_settings', $action);
    }

    public function onServiceNotification(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'service_notification', $action);
    }

    public function onPrivacy(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'privacy', $action);
    }

    public function onPeerSettings(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'peer_settings', $action);
    }

    public function onPeerLocated(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'peer_located', $action);
    }

    public function onPeerBlocked(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'peer_blocked', $action);
    }

    public function onPeerHistoryTtl(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'peer_history_ttl', $action);
    }

    public function onPeerWallpaper(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'peer_wallpaper', $action);
    }

    public function onContactsReset(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'contacts_reset', $action);
    }

    public function onPendingJoinRequests(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pending_join_requests', $action);
    }

    public function onBotStopped(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_stopped', $action);
    }

    public function onBotCommands(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_commands', $action);
    }

    public function onBotMenu(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_menu', $action);
    }

    public function onWebviewResultSent(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'webview_result_sent', $action);
    }

    public function onAttachMenuBots(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'attach_menu_bots', $action);
    }

    public function onBotWebhook(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_webhook', $action);
    }

    public function onBotWebhookQuery(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_webhook_query', $action);
    }

    public function onBotConnection(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_connection', $action);
    }

    public function onBotGuestChat(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_guest_chat', $action);
    }

    public function onManagedBot(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'managed_bot', $action);
    }

    public function onJoinChatWebview(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'join_chat_webview', $action);
    }

    public function onBusinessMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_business_message', $action);
    }

    public function onBusinessConnect(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_business_connect', $action);
    }

    public function onBusinessEdit(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_business_edit', $action);
    }

    public function onBusinessDelete(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_business_delete', $action);
    }

    public function onBusinessCallback(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'business_callback', $action);
    }

    public function onForumTopic(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pinned_forum_topic', $action);
    }

    public function onForumTopics(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pinned_forum_topics', $action);
    }

    public function onNewStickerSet(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'new_sticker_set', $action);
    }

    public function onStickerSetsOrder(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'sticker_sets_order', $action);
    }

    public function onStickerSets(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'sticker_sets', $action);
    }

    public function onSavedGifs(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'saved_gifs', $action);
    }

    public function onFavedStickers(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'faved_stickers', $action);
    }

    public function onRecentStickers(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'recent_stickers', $action);
    }

    public function onEmojiGame(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'emoji_game', $action);
    }

    public function onQuickReplies(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'quick_replies', $action);
    }

    public function onNewQuickReply(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'new_quick_reply', $action);
    }

    public function onDeleteQuickReply(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'delete_quick_reply', $action);
    }

    public function onQuickReplyMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'quick_reply_message', $action);
    }

    public function onDeleteQuickReplyMessages(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'delete_quick_reply_messages', $action);
    }

    public function onNewAuthorization(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'new_authorization', $action);
    }

    public function onLoginToken(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'login_token', $action);
    }

    public function onWebPage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'web_page', $action);
    }

    public function onConfig(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'config', $action);
    }

    public function onDcOptions(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'dc_options', $action);
    }

    public function onPtsChanged(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'pts_changed', $action);
    }

    public function onLangPack(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'lang_pack', $action);
    }

    public function onAutoSaveSettings(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'auto_save_settings', $action);
    }

    public function onTheme(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'theme', $action);
    }

    public function onSmsJob(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'sms_job', $action);
    }

    public function onAiComposeTones(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'ai_compose_tones', $action);
    }

    public function onWebBrowserException(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'web_browser_exception', $action);
    }

    public function onWebBrowserSettings(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'web_browser_settings', $action);
    }

    public function onEphemeralMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'new_ephemeral_message', $action);
    }

    public function onEditEphemeralMessage(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'edited_ephemeral_message', $action);
    }

    public function onDeleteEphemeralMessages(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'deleted_ephemeral_messages', $action);
    }

    public function onEphemeralCallbackQuery(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'ephemeral_callback', $action);
    }

    public function onBotStarsSubscription(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', 'bot_stars_subscription', $action);
    }

    public function onUpdate(Closure|array|string $action)
    {
        return $this->addListen('UPDATE', '*', $action)->fallback();
    }
}
