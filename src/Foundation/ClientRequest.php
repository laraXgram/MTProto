<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;
use LaraGram\Listening\Contracts\ProvidesListenContext;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Generated\Types;
use LaraGram\MTProto\TL\TLObject;
use LaraGram\Support\Str;
use LaraGram\Support\Traits\Conditionable;
use LaraGram\Support\Traits\Macroable;

/**
 * Request object for MTProto Client updates.
 *
 * @property-read Types\Message|null $message              Message object (updateNewMessage, updateEditMessage, etc.)
 * @property-read int|null $pts                            PTS counter
 * @property-read int|null $pts_count                      PTS count delta
 * @property-read int|null $id                             Generic ID field
 * @property-read int|null $random_id                      Random ID
 * @property-read \LaraGram\MTProto\Generated\Methods\Messages|int[]|null $messages  `messages` namespace (method call) OR message IDs (updateDeleteMessages payload)
 * @property-read int|null $user_id                        User ID
 * @property-read Types\SendMessageAction|null $action     Typing action (updateUserTyping)
 * @property-read int|null $chat_id                        Chat ID
 * @property-read Types\Peer|null $from_id                 Sender peer
 * @property-read Types\Peer|null $peer_id                 Chat/user peer
 * @property-read Types\Peer|null $peer                    Peer (for notify settings, etc.)
 * @property-read Types\ChatParticipants|null $participants Chat participants
 * @property-read Types\UserStatus|null $status            User online status (updateUserStatus)
 * @property-read string|null $first_name                  First name (updateUserName)
 * @property-read string|null $last_name                   Last name (updateUserName)
 * @property-read Types\Username[]|null $usernames         Usernames (updateUserName)
 * @property-read \LaraGram\MTProto\Generated\Methods\Phone|string|null $phone  `phone` namespace (method call) OR phone number (updateUserPhone payload)
 * @property-read int|null $date                           Date timestamp
 * @property-read Types\MessageMedia|null $media           Media (updateServiceNotification)
 * @property-read Types\MessageEntity[]|null $entities     Entities
 * @property-read string|null $type                        Type string
 * @property-read string|null $text                        Text (updateTranscribedAudio)
 * @property-read Types\WebPage|null $webpage              Web page (updateWebPage)
 * @property-read int|null $channel_id                     Channel ID
 * @property-read int|null $max_id                         Max read ID
 * @property-read int|null $views                          Message views count
 * @property-read int|null $forwards                       Message forwards count
 * @property-read int|null $folder_id                      Folder ID
 * @property-read int|null $still_unread_count             Still unread count
 * @property-read int|null $qts                            QTS counter
 * @property-read string|null $query                       Inline query string
 * @property-read int|null $query_id                       Inline query ID
 * @property-read string|null $offset                      Inline query offset
 * @property-read Types\GeoPoint|null $geo                 Geo point
 * @property-read string|null $data                        Callback query data
 * @property-read int|null $chat_instance                  Callback chat instance
 * @property-read string|null $game_short_name             Game short name
 * @property-read int|null $bot_id                         Bot ID
 * @property-read int|null $top_msg_id                     Top message ID
 * @property-read Types\DraftMessage|null $draft           Draft message
 * @property-read bool|null $pinned                        Pinned flag
 * @property-read Types\WidgetQueryType|null $query_type   Widget query type (onWidgetQuery)
 * @property-read int|null $widget_id                      Shared widget ID (onWidgetQuery)
 * @property-read string|null $public_data                 Widget public data (onWidgetData)
 * @property-read string|null $chat_data                   Widget chat data (onWidgetData)
 * @property-read int|null $inviter_id                     Inviter user ID
 * @property-read int|null $version                        Version
 * @property-read Types\PhoneCall|null $phone_call         Phone call
 * @property-read int|null $phone_call_id                  Phone call ID
 * @property-read Types\Poll|null $poll                    Poll
 * @property-read Types\PollResults|null $results          Poll results
 * @property-read int|null $poll_id                        Poll ID
 * @property-read Types\ChatBannedRights|null $default_banned_rights Default banned rights
 * @property-read Types\PeerSettings|null $settings        Peer settings
 * @property-read int|null $actor_id                       Actor user ID (updateChatParticipant)
 * @property-read Types\ChatParticipant|null $prev_participant Previous participant
 * @property-read Types\ChatParticipant|null $new_participant New participant
 * @property-read Types\ExportedChatInvite|null $invite    Export invite
 * @property-read bool|null $stopped                       Bot stopped flag
 * @property-read Types\BotCommand[]|null $commands        Bot commands
 * @property-read int|null $story_id                       Story ID
 * @property-read Types\StoryItem|null $story              Story item
 * @property-read Types\MessageReactions|null $reactions   Message reactions
 * @property-read Types\Reaction|null $reaction            Single reaction
 * @property-read Types\Reaction[]|null $old_reactions     Old reactions
 * @property-read Types\Reaction[]|null $new_reactions     New reactions
 * @property-read Types\InputGroupCall|null $call          Group call
 * @property-read string|null $connection_id               Business connection ID
 * @property-read Types\BotBusinessConnection|null $connection Business connection
 * @property-read Types\StarsAmount|null $balance          Stars balance
 * @property-read Types\EmojiStatus|null $emoji_status     Emoji status
 * @property-read bool|null $blocked                       Blocked flag
 * @property-read int|null $timeout                        Timeout value
 * @property-read int|null $ttl_period                     TTL period
 *
 * @method mixed uploadFile(string $path, ?string $fileName = null, ?callable $progress = null)
 * @method mixed uploadBytes(string $contents, string $fileName, ?callable $progress = null)
 * @method mixed sendPhoto(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendDocument(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendVideo(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendAudio(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendVoice(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendSticker(string|int $peer, string $path, array $params = [])
 * @method mixed sendAnimation(string|int $peer, string $path, ?string $message = null, array $params = [])
 * @method mixed sendVideoNote(string|int $peer, string $path, array $params = [])
 * @method mixed sendRichMessage(string|int $peer, string $content, string $format = 'markdown', array $params = [])
 * @method mixed forwardMessages(string|int $fromPeer, string|int $toPeer, int|array $ids, array $params = [])
 * @method mixed copyMessages(string|int $fromPeer, string|int $toPeer, int|array $ids, array $params = [])
 * @method mixed editMessage(string|int $peer, int $id, ?string $message = null, array $params = [], ?string $parse_mode = null)
 * @method mixed getMessages(string|int $peer, int|array $ids)
 * @method mixed markAsRead(string|int $peer, int $maxId = 0)
 * @method mixed sendChatAction(string|int $peer, string $action = 'typing', array $params = [])
 * @method mixed sendTyping(string|int $peer)
 * @method mixed blockUser(string|int $peer)
 * @method mixed unblockUser(string|int $peer)
 * @method mixed getFullChat(string|int $peer)
 * @method mixed banChatMember(string|int $peer, string|int $user, int $until = 0)
 * @method mixed kickChatMember(string|int $peer, string|int $user)
 * @method mixed promoteChatMember(string|int $peer, string|int $user, array|string $rights = [], string $rank = '')
 * @method mixed restrictChatMember(string|int $peer, string|int $user, array|string $restrictions, int $until = 0)
 * @method mixed sendReaction(string|int $peer, int $msgId, string|int|array|null $reaction = null, bool $big = false, bool $addToRecent = false)
 * @method mixed getParticipants(string|int $peer, string $filter = 'recent', int $offset = 0, int $limit = 200, string $q = '')
 * @method mixed downloadStory(string|int|array $peerOrStory, ?int $id = null, ?string $path = null)
 * @method mixed sendStory(string|int $peer, string|array $media, array $params = [])
 * @method mixed setProfilePhoto(string $path)
 * @method mixed setChatPermissions(string|int $peer, array|string $restrictions)
 * @method mixed setSlowMode(string|int $peer, int $seconds)
 * @method mixed setChatProtectedContent(string|int $peer, bool $enabled = true)
 * @method mixed transferChatOwnership(string|int $peer, string|int $user, string $password)
 * @method string getMessageLink(string|int $peer, int $msgId, bool $thread = false, bool $grouped = false)
 * @method mixed archiveChats(string|int|array $peers)
 * @method mixed unarchiveChats(string|int|array $peers)
 * @method mixed muteChat(string|int $peer, int $until = 0x7FFFFFFF)
 * @method mixed unmuteChat(string|int $peer)
 * @method mixed inlineQuery(string|int $bot, string $query = '', string|int $peer = 'me', string $offset = '', ?array $location = null)
 * @method mixed sendInlineResult(string|int $peer, int $queryId, string $resultId, array $params = [])
 * @method mixed sendInlineQuery(string|int $peer, string|int $bot, string $query, int $index = 0, array $params = [])
 * @method mixed getCallbackAnswer(string|int $peer, int $msgId, string $data, ?string $password = null, bool $game = false)
 * @method array passwordCheck(string $password)
 * @method string tmpPassword(string $password, int $period = 60)
 * @method mixed sendWidget(string|int $peer, string $html, ?string $caption = null, array $params = [])
 * @method mixed sendSharedWidget(string|int $peer, int $widgetId, ?string $caption = null, array $params = [])
 * @method mixed editWidget(string|int $peer, int $msgId, ?string $publicData = null, ?string $chatData = null)
 * @method mixed editInlineWidget(array|TLObject $id, ?string $publicData = null, ?string $chatData = null)
 * @method mixed editSharedWidget(int $widgetId, string $publicData, ?int $cacheTime = null)
 * @method mixed answerWidgetQuery(int $queryId, ?string $result = null)
 * @method string|null queryWidget(string|int $peer, int $msgId, string $data = '', ?array $queryType = null)
 * @method mixed getWidgetPublicData(string|int $peer, int $msgId, int $hash = 0)
 * @method string|null widgetHtml(array|TLObject $source)
 * @method mixed transcribeAudioFile(string|array $file)
 * @method ?array getFileInfo(TLObject|array $media, ?string $thumbSize = null)
 * @method \Generator iterateAdminLog(array|string|int $peer, string $query = '', array $params = [], ?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateBlockedUsers(?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateCommonChats(array|string|int $user, ?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateDialogs(?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateForumTopics(array|string|int $peer, ?int $limit = null, int $pageSize = 100, string $query = '')
 * @method \Generator iterateHistory(array|string|int $peer, ?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateJoinRequests(array|string|int $peer, ?int $limit = null, int $pageSize = 100, string $query = '')
 * @method \Generator iterateParticipants(array|string|int $peer, string $filter = 'recent', string $q = '', int $pageSize = 200)
 * @method \Generator iterateReplies(array|string|int $peer, int $msgId, ?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateSavedStarGifts(array|string|int $peer = 'me', ?int $limit = null, int $pageSize = 50, array $params = [])
 * @method \Generator iterateSearch(array|string|int $peer, string $query = '', array $params = [], ?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateStarsTransactions(array|string|int $peer = 'me', ?int $limit = null, ?string $direction = null, int $pageSize = 50, bool $ton = false)
 * @method \Generator iterateTakeoutHistory(int $takeoutId, array|string|int $peer, ?int $limit = null, int $pageSize = 100)
 * @method \Generator iterateWalletTransactions(?int $limit = null, ?string $direction = null, int $pageSize = 50)
 * @method array getCurrencyRates()
 * @method array getMe()
 * @method array getTonConnectSessions()
 * @method array sendSecretMessage(int $chatId, string $text, int $ttl = 0)
 * @method array startSecretChat(int $userId)
 * @method array takeoutContacts(int $takeoutId)
 * @method bool checkBotUsername(string $username, bool $additional = false)
 * @method bool finishTakeout(int $takeoutId, bool $success = true)
 * @method int downloadToFile(array $location, int $dcId, string $path, int $size = 0)
 * @method int initTakeout(array $opts = [])
 * @method mixed addBotUsername(array|string|int $bot, string $username)
 * @method mixed addChatMembers(array|string|int $peer, array|string|int $users, int $fwdLimit = 100)
 * @method mixed addContact(array|string|int $user, string $firstName, string $lastName = '', string $phone = '')
 * @method mixed answerCallback(int $queryId, string $text = '', bool $alert = false, ?string $url = null, int $cacheTime = 0)
 * @method mixed answerInlineQuery(int $queryId, array $results, array $params = [])
 * @method mixed answerPreCheckout(int $queryId, ?string $error = null)
 * @method mixed answerShipping(int $queryId, array $options = [], ?string $error = null)
 * @method mixed appendChecklistItems(array|string|int $peer, int $msgId, array $items, int $startId = 1)
 * @method mixed approveAllCommunityPeerLinks(array|string|int $community, bool $reject = false)
 * @method mixed approveAllJoinRequests(array|string|int $peer, ?string $link = null)
 * @method mixed approveCommunityPeerLink(array|string|int $community, array|string|int $peer, bool $reject = false)
 * @method mixed approveJoinRequest(array|string|int $peer, array|string|int $user)
 * @method mixed checkInviteLink(string $link)
 * @method mixed clearAllDrafts()
 * @method mixed closeForumTopic(array|string|int $peer, int $topicId, bool $closed = true)
 * @method mixed closePoll(array|string|int $peer, int $msgId, ?int $pollId = null, array $params = [])
 * @method mixed closeTonConnectSession(int $sessionId)
 * @method mixed composeRichMessageWithAI(array $text, array $params = [])
 * @method mixed convertStarGift(array|string|int $gift)
 * @method mixed convertToSupergroup(array|string|int $peer)
 * @method mixed createChannel(string $title, string $about = '')
 * @method mixed createCommunity(string $title, array|string|int $peer, ?string $about = null, bool $hidden = false)
 * @method mixed createForumTopic(array|string|int $peer, string $title, array $params = [])
 * @method mixed createGroup(string $title, array $users = [])
 * @method mixed createSupergroup(string $title, string $about = '')
 * @method mixed declineAllJoinRequests(array|string|int $peer, ?string $link = null)
 * @method mixed declineJoinRequest(array|string|int $peer, array|string|int $user)
 * @method mixed deleteAllWelcomeMessages(array|string|int $peer)
 * @method mixed deleteChat(array|string|int $peer)
 * @method mixed deleteChatMemberMessages(array|string|int $peer, array|string|int $user)
 * @method mixed deleteChatMessages(array|string|int $peer, array|int $ids, bool $revoke = true)
 * @method mixed deleteChatPhoto(array|string|int $peer)
 * @method mixed deleteContacts(array|string|int $users)
 * @method mixed deleteEphemeral(array|string|int|null $peer, array|string|int $receiver, int $id)
 * @method mixed deleteForumTopic(array|string|int $peer, int $topicId)
 * @method mixed deleteHistory(array|string|int $peer, int $maxId = 0, bool $forEveryone = false)
 * @method mixed deleteInviteLink(array|string|int $peer, string $link)
 * @method mixed deleteProfilePhotos(array $photos)
 * @method mixed deleteScheduledMessages(array|string|int $peer, array|int $ids)
 * @method mixed deleteStarGift(array|string|int $gift)
 * @method mixed deleteStories(array|string|int $peer, array|int $ids)
 * @method mixed deleteWelcomeMessage(array|string|int $peer, int $id)
 * @method mixed demoteChatMember(array|string|int $peer, array|string|int $user)
 * @method mixed editEphemeral(array|string|int|null $peer, array|string|int $receiver, int $id, array $params = [])
 * @method mixed editFactCheck(array|string|int $peer, int $msgId, string $text)
 * @method mixed editForumTopic(array|string|int $peer, int $topicId, array $params = [])
 * @method mixed editInviteLink(array|string|int $peer, string $link, array $params = [])
 * @method mixed editStory(array|string|int $peer, int $id, array $params = [])
 * @method mixed exportInviteLink(array|string|int $peer, array $params = [])
 * @method mixed exportWalletSecretPhrase(?string $password = null)
 * @method mixed getAllDrafts()
 * @method mixed getBoostsStatus(array|string|int $peer)
 * @method mixed getBotCommands(array|string $scope = 'default', string $langCode = '')
 * @method mixed getChatMember(array|string|int $peer, array|string|int $user)
 * @method mixed getCommunityParticipantJoinedChats(array|string|int $community, array|string|int $participant)
 * @method mixed getCommunityPeerLinkRequests(array|string|int $community, string $offset = '', int $limit = 100)
 * @method mixed getContacts()
 * @method mixed getEphemeralCallbackAnswer(array|string|int $peer, int $id, ?string $data = null)
 * @method mixed getForumTopics(array|string|int $peer, array $params = [])
 * @method mixed getForumTopicsById(array|string|int $peer, array|int $topicIds)
 * @method mixed getInviteLinks(array|string|int $peer, array $params = [])
 * @method mixed getJoinedCommunities()
 * @method mixed getMessageReactions(array|string|int $peer, array|int $ids)
 * @method mixed getMessageReactionsList(array|string|int $peer, int $msgId, string|int|null $reaction = null, string $offset = '', int $limit = 50)
 * @method mixed getMyBoosts()
 * @method mixed getPeerStories(array|string|int $peer)
 * @method mixed getPollResults(array|string|int $peer, int $msgId)
 * @method mixed getPrivacyRule(array|string $key)
 * @method mixed getScheduledMessages(array|string|int $peer, array|int|null $ids = null)
 * @method mixed getStarGiftWithdrawalUrl(array|string|int $gift, string $password)
 * @method mixed getStarGifts(int $hash = 0)
 * @method mixed getStarsStatus(array|string|int $peer = 'me', bool $ton = false)
 * @method mixed getStickerSetByName(string $name, int $hash = 0)
 * @method mixed getStories(array|string|int $peer, array|int $ids)
 * @method mixed getUniqueStarGift(string $slug)
 * @method mixed getUserBoosts(array|string|int $peer, array|string|int $user)
 * @method mixed getUserPhotos(array|string|int $user = 'me', int $offset = 0, int $limit = 100)
 * @method mixed getWallet()
 * @method mixed getWalletAddresses(array $users = [], array $addresses = [], bool $force = false)
 * @method mixed getWalletNfts(string $offset = '', int $limit = 50)
 * @method mixed getWalletTransactions(string $offset = '', int $limit = 50, ?string $direction = null)
 * @method mixed getWelcomeMessages(array|string|int $peer, int $hash = 0)
 * @method mixed hideForumTopic(array|string|int $peer, int $topicId, bool $hidden = true)
 * @method mixed importContact(string $phone, string $firstName, string $lastName = '')
 * @method mixed invoke(string $method, array $params = [])
 * @method mixed invokeWithTakeout(int $takeoutId, string $method, array $params = [])
 * @method mixed joinChat(array|string|int $peer)
 * @method mixed leaveChat(array|string|int $peer)
 * @method mixed markChatUnread(array|string|int $peer, bool $unread = true)
 * @method mixed markMentionsRead(array|string|int $peer, ?int $topMsgId = null)
 * @method mixed markReactionsRead(array|string|int $peer, ?int $topMsgId = null)
 * @method mixed pinChat(array|string|int $peer, bool $pinned = true)
 * @method mixed pinForumTopic(array|string|int $peer, int $topicId, bool $pinned = true)
 * @method mixed pinMessage(array|string|int $peer, int $id, bool $silent = false, bool $oneSide = false)
 * @method mixed pinStory(array|string|int $peer, array|int $ids, bool $pinned = true)
 * @method mixed readMessageContents(array|string|int $peer, array|int $ids)
 * @method mixed readStories(array|string|int $peer, int $maxId)
 * @method mixed removeBotUsername(array|string|int $bot, string $username)
 * @method mixed reorderForumTopics(array|string|int $peer, array $order, bool $force = false)
 * @method mixed reportEphemeral(array|string|int $peer, int $id, string $option, string $message = '')
 * @method mixed requestChatJoinWebView(int $queryId, string $platform, array $params = [])
 * @method mixed resetBotCommands(array|string $scope = 'default', string $langCode = '')
 * @method mixed resetShareTheme(array|string|int $peer = 'me')
 * @method mixed revokeInviteLink(array|string|int $peer, string $link)
 * @method mixed saveDraft(array|string|int $peer, string $message = '', array $params = [])
 * @method mixed saveStarGift(array|string|int $gift, bool $unsave = false)
 * @method mixed searchGlobal(string $query = '', array $params = [])
 * @method mixed searchMessages(array|string|int $peer, string $query = '', array $params = [])
 * @method mixed sendAlbum(array|string|int $peer, array $items, array $params = [])
 * @method mixed sendChecklist(array|string|int $peer, string $title, array $items, array $params = [])
 * @method mixed sendContact(array|string|int $peer, string $phoneNumber, string $firstName, array $params = [])
 * @method mixed sendDice(array|string|int $peer, string $emoticon = '🎲', array $params = [])
 * @method mixed sendEphemeral(array|string|int|null $peer, array|string|int $receiver, string $message, array $params = [])
 * @method mixed sendLocation(array|string|int $peer, float $lat, float $long, array $params = [])
 * @method mixed sendMedia(array|string|int $peer, array $media, ?string $message = null, array $params = [])
 * @method mixed sendMediaById(array|string|int $peer, string $fileId, ?string $message = null, array $params = [])
 * @method mixed sendPaidMedia(array|string|int $peer, int $starsAmount, array $mediaList, ?string $message = null, array $params = [])
 * @method mixed sendPaidReaction(array|string|int $peer, int $msgId, int $count = 1)
 * @method mixed sendPoll(array|string|int $peer, string $question, array $answers, array $params = [])
 * @method mixed sendScheduledMessages(array|string|int $peer, array|int $ids)
 * @method mixed sendTopicMessage(array|string|int $peer, int $topicId, string $message, array $params = [])
 * @method mixed sendVenue(array|string|int $peer, float $lat, float $long, string $title, string $address, array $params = [])
 * @method mixed sendWelcomeMessage(array|string|int $peer, array|string|int $receiver, string $message, array $params = [])
 * @method mixed setBotCommands(array $commands, array|string $scope = 'default', string $langCode = '')
 * @method mixed setBotInfo(array $params = [])
 * @method mixed setBotMenuButton(array|string|int $user, array|string $button = 'commands')
 * @method mixed setChatDescription(array|string|int $peer, string $about)
 * @method mixed setChatMemberRank(array|string|int $peer, array|string|int $user, string $rank)
 * @method mixed setChatPhoto(array|string|int $peer, string $path)
 * @method mixed setChatTitle(array|string|int $peer, string $title)
 * @method mixed setCustomVerification(array|string|int $peer, bool $enabled = true, ?string $description = null, array|string|int|null $bot = null)
 * @method mixed setDefaultAdminRights(array|string $rights, bool $forChannels = false)
 * @method mixed setEmojiStatus(array|int|null $emoji, int $until = 0)
 * @method mixed setOnline(bool $online = true)
 * @method mixed setPrivacyRule(array|string $key, array|string $rules)
 * @method mixed setShareThemeBackdrop(array|string|int $peer, ?int $backdropId = null, array $emojis = [])
 * @method mixed setShareThemeGift(array|string|int $peer, array|string|int $gift)
 * @method mixed setUsername(string $username)
 * @method mixed takeout(callable $callback, array $opts = [])
 * @method mixed toggleChecklistItems(array|string|int $peer, int $msgId, array $completed = [], array $incompleted = [])
 * @method mixed toggleCommunityCollapsed(array|string|int $community, bool $collapsed = true)
 * @method mixed toggleCommunityParticipantBanned(array|string|int $community, array|string|int $participant, bool $unban = false)
 * @method mixed toggleCommunityPeerLink(array|string|int $community, array|string|int $peer, bool $visible = false, bool $hidden = false, bool $deleted = false)
 * @method mixed toggleForum(array|string|int $peer, bool $enabled = true, bool $tabs = false, bool $sidebar = false)
 * @method mixed toggleSuggestedPost(array|string|int $peer, int $msgId, bool $accept = true, array $params = [])
 * @method mixed toncenter(string $endpoint, array|string|null $query = null, array|string|null $payload = null, bool $post = false)
 * @method mixed transferStarGift(array|string|int $gift, array|string|int $to)
 * @method mixed translateMessages(array|string|int $peer, array|int $ids, string $toLang)
 * @method mixed translateRichMessage(string $toLang, array $params = [])
 * @method mixed unbanChatMember(array|string|int $peer, array|string|int $user)
 * @method mixed unpinAllMessages(array|string|int $peer)
 * @method mixed unpinMessage(array|string|int $peer, int $id)
 * @method mixed updateProfile(array $params)
 * @method mixed voteInPoll(array|string|int $peer, int $msgId, array|string|int $options)
 * @method mixed withdrawStarGift(array|string|int $gift, string $password, bool $keepOnProfile = false)
 * @method string downloadToMemory(array $location, int $dcId, int $size = 0)
 * @method string fileId(array $media)
 *
 * @mixin \LaraGram\MTProto\Generated\ClientIdeHelper
 */
