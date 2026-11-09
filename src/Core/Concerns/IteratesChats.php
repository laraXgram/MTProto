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
        $input = $this->inputPeerFor($peer);
        if (($input['_'] ?? '') === 'inputPeerChat') {
            return $this->basicGroupParticipants($input, $filter, $offset, $limit);
        }

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
        return $this->pageMessages(fn (int $offsetId, int $take) => $this->invoke('messages.getHistory', [
            'peer' => $peer,
            'offset_id' => $offsetId,
            'offset_date' => 0,
            'add_offset' => 0,
            'limit' => $take,
            'max_id' => 0,
            'min_id' => 0,
            'hash' => 0,
        ]), $limit, $pageSize);
    }

    /**
     * Lazily yield every message matching a search. `$params` takes the same
     * options as {@see searchMessages()}.
     *
     * @return Generator<int, array>
     */
    public function iterateSearch(
        string|int|array $peer,
        string $query = '',
        array $params = [],
        ?int $limit = null,
        int $pageSize = 100,
    ): Generator {
        return $this->pageMessages(
            fn (int $offsetId, int $take) => $this->searchMessages($peer, $query, array_merge($params, ['offset_id' => $offsetId, 'limit' => $take])),
            $limit,
            $pageSize,
        );
    }

    /**
     * Lazily yield the replies to a message: its thread or channel-post comments.
     *
     * @return Generator<int, array>
     */
    public function iterateReplies(string|int|array $peer, int $msgId, ?int $limit = null, int $pageSize = 100): Generator
    {
        return $this->pageMessages(fn (int $offsetId, int $take) => $this->invoke('messages.getReplies', [
            'peer' => $peer,
            'msg_id' => $msgId,
            'offset_id' => $offsetId,
            'offset_date' => 0,
            'add_offset' => 0,
            'limit' => $take,
            'max_id' => 0,
            'min_id' => 0,
            'hash' => 0,
        ]), $limit, $pageSize);
    }

    /**
     * Lazily yield the pending join requests of a chat.
     *
     * @return Generator<int, array> `chatInviteImporter` objects
     */
    public function iterateJoinRequests(string|int|array $peer, ?int $limit = null, int $pageSize = 100, string $query = ''): Generator
    {
        $offsetDate = 0;
        $offsetUser = ['_' => 'inputUserEmpty'];
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $page = $this->invoke('messages.getChatInviteImporters', array_merge([
                'requested' => true,
                'peer' => $peer,
                'offset_date' => $offsetDate,
                'offset_user' => $offsetUser,
                'limit' => $take,
            ], $query !== '' ? ['q' => $query] : []));
            $importers = $page['importers'] ?? [];

            foreach ($importers as $importer) {
                yield $importer;
                $offsetDate = (int) $importer['date'];
                $offsetUser = (int) $importer['user_id'];
                if (++$yielded === $limit) {
                    return;
                }
            }
        } while ($importers !== []);
    }

    /**
     * Lazily yield the chats you share with a user.
     *
     * @return Generator<int, array>
     */
    public function iterateCommonChats(string|int|array $user, ?int $limit = null, int $pageSize = 100): Generator
    {
        $maxId = 0;
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $page = $this->invoke('messages.getCommonChats', ['user_id' => $user, 'max_id' => $maxId, 'limit' => $take]);
            $chats = $page['chats'] ?? [];

            foreach ($chats as $chat) {
                yield $chat;
                $maxId = (int) $chat['id'];
                if (++$yielded === $limit) {
                    return;
                }
            }
        } while ($chats !== [] && ($page['_'] ?? '') === 'messages.chatsSlice');
    }

    /**
     * Lazily yield a supergroup or channel's admin log, newest first.
     * `$params` takes `events_filter` and `admins`.
     *
     * @return Generator<int, array>
     */
    public function iterateAdminLog(
        string|int|array $peer,
        string $query = '',
        array $params = [],
        ?int $limit = null,
        int $pageSize = 100,
    ): Generator {
        $maxId = 0;
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $page = $this->invoke('channels.getAdminLog', array_merge($params, [
                'channel' => $peer,
                'q' => $query,
                'max_id' => $maxId,
                'min_id' => 0,
                'limit' => $take,
            ]));
            $events = $page['events'] ?? [];

            foreach ($events as $event) {
                yield $event;
                $maxId = (int) $event['id'];
                if (++$yielded === $limit) {
                    return;
                }
            }
        } while ($events !== []);
    }

    /**
     * Lazily yield the users and chats this account has blocked.
     *
     * @return Generator<int, array> `peerBlocked` objects
     */
    public function iterateBlockedUsers(?int $limit = null, int $pageSize = 100): Generator
    {
        $offset = 0;
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $page = $this->invoke('contacts.getBlocked', ['offset' => $offset, 'limit' => $take]);
            $blocked = $page['blocked'] ?? [];

            foreach ($blocked as $entry) {
                yield $entry;
                if (++$yielded === $limit) {
                    return;
                }
            }

            $offset += count($blocked);
        } while ($blocked !== [] && ($page['_'] ?? '') === 'contacts.blockedSlice');
    }

    /**
     * Yield messages from a fetcher paged by `offset_id`, newest first.
     *
     * @param callable(int, int): mixed $fetch receives the offset id and page size
     * @return Generator<int, array>
     */
    private function pageMessages(callable $fetch, ?int $limit, int $pageSize): Generator
    {
        $offsetId = 0;
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $messages = $fetch($offsetId, $take)['messages'] ?? [];

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
     * Participants of a basic group, shaped like `channels.channelParticipants`.
     */
    private function basicGroupParticipants(array $input, string $filter, int $offset, int $limit): array
    {
        $full = $this->invoke('messages.getFullChat', ['chat_id' => $input['chat_id']]);
        $members = $full['full_chat']['participants']['participants'] ?? [];

        $members = match (strtolower($filter)) {
            'recent' => $members,
            'admins' => array_values(array_filter($members, static fn (array $member): bool => ($member['_'] ?? '') !== 'chatParticipant')),
            default => throw new MTProtoException("Basic groups only support the recent and admins participant filters, not {$filter}."),
        };

        return [
            '_' => 'channels.channelParticipants',
            'count' => count($members),
            'participants' => array_slice($members, $offset, $limit),
            'chats' => $full['chats'] ?? [],
            'users' => $full['users'] ?? [],
        ];
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

        $peer = $last['peer'] ?? null;

        // Message ids repeat across channels, so the peer has to match too.
        $date = 0;
        foreach ($result['messages'] ?? [] as $message) {
            if (($message['id'] ?? null) === $topId && ($message['peer_id'] ?? null) == $peer) {
                $date = (int) ($message['date'] ?? 0);
                break;
            }
        }

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
