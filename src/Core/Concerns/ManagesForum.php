<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait ManagesForum
{
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
        return $this->invoke('messages.deleteTopicHistory', ['peer' => $peer, 'top_msg_id' => $topicId]);
    }

    public function toggleForum(string|int|array $peer, bool $enabled = true, bool $tabs = false): mixed
    {
        return $this->invoke('channels.toggleForum', ['channel' => $peer, 'enabled' => $enabled, 'tabs' => $tabs]);
    }
}