class ClientRequest implements ProvidesListenContext
{
    use Conditionable, Macroable;

    /**
     * The raw MTProto update data.
     */
    protected array $data;

    /**
     * The MTProto constructor name (e.g. 'updateNewMessage').
     */
    protected string $type;

    /**
     * The verb this dispatch pass runs under (one of ClientType's 8 verbs).
     */
    protected ?string $verb = null;

    /**
     * Back-compat alias kept for callers that set a media-filtered verb.
     * @deprecated use setListenVerb()
     */
    protected ?string $mediaVerb = null;

    /**
     * The listen resolver callback.
     */
    protected ?Closure $listenResolver = null;

    /**
     * The session name this update came from.
     */
    protected string $session = 'default';

    /**
     * The MTProto Client instance (injected by the start command).
     */
    protected ?Client $client = null;

    /**
     * Create a new ClientRequest.
     *
     * @param array $update The raw MTProto update array
     * @param string $type The constructor name (e.g. 'updateNewMessage')
     * @param string $session The session name
     */
    public function __construct(array $update = [], string $type = '', string $session = 'default')
    {
        $this->data = $update;
        $this->type = $type;
        $this->session = $session;
    }

    /**
     * Create a ClientRequest from an MTProto update.
     */
    public static function fromUpdate(array $update, string $type, string $session = 'default'): static
    {
        return new static($update, $type, $session);
    }

