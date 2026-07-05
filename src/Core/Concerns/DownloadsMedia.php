<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core\Concerns;

use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Foundation\FileDecoder;
use LaraGram\MTProto\TL\TLObject;

/**
 * {@see FileDecoder}
 *
 * @mixin \LaraGram\MTProto\Core\Client
 */
trait DownloadsMedia
{
    private ?FileDecoder $fileDecoderInstance = null;

    /**
     * Shared lazy FileDecoder bound to this client.
     */
    public function fileDecoder(): FileDecoder
    {
        return $this->fileDecoderInstance ??= new FileDecoder($this);
    }

    /**
     * Download media and return the raw bytes.
     *
     * @param array|TLObject $media
     */
    public function downloadMedia(array|TLObject $media, ?string $thumbSize = null): string
    {
        return $this->fileDecoder()->downloadMedia($media, $thumbSize);
    }

    /**
     * Download media straight to disk (streamed). Returns bytes written.
     *
     * @param array|TLObject $media
     */
    public function downloadMediaToFile(array|TLObject $media, string $path, ?string $thumbSize = null): int
    {
        return $this->fileDecoder()->downloadMediaToFile($media, $path, $thumbSize);
    }

    /**
     * Resolve size/DC/mime/name/type for media without downloading.
     *
     * @param array|TLObject $media
     */
    public function getFileInfo(array|TLObject $media, ?string $thumbSize = null): ?array
    {
        return $this->fileDecoder()->getFileInfo($media, $thumbSize);
    }

    /**
     * Low-level: download an explicit InputFileLocation into memory.
     */
    public function downloadToMemory(array $location, int $dcId, int $size = 0): string
    {
        return $this->fileDecoder()->downloadToMemory($location, $dcId, $size);
    }

    /**
     * Low-level: stream an explicit InputFileLocation to disk. Returns bytes written.
     */
    public function downloadToFile(array $location, int $dcId, string $path, int $size = 0): int
    {
        return $this->fileDecoder()->downloadToFile($location, $dcId, $path, $size);
    }

    /**
     * Download a story's media. Pass a story id (fetched via {@see getStories()})
     * or a prebuilt `storyItem` array. With `$path` it streams to disk and
     * returns bytes written; otherwise it returns the raw bytes.
     *
     * @param string|int|array $peerOrStory a peer reference, or a storyItem array
     */
    public function downloadStory(string|int|array $peerOrStory, ?int $id = null, ?string $path = null): string|int
    {
        if (is_array($peerOrStory) && ($peerOrStory['_'] ?? '') === 'storyItem') {
            $story = $peerOrStory;
        } else {
            if ($id === null) {
                throw new MTProtoException('downloadStory() needs a story id when given a peer.');
            }
            $result = $this->getStories($peerOrStory, [$id]);
            $story = $result['stories'][0] ?? null;
            if (!is_array($story)) {
                throw new MTProtoException("Story {$id} not found or inaccessible.");
            }
        }

        return $path === null
            ? $this->downloadMedia($story)
            : $this->downloadMediaToFile($story, $path);
    }
}
