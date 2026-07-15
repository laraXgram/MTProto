<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait MessagingExtras
{
    /** @var array<string,string> */
    private const CHAT_ACTIONS = [
        'typing' => 'sendMessageTypingAction',
        'cancel' => 'sendMessageCancelAction',
        'record_video' => 'sendMessageRecordVideoAction',
        'upload_video' => 'sendMessageUploadVideoAction',
        'record_audio' => 'sendMessageRecordAudioAction',
        'record_voice' => 'sendMessageRecordAudioAction',
        'upload_audio' => 'sendMessageUploadAudioAction',
        'upload_voice' => 'sendMessageUploadAudioAction',
        'upload_photo' => 'sendMessageUploadPhotoAction',
        'upload_document' => 'sendMessageUploadDocumentAction',
    ];

    public function sendRichMessage(string|int|array $peer, string $content, string $format = 'markdown', array $params = []): mixed
    {
        $rich = match (strtolower($format)) {
            'markdown', 'md' => ['_' => 'inputRichMessageMarkdown', 'markdown' => $content],
            'html' => ['_' => 'inputRichMessageHTML', 'html' => $content],
            default => throw new MTProtoException("Unknown rich message format: {$format}"),
        };
        if (!empty($params['rtl'])) {
            $rich['rtl'] = true;
        }
        if (!empty($params['noautolink'])) {
            $rich['noautolink'] = true;
        }
        unset($params['rtl'], $params['noautolink']);

        return $this->invoke('messages.sendMessage', array_merge([
            'peer' => $peer,
            'message' => '',
            'rich_message' => $rich,
        ], $params));
    }

    /**
     * @param int|list<int> $ids
     */
    public function forwardMessages(string|int|array $fromPeer, string|int|array $toPeer, int|array $ids, array $params = []): mixed
    {
        return $this->invoke('messages.forwardMessages', array_merge([
            'from_peer' => $fromPeer,
            'to_peer' => $toPeer,
            'id' => is_array($ids) ? $ids : [$ids],
        ], $params));
    }

    /**
     * @param int|list<int> $ids
     */
    public function copyMessages(string|int|array $fromPeer, string|int|array $toPeer, int|array $ids, array $params = []): mixed
    {
        return $this->forwardMessages($fromPeer, $toPeer, $ids, array_merge(['drop_author' => true], $params));
    }

    public function editMessage(string|int|array $peer, int $id, ?string $message = null, array $params = []): mixed
    {
        $call = ['peer' => $peer, 'id' => $id];
        if ($message !== null) {
            $call['message'] = $message;
        }

        return $this->invoke('messages.editMessage', array_merge($call, $params));
    }

    /**
     * @param int|list<int> $ids
     */
    public function getMessages(string|int|array $peer, int|array $ids): mixed
    {
        $list = is_array($ids) ? $ids : [$ids];

        if ($this->isChannelInput($this->inputPeerFor($peer))) {
            return $this->invoke('channels.getMessages', ['channel' => $peer, 'id' => $list]);
        }

        return $this->invoke('messages.getMessages', ['id' => $list]);
    }

    public function markAsRead(string|int|array $peer, int $maxId = 0): mixed
    {
        if ($this->isChannelInput($this->inputPeerFor($peer))) {
            return $this->invoke('channels.readHistory', ['channel' => $peer, 'max_id' => $maxId]);
        }

        return $this->invoke('messages.readHistory', ['peer' => $peer, 'max_id' => $maxId]);
    }

    /**
     * @param int|list<int> $ids
     */
    public function readMessageContents(string|int|array $peer, int|array $ids): mixed
    {
        $list = is_array($ids) ? $ids : [$ids];

        if ($this->isChannelInput($this->inputPeerFor($peer))) {
            return $this->invoke('channels.readMessageContents', ['channel' => $peer, 'id' => $list]);
        }

        return $this->invoke('messages.readMessageContents', ['id' => $list]);
    }

    public function sendChatAction(string|int|array $peer, string $action = 'typing', array $params = []): mixed
    {
        $ctor = self::CHAT_ACTIONS[strtolower($action)] ?? null;
        if ($ctor === null) {
            throw new MTProtoException("Unknown chat action: {$action}");
        }

        $actionObj = ['_' => $ctor];
        if (isset($params['progress'])) {
            $actionObj['progress'] = (int) $params['progress'];
        }

        $call = ['peer' => $peer, 'action' => $actionObj];
        if (isset($params['top_msg_id'])) {
            $call['top_msg_id'] = (int) $params['top_msg_id'];
        }

        return $this->invoke('messages.setTyping', $call);
    }

    public function sendTyping(string|int|array $peer): mixed
    {
        return $this->sendChatAction($peer, 'typing');
    }

    public function blockUser(string|int|array $peer): mixed
    {
        return $this->invoke('contacts.block', ['id' => $peer]);
    }

    public function unblockUser(string|int|array $peer): mixed
    {
        return $this->invoke('contacts.unblock', ['id' => $peer]);
    }

    public function getFullChat(string|int|array $peer): mixed
    {
        $input = $this->inputPeerFor($peer);
        $ctor = $input['_'] ?? '';

        return match ($ctor) {
            'inputPeerChannel' => $this->invoke('channels.getFullChannel', ['channel' => $peer]),
            'inputPeerChat' => $this->invoke('messages.getFullChat', ['chat_id' => $input['chat_id']]),
            default => $this->invoke('users.getFullUser', ['id' => $peer]),
        };
    }

    public function getChatMember(string|int|array $peer, string|int|array $user): mixed
    {
        if (!$this->isChannelInput($this->inputPeerFor($peer))) {
            throw new MTProtoException('getChatMember() requires a supergroup or channel peer.');
        }

        return $this->invoke('channels.getParticipant', ['channel' => $peer, 'participant' => $user]);
    }

    public function searchMessages(string|int|array $peer, string $query = '', array $params = []): mixed
    {
        return $this->invoke('messages.search', array_merge([
            'peer' => $peer,
            'q' => $query,
            'filter' => ['_' => 'inputMessagesFilterEmpty'],
            'min_date' => 0,
            'max_date' => 0,
            'offset_id' => 0,
            'add_offset' => 0,
            'limit' => 100,
            'max_id' => 0,
            'min_id' => 0,
            'hash' => 0,
        ], $params));
    }

    public function searchGlobal(string $query = '', array $params = []): mixed
    {
        return $this->invoke('messages.searchGlobal', array_merge([
            'q' => $query,
            'filter' => ['_' => 'inputMessagesFilterEmpty'],
            'min_date' => 0,
            'max_date' => 0,
            'offset_rate' => 0,
            'offset_peer' => ['_' => 'inputPeerEmpty'],
            'offset_id' => 0,
            'limit' => 100,
        ], $params));
    }

    public function editFactCheck(string|int|array $peer, int $msgId, string $text): mixed
    {
        return $this->invoke('messages.editFactCheck', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'text' => ['_' => 'textWithEntities', 'text' => $text, 'entities' => []],
        ]);
    }

    public function deleteHistory(string|int|array $peer, int $maxId = 0, bool $forEveryone = false): mixed
    {
        if ($this->isChannelInput($this->inputPeerFor($peer))) {
            $call = ['channel' => $peer, 'max_id' => $maxId];
            if ($forEveryone) {
                $call['for_everyone'] = true;
            }

            return $this->invoke('channels.deleteHistory', $call);
        }

        $call = ['peer' => $peer, 'max_id' => $maxId];
        if ($forEveryone) {
            $call['revoke'] = true;
        }

        return $this->invoke('messages.deleteHistory', $call);
    }

    /**
     * @param int|list<int>|null $ids
     */
    public function getScheduledMessages(string|int|array $peer, int|array|null $ids = null): mixed
    {
        if ($ids === null) {
            return $this->invoke('messages.getScheduledHistory', ['peer' => $peer, 'hash' => 0]);
        }

        return $this->invoke('messages.getScheduledMessages', [
            'peer' => $peer,
            'id' => is_array($ids) ? $ids : [$ids],
        ]);
    }

    /**
     * @param int|list<int> $ids
     */
    public function sendScheduledMessages(string|int|array $peer, int|array $ids): mixed
    {
        return $this->invoke('messages.sendScheduledMessages', [
            'peer' => $peer,
            'id' => is_array($ids) ? $ids : [$ids],
        ]);
    }

    /**
     * @param int|list<int> $ids
     */
    public function deleteScheduledMessages(string|int|array $peer, int|array $ids): mixed
    {
        return $this->invoke('messages.deleteScheduledMessages', [
            'peer' => $peer,
            'id' => is_array($ids) ? $ids : [$ids],
        ]);
    }

    /**
     * Compose or rewrite a rich message with AI (proofread / emojify / translate).
     *
     * `$text` is an `InputRichMessage` array (e.g. `inputRichMessageMarkdown`);
     * `$tone` is an `InputAiComposeTone` array (slug, id, or single-use prompt).
     *
     * @param array<string, mixed> $params Extra flags: proofread, emojify,
     *        translate_to_lang, tone.
     */
    public function composeRichMessageWithAI(array $text, array $params = []): mixed
    {
        return $this->invoke('messages.composeRichMessageWithAI', array_merge([
            'text' => $text,
        ], $params));
    }

    /**
     * Translate a rich message (or a peer's existing message ids) to `$toLang`.
     *
     * Provide either `$params['text']` (a list of `InputRichMessage`) or
     * `$params['peer']` + `$params['id']` (message ids). Optional `$params['tone']`.
     *
     * @param array<string, mixed> $params
     */
    public function translateRichMessage(string $toLang, array $params = []): mixed
    {
        return $this->invoke('messages.translateRichMessage', array_merge([
            'to_lang' => $toLang,
        ], $params));
    }

    /**
     * Request the WebView used to join a chat.
     *
     * @param array<string, mixed> $params Optional: theme_params.
     */
    public function requestChatJoinWebView(int $queryId, string $platform, array $params = []): mixed
    {
        return $this->invoke('messages.requestChatJoinWebView', array_merge([
            'query_id' => $queryId,
            'platform' => $platform,
        ], $params));
    }
}