    /**
     * Get the raw constructor type name (e.g. 'updateNewMessage').
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Get the session name this update arrived on.
     */
    public function session(): string
    {
        return $this->session;
    }

    /**
     * Reply/act through a *different* session than the one this update arrived
     * on.
     */
    public function usingSession(string $session): static
    {
        $clone = clone $this;
        $clone->session = $session;
        $clone->client = null;

        return $clone;
    }

    /**
     * Get the full update as a raw array.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get the full update as a JSON string.
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->data, $options | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * Get the message text.
     */
    public function text(): ?string
    {
        return $this->data['message']['message'] ?? null;
    }

    /**
     * Get the callback query data.
     */
    public function callbackData(): ?string
    {
        return $this->data['data'] ?? null;
    }

    /**
     * Without arguments, get this update's inline query string. With a `$bot`,
     * ask that bot for inline results, like {@see Client::inlineQuery()}.
     */
    public function inlineQuery(
        string|int|array|null $bot = null,
        string $query = '',
        string|int|array $peer = 'me',
        string $offset = '',
        ?array $location = null,
    ): mixed {
        if ($bot !== null) {
            return $this->client()->inlineQuery($bot, $query, $peer, $offset, $location);
        }

        return $this->data['query'] ?? null;
    }

    /**
     * Reply to this update's message in the same chat. Extra
     * `messages.sendMessage` params (parse_mode, reply_markup, ...) pass through.
     */
    public function reply(string $message, array $params = []): mixed
    {
        $peer = $this->chatId();
        if ($peer === null) {
            throw new \RuntimeException('No chat on this update to reply to');
        }

        return $this->client()->invoke('messages.sendMessage', array_merge(array_filter([
            'peer' => $peer,
            'message' => $message,
            'reply_to' => $this->messageId(),
        ], static fn ($value): bool => $value !== null), $params));
    }

