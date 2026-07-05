<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Foundation\InputMedia;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesStories
{
    public function sendStory(string|int|array $peer, string|array $media, array $params = []): mixed
    {
        $input = is_array($media) ? $media : InputMedia::uploadedPhoto($this->uploadFile($media));

        $call = [
            'peer' => $peer,
            'media' => $input,
            'privacy_rules' => $params['privacy_rules'] ?? [['_' => 'inputPrivacyValueAllowAll']],
        ];
        foreach (['caption', 'entities', 'period', 'pinned', 'noforwards', 'media_areas', 'albums'] as $k) {
            if (isset($params[$k])) {
                $call[$k] = $params[$k];
            }
        }

        return $this->invoke('stories.sendStory', $call);
    }

    public function editStory(string|int|array $peer, int $id, array $params = []): mixed
    {
        return $this->invoke('stories.editStory', array_merge(['peer' => $peer, 'id' => $id], $params));
    }

    public function deleteStories(string|int|array $peer, int|array $ids): mixed
    {
        return $this->invoke('stories.deleteStories', [
            'peer' => $peer,
            'id' => is_array($ids) ? $ids : [$ids],
        ]);
    }

    /**
     * @param int|list<int> $ids
     */
    public function pinStory(string|int|array $peer, int|array $ids, bool $pinned = true): mixed
    {
        return $this->invoke('stories.togglePinned', [
            'peer' => $peer,
            'id' => is_array($ids) ? $ids : [$ids],
            'pinned' => $pinned,
        ]);
    }

    /**
     * @param int|list<int> $ids
     */
    public function getStories(string|int|array $peer, int|array $ids): mixed
    {
        return $this->invoke('stories.getStoriesByID', [
            'peer' => $peer,
            'id' => is_array($ids) ? $ids : [$ids],
        ]);
    }

    public function getPeerStories(string|int|array $peer): mixed
    {
        return $this->invoke('stories.getPeerStories', ['peer' => $peer]);
    }

    public function readStories(string|int|array $peer, int $maxId): mixed
    {
        return $this->invoke('stories.readStories', ['peer' => $peer, 'max_id' => $maxId]);
    }
}
