<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use Generator;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait IteratesChats
{
    public function getParticipants(
        string|int|array $peer,
        string $filter = 'recent',
        int $offset = 0,
        int $limit = 200,
        string $q = '',
    ): array {
        $result = $this->invoke('channels.getParticipants', [
            'channel' => $peer,
            'filter' => $this->participantsFilter($filter, $q),
            'offset' => $offset,
            'limit' => $limit,
            'hash' => 0,
        ]);

        return is_array($result) ? $result : [];
    }

    /**
     * Lazily yield every participant, paging by offset.
     *
     * @return Generator<int, array>
     */
    public function iterateParticipants(
        string|int|array $peer,
        string $filter = 'recent',
        string $q = '',
        int $pageSize = 200,
    ): Generator {
        $offset = 0;

        do {
            $page = $this->getParticipants($peer, $filter, $offset, $pageSize, $q);
            $batch = $page['participants'] ?? [];
            $count = (int) ($page['count'] ?? 0);

            foreach ($batch as $participant) {
                yield $participant;
            }

            $offset += count($batch);
        } while ($batch !== [] && $offset < $count);
    }

    /**
     * @return Generator<int, array>
     */
    public function iterateHistory(
        string|int|array $peer,
        ?int $limit = null,
        int $pageSize = 100,
    ): Generator {
        $offsetId = 0;
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $result = $this->invoke('messages.getHistory', [
                'peer' => $peer,
                'offset_id' => $offsetId,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => $take,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
            ]);

            $messages = $result['messages'] ?? [];

            foreach ($messages as $message) {
                yield $message;
                $offsetId = $message['id'] ?? $offsetId;
                if (++$yielded === $limit) {
                    return;
                }
            }
        } while ($messages !== []);
    }

    /**
     * @return Generator<int, array>
     */
    public function iterateDialogs(?int $limit = null, int $pageSize = 100): Generator
    {
        $offsetDate = 0;
        $offsetId = 0;
        $offsetPeer = ['_' => 'inputPeerEmpty'];
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $result = $this->invoke('messages.getDialogs', [
                'offset_date' => $offsetDate,
                'offset_id' => $offsetId,
                'offset_peer' => $offsetPeer,
                'limit' => $take,
                'hash' => 0,
            ]);

            $dialogs = $result['dialogs'] ?? [];

            foreach ($dialogs as $dialog) {
                yield $dialog;
                if (++$yielded === $limit) {
                    return;
                }
            }

            if (($result['_'] ?? '') !== 'messages.dialogsSlice') {
                return;
            }

            $next = $this->dialogsNextOffset($result);
            if ($next === null) {
                return;
            }
            [$offsetDate, $offsetId, $offsetPeer] = $next;
        } while ($dialogs !== []);
    }

    /**
     * @return array{0:int,1:int,2:array}|null
     */
    private function dialogsNextOffset(array $result): ?array
    {
        $dialogs = $result['dialogs'] ?? [];
        if ($dialogs === []) {
            return null;
        }

        $last = $dialogs[array_key_last($dialogs)];
        $topId = (int) ($last['top_message'] ?? 0);

        $date = 0;
        foreach ($result['messages'] ?? [] as $message) {
            if (($message['id'] ?? null) === $topId) {
                $date = (int) ($message['date'] ?? 0);
                break;
            }
        }

        $peer = $last['peer'] ?? null;
        $id = is_array($peer) ? ($peer['user_id'] ?? $peer['channel_id'] ?? $peer['chat_id'] ?? null) : null;

        $inputPeer = ['_' => 'inputPeerEmpty'];
        if ($id !== null) {
            $inputPeer = $this->getResolver()?->resolveInputPeer((int) $id) ?? $inputPeer;
        }

        return [$date, $topId, $inputPeer];
    }

    /**
     * Build a ChannelParticipantsFilter TL object from a friendly name.
     */
    private function participantsFilter(string $filter, string $q): array
    {
        return match (strtolower($filter)) {
            'recent' => ['_' => 'channelParticipantsRecent'],
            'admins' => ['_' => 'channelParticipantsAdmins'],
            'bots' => ['_' => 'channelParticipantsBots'],
            'kicked' => ['_' => 'channelParticipantsKicked', 'q' => $q],
            'banned' => ['_' => 'channelParticipantsBanned', 'q' => $q],
            'search' => ['_' => 'channelParticipantsSearch', 'q' => $q],
            'contacts' => ['_' => 'channelParticipantsContacts', 'q' => $q],
            'mentions' => $q === ''
                ? ['_' => 'channelParticipantsMentions']
                : ['_' => 'channelParticipantsMentions', 'q' => $q],
            default => throw new MTProtoException("Unknown participants filter: {$filter}"),
        };
    }
}