    /**
     * Get the shared FileDecoder for this session (lazy, reused).
     */
    public function file(): FileDecoder
    {
        return $this->client()->fileDecoder();
    }

    /**
     * Download whatever media this update carries - photo, video, animation,
     * document, voice, sticker, or story - no arguments needed. With `$path`
     * it streams to disk and returns bytes written; otherwise returns the raw
     * bytes.
     *
     * @return string|int bytes (no path) or bytes-written (with path)
     */
    public function download(?string $path = null, ?string $thumbSize = null): string|int
    {
        return $path === null
            ? $this->downloadMedia($thumbSize)
            : $this->downloadMediaToFile($path, $thumbSize);
    }

    /**
     * The message this update carries, as a plain array - regardless of whether
     * property access has already wrapped it into a TLObject.
     */
    private function messageArray(): ?array
    {
        $m = $this->data['message'] ?? null;
        if ($m instanceof TLObject) {
            return $m->toArray();
        }

        return is_array($m) ? $m : null;
    }

    /**
     * The id of the message this update carries (null if none).
     */
    public function messageId(): ?int
    {
        $id = $this->messageArray()['id'] ?? $this->data['id'] ?? $this->data['msg_id'] ?? null;

        return $id !== null ? (int)$id : null;
    }

    /**
     * A resolver-friendly id for the chat this update belongs to, taken from
     * the message's peer_id (user/chat/channel).
     */
    public function chatId(): ?int
    {
        $peer = $this->messageArray()['peer_id']
            ?? $this->data['peer_id']
            ?? $this->data['peer']
            ?? null;
        if ($peer instanceof TLObject) {
            $peer = $peer->toArray();
        }
        if (!is_array($peer)) {
            return null;
        }

        $id = $peer['user_id'] ?? $peer['channel_id'] ?? $peer['chat_id'] ?? null;

        return $id !== null ? (int)$id : null;
    }

