<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesEngagement
{
    /**
     * @param string|int|array|null $reaction
     */
    public function sendReaction(
        string|int|array $peer,
        int $msgId,
        string|int|array|null $reaction = null,
        bool $big = false,
        bool $addToRecent = false,
    ): mixed {
        $params = ['peer' => $peer, 'msg_id' => $msgId];

        $reactions = $this->toReactions($reaction);
        if ($reactions !== []) {
            $params['reaction'] = $reactions;
        }
        if ($big) {
            $params['big'] = true;
        }
        if ($addToRecent) {
            $params['add_to_recent'] = true;
        }

        return $this->invoke('messages.sendReaction', $params);
    }

    /**
     * @param int|list<int> $ids
     */
    public function getMessageReactions(string|int|array $peer, int|array $ids): mixed
    {
        return $this->invoke('messages.getMessagesReactions', [
            'peer' => $peer,
            'id' => is_array($ids) ? $ids : [$ids],
        ]);
    }

    /**
     * @param string|int|null $reaction emoji string or custom-emoji document id
     */
    public function getMessageReactionsList(
        string|int|array $peer,
        int $msgId,
        string|int|null $reaction = null,
        string $offset = '',
        int $limit = 50,
    ): mixed {
        $params = ['peer' => $peer, 'id' => $msgId, 'limit' => $limit];

        if ($reaction !== null) {
            $params['reaction'] = $this->toReactions($reaction)[0];
        }
        if ($offset !== '') {
            $params['offset'] = $offset;
        }

        return $this->invoke('messages.getMessageReactionsList', $params);
    }

    /**
     * Vote in a poll. An int option is the answer's position in the poll; a
     * string option is its raw `option` bytes.
     *
     * @param string|int|array<int,string|int> $options
     */
    public function voteInPoll(string|int|array $peer, int $msgId, string|int|array $options): mixed
    {
        $list = is_array($options) ? $options : [$options];
        $known = array_filter($list, is_int(...)) !== []
            ? array_column((array) ($this->pollFromMessage($peer, $msgId)['answers'] ?? []), 'option')
            : [];
        $list = array_map(
            static fn (string|int $o): string => is_int($o) ? ($known[$o] ?? chr($o)) : $o,
            $list,
        );

        return $this->invoke('messages.sendVote', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'options' => $list,
        ]);
    }

    /**
     * Close a poll you sent so it stops accepting votes. Pass `$pollId` to skip
     * looking it up. Extra `messages.editMessage` params pass through $params.
     */
    public function closePoll(string|int|array $peer, int $msgId, ?int $pollId = null, array $params = []): mixed
    {
        $pollId ??= $this->pollFromMessage($peer, $msgId)['id'] ?? null;
        if ($pollId === null) {
            throw new MTProtoException("Message {$msgId} does not contain a poll.");
        }

        return $this->invoke('messages.editMessage', array_merge([
            'peer' => $peer,
            'id' => $msgId,
            'media' => [
                '_' => 'inputMediaPoll',
                'poll' => [
                    '_' => 'poll',
                    'id' => $pollId,
                    'closed' => true,
                    'creator' => true,
                    'question' => ['_' => 'textWithEntities', 'text' => '', 'entities' => []],
                    'answers' => [],
                ],
            ],
        ], $params));
    }

    public function getPollResults(string|int|array $peer, int $msgId): mixed
    {
        return $this->invoke('messages.getPollResults', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'poll_hash' => 0,
        ]);
    }

    /**
     * The `poll` object of a message, or null when it holds none.
     */
    private function pollFromMessage(string|int|array $peer, int $msgId): ?array
    {
        foreach ($this->getMessages($peer, $msgId)['messages'] ?? [] as $message) {
            if (($message['id'] ?? null) === $msgId) {
                return $message['media']['poll'] ?? null;
            }
        }

        return null;
    }

    /**
     * Expand a friendly reaction value into a list of Reaction TL objects.
     *
     * @param string|int|array|null $reaction
     * @return list<array>
     */
    private function toReactions(string|int|array|null $reaction): array
    {
        if ($reaction === null || $reaction === '' || $reaction === []) {
            return [];
        }

        $items = is_array($reaction) ? $reaction : [$reaction];
        $out = [];

        foreach ($items as $item) {
            if (is_array($item) && isset($item['_'])) {
                $out[] = $item;
            } elseif (is_int($item)) {
                $out[] = ['_' => 'reactionCustomEmoji', 'document_id' => $item];
            } else {
                $out[] = ['_' => 'reactionEmoji', 'emoticon' => (string) $item];
            }
        }

        return $out;
    }
}
