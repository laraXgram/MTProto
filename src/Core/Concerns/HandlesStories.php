<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Foundation\InputMedia;

/**
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait HandlesStories
{
    /**
     * Post a story from a local photo or video, or a prebuilt InputMedia.
     * Recognised $params for a video path: duration, w, h.
     */
    public function sendStory(string|int|array $peer, string|array $media, array $params = []): mixed
    {
        $input = is_array($media) ? $media : $this->storyMedia($media, $params);

        $call = [
            'peer' => $peer,
            'media' => $input,
            'privacy_rules' => $params['privacy_rules'] ?? [['_' => 'inputPrivacyValueAllowAll']],
        ];
        foreach (['caption', 'entities', 'parse_mode', 'period', 'pinned', 'noforwards', 'media_areas', 'albums', 'fwd_modified', 'fwd_from_id', 'fwd_from_story', 'random_id'] as $k) {
            if (isset($params[$k])) {
                $call[$k] = $params[$k];
            }
        }

        return $this->invoke('stories.sendStory', $call);
    }

    private function storyMedia(string $path, array $params): array
    {
        $mime = $this->guessMimeType($path);

        if (!str_starts_with($mime, 'video/')) {
            return InputMedia::uploadedPhoto($this->uploadFile($path));
        }

        return InputMedia::uploadedDocument($this->uploadFile($path), $mime, [
            InputMedia::attrVideo(
                (int) ($params['duration'] ?? 0),
                (int) ($params['w'] ?? 0),
                (int) ($params['h'] ?? 0),
                supportsStreaming: true,
            ),
        ]);
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