    /**
     * Mark this chat's history read up to the current message - i.e. put a
     * "seen" tick on the user's message. Routes to channels/messages readHistory
     * as appropriate.
     */
    public function read(): mixed
    {
        $peer = $this->chatId();
        if ($peer === null) {
            throw new \RuntimeException('No chat on this update to mark as read');
        }

        return $this->client()->markAsRead($peer, $this->messageId() ?? 0);
    }

    /**
     * Alias of {@see read()} - mark the user's message as seen.
     */
    public function seen(): mixed
    {
        return $this->read();
    }

    /**
     * Download media and return its bytes: `$media` when given, otherwise the
     * media attached to this update's message.
     *
     * @param array|TLObject|string|null $media Media to download. A string is
     *        read as the thumb size, so `downloadMedia('x')` targets this update.
     * @param string|null $thumbSize Photo size type (e.g. 'x', 'y', 'w'). Defaults to largest.
     * @return string The file bytes
     */
    public function downloadMedia(array|TLObject|string|null $media = null, ?string $thumbSize = null): string
    {
        if (is_array($media) || $media instanceof TLObject) {
            return $this->client()->downloadMedia($media, $thumbSize);
        }

        $thumbSize = $media ?? $thumbSize;
        $media = $this->mediaArray();
        if ($media === null) {
            throw new \RuntimeException('No media found in this update');
        }

        return $this->file()->downloadMedia($media, $thumbSize);
    }

