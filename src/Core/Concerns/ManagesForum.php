<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use Generator;
use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait ManagesForum
{
    /** The General topic has no root message to reply to. */
    private const GENERAL_FORUM_TOPIC = 1;

    /**
     * Send a message into a forum topic. Extra `messages.sendMessage` params
     * (parse_mode, reply_markup, ...) pass through $params.
     */
    public function sendTopicMessage(string|int|array $peer, int $topicId, string $message, array $params = []): mixed
    {
        $call = ['peer' => $peer, 'message' => $message];

        if ($topicId !== self::GENERAL_FORUM_TOPIC) {
            $call['reply_to'] = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $topicId, 'top_msg_id' => $topicId];
        }

        return $this->invoke('messages.sendMessage', array_merge($call, $params));
    }

    public function createForumTopic(string|int|array $peer, string $title, array $params = []): mixed
    {
        return $this->invoke('messages.createForumTopic', array_merge([
            'peer' => $peer,
            'title' => $title,
        ], $params));
    }

    public function editForumTopic(string|int|array $peer, int $topicId, array $params = []): mixed
    {
        return $this->invoke('messages.editForumTopic', array_merge([
            'peer' => $peer,
            'topic_id' => $topicId,
        ], $params));
    }

    public function closeForumTopic(string|int|array $peer, int $topicId, bool $closed = true): mixed
    {
        return $this->editForumTopic($peer, $topicId, ['closed' => $closed]);
    }

    public function hideForumTopic(string|int|array $peer, int $topicId, bool $hidden = true): mixed
    {
        return $this->editForumTopic($peer, $topicId, ['hidden' => $hidden]);
    }

    public function getForumTopics(string|int|array $peer, array $params = []): mixed
    {
        return $this->invoke('messages.getForumTopics', array_merge([
            'peer' => $peer,
            'offset_date' => 0,
            'offset_id' => 0,
            'offset_topic' => 0,
            'limit' => 100,
        ], $params));
    }

    /**
     * Lazily yield every forum topic, optionally only those matching `$query`.
     *
     * @return Generator<int, array>
     */
    public function iterateForumTopics(string|int|array $peer, ?int $limit = null, int $pageSize = 100, string $query = ''): Generator
    {
        $cursor = ['offset_date' => 0, 'offset_id' => 0, 'offset_topic' => 0];
        $yielded = 0;

        do {
            $take = $limit !== null ? min($pageSize, $limit - $yielded) : $pageSize;
            if ($take <= 0) {
                return;
            }

            $page = $this->getForumTopics($peer, array_merge(
                $cursor,
                ['limit' => $take],
                $query !== '' ? ['q' => $query] : [],
            ));
            $topics = $page['topics'] ?? [];

            foreach ($topics as $topic) {
                yield $topic;
                if (++$yielded === $limit) {
                    return;
                }
            }

            $cursor = $this->forumTopicsCursor($page);
        } while ($cursor !== null && $yielded < (int) ($page['count'] ?? 0));
    }

    /**
     * @return array{offset_date: int, offset_id: int, offset_topic: int}|null
     */
    private function forumTopicsCursor(array $page): ?array
    {
        $topics = array_filter($page['topics'] ?? [], static fn (array $topic): bool => isset($topic['top_message']));
        if ($topics === []) {
            return null;
        }

        $last = $topics[array_key_last($topics)];
        $date = (int) ($last['date'] ?? 0);

        if (empty($page['order_by_create_date'])) {
            foreach ($page['messages'] ?? [] as $message) {
                if (($message['id'] ?? null) === $last['top_message']) {
                    $date = (int) ($message['date'] ?? 0);
                    break;
                }
            }
        }

        return ['offset_date' => $date, 'offset_id' => (int) $last['top_message'], 'offset_topic' => (int) $last['id']];
    }

    /**
     * @param int|list<int> $topicIds
     */
    public function getForumTopicsById(string|int|array $peer, int|array $topicIds): mixed
    {
        return $this->invoke('messages.getForumTopicsByID', [
            'peer' => $peer,
            'topics' => is_array($topicIds) ? $topicIds : [$topicIds],
        ]);
    }

    public function pinForumTopic(string|int|array $peer, int $topicId, bool $pinned = true): mixed
    {
        return $this->invoke('messages.updatePinnedForumTopic', [
            'peer' => $peer,
            'topic_id' => $topicId,
            'pinned' => $pinned,
        ]);
    }

    /**
     * @param list<int> $order
     */
    public function reorderForumTopics(string|int|array $peer, array $order, bool $force = false): mixed
    {
        $call = ['peer' => $peer, 'order' => array_values($order)];
        if ($force) {
            $call['force'] = true;
        }

        return $this->invoke('messages.reorderPinnedForumTopics', $call);
    }

    public function deleteForumTopic(string|int|array $peer, int $topicId): mixed
    {
        return $this->drainAffectedHistory(
            fn () => $this->invoke('messages.deleteTopicHistory', ['peer' => $peer, 'top_msg_id' => $topicId]),
        );
    }

    /**
     * Turn topics on or off. Topics show as a list by default; `$tabs` and
     * `$sidebar` pick another layout and cannot be combined.
     */
    public function toggleForum(string|int|array $peer, bool $enabled = true, bool $tabs = false, bool $sidebar = false): mixed
    {
        if ($tabs && $sidebar) {
            throw new MTProtoException('A forum cannot show its topics as both tabs and a sidebar.');
        }

        return $this->invoke('channels.toggleForum', [
            'channel' => $peer,
            'enabled' => $enabled,
            'tabs' => $tabs,
            'sidebar' => $sidebar,
        ]);
    }
}