    /**
     * The media attached to this update, as a plain array, tolerant of TLObject
     * wrapping from prior property access.
     */
    private function mediaArray(): ?array
    {
        $media = $this->messageArray()['media'] ?? $this->data['media'] ?? null;
        if ($media instanceof TLObject) {
            return $media->toArray();
        }

        return is_array($media) ? $media : null;
    }

    /**
     * Download media to a file: `downloadMediaToFile($media, $path)`, or
     * `downloadMediaToFile($path)` for the media attached to this update.
     *
     * @param array|TLObject|string|null $media Media to download, or the
     *        destination path to use this update's media.
     * @param string|null $path Destination path, or the thumb size when `$media` is the path.
     * @param string|null $thumbSize Photo size type (e.g. 'x', 'y', 'w'). Defaults to largest.
     * @return int Bytes written
     */
    public function downloadMediaToFile(array|TLObject|string|null $media = null, ?string $path = null, ?string $thumbSize = null): int
    {
        if (is_array($media) || $media instanceof TLObject) {
            if ($path === null) {
                throw new \InvalidArgumentException('A destination path is required');
            }

            return $this->client()->downloadMediaToFile($media, $path, $thumbSize);
        }

        if (is_string($media)) {
            [$path, $thumbSize] = [$media, $path ?? $thumbSize];
        }
        if ($path === null) {
            throw new \InvalidArgumentException('A destination path is required');
        }

        $media = $this->mediaArray();
        if ($media === null) {
            throw new \RuntimeException('No media found in this update');
        }

        return $this->file()->downloadMediaToFile($media, $path, $thumbSize);
    }

    /**
     * Get info about the media file without downloading it.
     *
     * @param string|null $thumbSize Photo size type override
     * @return array|null File info or null if no media
     */
    public function getMediaInfo(?string $thumbSize = null): ?array
    {
        $media = $this->mediaArray();
        if ($media === null) {
            return null;
        }

        return $this->file()->getFileInfo($media, $thumbSize);
    }

    /**
     * Get the verb for the Listening system
     */
    public function method(): string
    {
        if ($this->mediaVerb !== null) {
            return $this->mediaVerb;
        }

        return $this->verb ??= ClientType::UPDATE->name;
    }

    /**
     * Set the verb for this dispatch pass (UPDATE, TEXT, MESSAGE, DICE, …).
     */
    public function setListenVerb(string $verb): static
    {
        $this->verb = $verb;
        return $this;
    }

    /**
     * Back-compat: set a media-filtered verb. Prefer setListenVerb().
     * @deprecated
     */
    public function setMediaVerb(string $verb): static
    {
        $this->mediaVerb = $verb;
        return $this;
    }

    /**
     * Check if the method matches.
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === $method;
    }

    /**
     * Shared per-update dispatch state, so the several verb passes an update
     * fans out into agree on whether a primary listen has already handled it.
     * `{ done: bool }`.
     */
    protected ?object $dispatchState = null;

    /**
     * Attach the shared dispatch state (set once per incoming update).
     */
    public function setDispatchState(object $state): static
    {
        $this->dispatchState = $state;
        return $this;
    }

    /**
     * Whether a non-overlap primary listen has already handled this update in
     * an earlier verb pass.
     */
    public function dispatchDone(): bool
    {
        return (bool)($this->dispatchState->done ?? false);
    }

    /**
     * Mark this update as handled by a primary listen.
     */
    public function markDispatchDone(): void
    {
        if ($this->dispatchState !== null) {
            $this->dispatchState->done = true;
        }
    }

    /**
     * {@inheritdoc}
     *
     * The verb is the resolved MTProto match verb (native, not Bot-API-remapped).
     */
    public function listenVerb(): string
    {
        return $this->method();
    }

    /**
     * {@inheritdoc}
     *
     * Returns the regex-matchable string for the given verb. Structural verbs
     * (catch-all media/update handlers, etc.) carry no string to match and
     * return null, the verb match alone is then sufficient.
     */
    public function listenValue(string $verb): ?string
    {
        return match ($verb) {
            'UPDATE' => ClientType::eventKey($this->type),

            'MESSAGE' => $this->contentType(),

            'TEXT' => $this->text(),
            'COMMAND' => ($t = $this->text()) !== null ? Str::replaceFirst('/', '', $t) : null,
            'REFERRAL' => ($t = $this->text()) !== null ? Str::replaceFirst('/start ', '', $t) : null,

            'CALLBACK_DATA' => $this->callbackData(),

            'DICE' => $this->diceValue(),

            default => null,
        };
    }

    /**
     * The media content type of this message (photo/video/…), or null.
     */
    protected function contentType(): ?string
    {
        $message = $this->data['message'] ?? null;

        if (!is_array($message)) {
            return null;
        }

        return ClientType::mediaTypeFromMessage($message);
    }

    /**
     * The dice value as "emoji,value" for the DICE matcher.
     */
    protected function diceValue(): ?string
    {
        $media = $this->data['message']['media'] ?? null;

        if (!is_array($media) || ($media['_'] ?? '') !== 'messageMediaDice') {
            return null;
        }

        return ($media['emoticon'] ?? '') . ',' . ($media['value'] ?? 0);
    }

    /**
     * {@inheritdoc}
     */
    public function entities(): array
    {
        return $this->data['message']['entities'] ?? [];
    }

    /**
     * Check whether this update represents a message sent by this session
     * itself (an outgoing message), as opposed to one received from someone
     * else (incoming).
     */
    public function isOutgoing(): bool
    {
        return match ($this->type) {
            'updateShortSentMessage' => true,
            'updateShortMessage', 'updateShortChatMessage' => !empty($this->data['out']),
            'updateNewMessage', 'updateNewChannelMessage',
            'updateEditMessage', 'updateEditChannelMessage',
            'updateNewEphemeralMessage', 'updateEditEphemeralMessage' => !empty($this->data['message']['out']),
            default => false,
        };
    }

    /**
     * {@inheritdoc}
     *
     * The scope for an MTProto update is the **session** it arrived on, so a
     * listen file bound with `forSessions('x')` only runs for that account.
     * Carried on the request object → coroutine/multi-session safe.
     */
    public function listenScope(): ?string
    {
        return $this->session;
    }

    /**
     * Get the MTProto Client instance for this session.
     */
    public function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        /** @var ClientManager $manager */
        $manager = app('mtproto.manager');
        return $manager->client($this->session);
    }

    /**
     * Set the MTProto Client instance.
     */
    public function setClient(Client $client): static
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Get the listen handling the request.
     */
    public function listen(string $param = null, mixed $default = null): mixed
    {
        $listen = call_user_func($this->getListenResolver());

        if (is_null($listen) || is_null($param)) {
            return $listen;
        }

        return $listen->parameter($param, $default);
    }

    /**
     * Get the listen resolver callback.
     */
    public function getListenResolver(): Closure
    {
        return $this->listenResolver ?: function () {
        };
    }

    /**
     * Set the listen resolver callback.
     */
    public function setListenResolver(Closure $callback): static
    {
        $this->listenResolver = $callback;
        return $this;
    }

    /**
     * Property-style access to update data.
     *
     * @return \LaraGram\MTProto\TL\TLObject|mixed|null
     */
    public function __get(string $name): mixed
    {
        $isNamespace = Client::isNamespace($name);
        $hasData = array_key_exists($name, $this->data);

        // Name is an API namespace only (no colliding update key) → the
        // namespace object, so `$request->messages->sendMessage(...)` works.
        if ($isNamespace && !$hasData) {
            return $this->client()->{$name};
        }

        // Name is BOTH a namespace and an update-payload key → defer the
        // meaning to how the caller uses it (method the namespace defines →
        // namespace; property/array/iteration/other method → update value).
        if ($isNamespace && $hasData) {
            return new PendingNamespaceOrUpdate(
                fn() => $this->client()->{$name},
                fn() => $this->wrapDataValue($name),
            );
        }

        // Pure update payload.
        if (!$hasData) {
            return null;
        }

        return $this->wrapDataValue($name);
    }

    /**
     * Wrap a raw update-data value into TLObject(s) where it carries a `_`
     * constructor, caching the result. Scalars and plain arrays pass through.
     */
    private function wrapDataValue(string $name): mixed
    {
        $value = $this->data[$name];

        if (is_array($value)) {
            if (isset($value['_'])) {
                $wrapped = TLObject::fromArray($value);
                $this->data[$name] = $wrapped; // cache
                return $wrapped;
            }

            if (!empty($value)) {
                $first = reset($value);
                if (is_array($first) && isset($first['_'])) {
                    $wrapped = array_map(static fn(array $item) => TLObject::fromArray($item), $value);
                    $this->data[$name] = $wrapped; // cache
                    return $wrapped;
                }
            }

            return $value;
        }

        return $value;
    }

    /**
     * Check if a key exists in the update data.
     */
    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Forward MTProto API method calls to the underlying Client.
     *
     * IDE auto-complete is provided via @mixin \LaraGram\MTProto\Generated\ClientIdeHelper.
     */
    public function __call($method, $parameters): mixed
    {
        return $this->client()->{$method}(...$parameters);
    }
}
