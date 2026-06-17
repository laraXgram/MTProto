<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\TL\TLObject;

/**
 * Decodes and downloads files from Telegram MTProto media.
 *
 * Supports:
 *  - Photos (all sizes)
 *  - Documents (stickers, GIFs, audio, video, files)
 *  - Profile photos
 *
 * Usage:
 *   $decoder = new FileDecoder($client);
 *
 *   // Download from update data
 *   $bytes = $decoder->downloadMedia($request->message->media->toArray());
 *   $decoder->downloadMediaToFile($request->message->media->toArray(), '/path/to/file.jpg');
 *
 *   // Download from raw location
 *   $bytes = $decoder->downloadToMemory($location, $dcId, $size);
 *   $decoder->downloadToFile($location, $dcId, '/path/to/file', $size);
 */
class FileDecoder
{
    /**
     * Default chunk size for downloads (512 KB).
     * Must be divisible by 4096 and <= 1MB (1048576).
     */
    protected const CHUNK_SIZE = 524288; // 512 * 1024

    /**
     * Maximum chunk size (1 MB, Telegram's limit).
     */
    protected const MAX_CHUNK_SIZE = 1048576; // 1024 * 1024

    protected Client $client;
    protected \LaraGram\Filesystem\Filesystem $files;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->files  = $client->getFiles();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  High-level: Download from media object
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Download media from an update and return the bytes.
     *
     * @param array|TLObject $media The media object from the message
     * @param string|null $thumbSize For photos, the size type (e.g. 'x', 'y', 'w'). Defaults to largest.
     * @return string The file bytes
     * @throws MTProtoException
     */
    public function downloadMedia(array|TLObject $media, ?string $thumbSize = null): string
    {
        $data = $media instanceof TLObject ? $media->toArray() : $media;

        $info = $this->resolveMediaLocation($data, $thumbSize);
        if ($info === null) {
            throw new MTProtoException('Cannot resolve file location from media');
        }

        return $this->downloadToMemory($info['location'], $info['dc_id'], $info['size']);
    }

    /**
     * Download media from an update and save to a file.
     *
     * @param array|TLObject $media The media object from the message
     * @param string $path Destination file path
     * @param string|null $thumbSize For photos, the size type (e.g. 'x', 'y', 'w'). Defaults to largest.
     * @return int Bytes written
     * @throws MTProtoException
     */
    public function downloadMediaToFile(array|TLObject $media, string $path, ?string $thumbSize = null): int
    {
        $data = $media instanceof TLObject ? $media->toArray() : $media;

        $info = $this->resolveMediaLocation($data, $thumbSize);
        if ($info === null) {
            throw new MTProtoException('Cannot resolve file location from media');
        }

        return $this->downloadToFile($info['location'], $info['dc_id'], $path, $info['size']);
    }

    /**
     * Get file info from media without downloading.
     *
     * Returns:
     *  - location: InputFileLocation array for upload.getFile
     *  - dc_id: DC where the file is stored
     *  - size: File size in bytes (0 if unknown)
     *  - mime_type: MIME type (if available)
     *  - file_name: Original file name (if available)
     *  - media_type: Type string (photo, document, sticker, video, etc.)
     *
     * @param array|TLObject $media The media object
     * @param string|null $thumbSize For photos, the size type
     * @return array|null File info array or null if cannot resolve
     */
    public function getFileInfo(array|TLObject $media, ?string $thumbSize = null): ?array
    {
        $data = $media instanceof TLObject ? $media->toArray() : $media;

        $info = $this->resolveMediaLocation($data, $thumbSize);
        if ($info === null) {
            return null;
        }

        // Add extra metadata
        $info['mime_type'] = $this->extractMimeType($data);
        $info['file_name'] = $this->extractFileName($data);
        $info['media_type'] = $this->detectMediaType($data);

        return $info;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Low-level: Download by InputFileLocation
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Download a file into memory by its InputFileLocation.
     *
     * @param array $location InputFileLocation array
     * @param int $dcId DC where the file resides
     * @param int $size Expected file size (0 = unknown, will read until empty)
     * @return string The raw file bytes
     * @throws MTProtoException
     */
    public function downloadToMemory(array $location, int $dcId, int $size = 0): string
    {
        $chunks = [];
        $offset = 0;
        $chunkSize = self::CHUNK_SIZE;

        while (true) {
            $result = $this->getFileChunk($location, $dcId, $offset, $chunkSize);

            $bytes = $result['bytes'] ?? '';

            if ($bytes === '') {
                break;
            }

            $chunks[] = $bytes;
            $offset += strlen($bytes);

            // If we know the size and have downloaded enough, stop
            if ($size > 0 && $offset >= $size) {
                break;
            }

            // If we got less than requested, we're done
            if (strlen($bytes) < $chunkSize) {
                break;
            }
        }

        $content = implode('', $chunks);

        // Trim to exact size if known
        if ($size > 0 && strlen($content) > $size) {
            $content = substr($content, 0, $size);
        }

        return $content;
    }

    /**
     * Download a file to disk by its InputFileLocation.
     *
     * Streams chunks directly to file to avoid memory issues with large files.
     *
     * @param array $location InputFileLocation array
     * @param int $dcId DC where the file resides
     * @param string $path Destination file path
     * @param int $size Expected file size (0 = unknown)
     * @return int Total bytes written
     * @throws MTProtoException
     */
    public function downloadToFile(array $location, int $dcId, string $path, int $size = 0): int
    {
        // Ensure directory exists; keep handle-based streaming for the body
        // (large media — avoids re-opening the file per 1 MB chunk).
        $this->files->ensureDirectoryExists(dirname($path), 0755);

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new MTProtoException("Cannot open file for writing: {$path}");
        }

        try {
            $offset = 0;
            $chunkSize = self::CHUNK_SIZE;
            $totalWritten = 0;

            while (true) {
                $result = $this->getFileChunk($location, $dcId, $offset, $chunkSize);

                $bytes = $result['bytes'] ?? '';

                if ($bytes === '') {
                    break;
                }

                // Trim last chunk if we know the exact size
                $len = strlen($bytes);
                if ($size > 0 && ($offset + $len) > $size) {
                    $bytes = substr($bytes, 0, $size - $offset);
                    $len = strlen($bytes);
                }

                $written = fwrite($handle, $bytes);
                if ($written === false) {
                    throw new MTProtoException("Failed to write to file: {$path}");
                }

                $totalWritten += $written;
                $offset += $len;

                // If we know the size and have written enough, stop
                if ($size > 0 && $offset >= $size) {
                    break;
                }

                // If we got less than requested, we're done
                if (strlen($result['bytes'] ?? '') < $chunkSize) {
                    break;
                }
            }

            return $totalWritten;
        } finally {
            fclose($handle);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Media → InputFileLocation Resolution
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Resolve an InputFileLocation from a media object.
     *
     * @param array $media The raw media array
     * @param string|null $thumbSize Photo size type override
     * @return array{location: array, dc_id: int, size: int}|null
     */
    protected function resolveMediaLocation(array $media, ?string $thumbSize = null): ?array
    {
        $constructor = $media['_'] ?? '';

        return match ($constructor) {
            'messageMediaPhoto'    => $this->resolvePhotoLocation($media, $thumbSize),
            'messageMediaDocument' => $this->resolveDocumentLocation($media),
            'photo'                => $this->resolvePhotoDirectLocation($media, $thumbSize),
            'document'             => $this->resolveDocumentDirectLocation($media),
            default                => null,
        };
    }

    /**
     * Resolve location for messageMediaPhoto.
     */
    protected function resolvePhotoLocation(array $media, ?string $thumbSize): ?array
    {
        $photo = $media['photo'] ?? null;
        if (!is_array($photo) || ($photo['_'] ?? '') === 'photoEmpty') {
            return null;
        }

        return $this->resolvePhotoDirectLocation($photo, $thumbSize);
    }

    /**
     * Resolve location for a raw photo object.
     */
    protected function resolvePhotoDirectLocation(array $photo, ?string $thumbSize): ?array
    {
        $sizes = $photo['sizes'] ?? [];
        if (empty($sizes)) {
            return null;
        }

        // Find requested size or the largest one
        $targetSize = null;
        $targetSizeBytes = 0;

        if ($thumbSize !== null) {
            // Find specific size type
            foreach ($sizes as $size) {
                if (($size['type'] ?? '') === $thumbSize) {
                    $targetSize = $size;
                    $targetSizeBytes = $size['size'] ?? 0;
                    break;
                }
            }
        }

        if ($targetSize === null) {
            // Pick the largest by size, preferring 'y' > 'x' > 'w' > etc.
            $priority = ['y' => 6, 'x' => 5, 'w' => 4, 'm' => 3, 's' => 2, 'a' => 1];
            $bestPriority = -1;

            foreach ($sizes as $size) {
                $sizeConstructor = $size['_'] ?? '';
                // Skip stripped/path sizes (no downloadable bytes)
                if ($sizeConstructor === 'photoStrippedSize' || $sizeConstructor === 'photoPathSize') {
                    continue;
                }

                $type = $size['type'] ?? '';
                $p = $priority[$type] ?? 0;
                if ($p > $bestPriority) {
                    $bestPriority = $p;
                    $targetSize = $size;
                    $targetSizeBytes = $size['size'] ?? 0;
                }
            }
        }

        if ($targetSize === null) {
            // Fallback: just use last non-stripped size
            foreach (array_reverse($sizes) as $size) {
                $sizeConstructor = $size['_'] ?? '';
                if ($sizeConstructor !== 'photoStrippedSize' && $sizeConstructor !== 'photoPathSize') {
                    $targetSize = $size;
                    $targetSizeBytes = $size['size'] ?? 0;
                    break;
                }
            }
        }

        if ($targetSize === null) {
            return null;
        }

        // photoCachedSize has bytes directly embedded
        if (($targetSize['_'] ?? '') === 'photoCachedSize') {
            // We can return the bytes directly — but caller expects a location.
            // Fall through and build the location anyway (the server will serve it).
        }

        return [
            'location' => [
                '_' => 'inputPhotoFileLocation',
                'id' => $photo['id'],
                'access_hash' => $photo['access_hash'],
                'file_reference' => $photo['file_reference'],
                'thumb_size' => $targetSize['type'] ?? '',
            ],
            'dc_id' => $photo['dc_id'] ?? $this->client->getDcId(),
            'size' => $targetSizeBytes,
        ];
    }

    /**
     * Resolve location for messageMediaDocument.
     */
    protected function resolveDocumentLocation(array $media): ?array
    {
        $doc = $media['document'] ?? null;
        if (!is_array($doc) || ($doc['_'] ?? '') === 'documentEmpty') {
            return null;
        }

        return $this->resolveDocumentDirectLocation($doc);
    }

    /**
     * Resolve location for a raw document object.
     */
    protected function resolveDocumentDirectLocation(array $doc): ?array
    {
        return [
            'location' => [
                '_' => 'inputDocumentFileLocation',
                'id' => $doc['id'],
                'access_hash' => $doc['access_hash'],
                'file_reference' => $doc['file_reference'],
                'thumb_size' => '',
            ],
            'dc_id' => $doc['dc_id'] ?? $this->client->getDcId(),
            'size' => $doc['size'] ?? 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  RPC Call
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Call upload.getFile for a single chunk.
     *
     * @param array $location InputFileLocation
     * @param int $dcId Target DC
     * @param int $offset Byte offset
     * @param int $limit Chunk size
     * @return array{_: string, type: array, mtime: int, bytes: string}
     * @throws MTProtoException
     */
    protected function getFileChunk(array $location, int $dcId, int $offset, int $limit): array
    {
        // If the file is on a different DC, we need to handle FILE_MIGRATE
        // The invoke() method already handles this via the DC migration logic
        $result = $this->client->invoke('upload.getFile', [
            'precise' => true,
            'location' => $location,
            'offset' => $offset,
            'limit' => $limit,
        ]);

        // upload.getFile returns upload.file or upload.fileCdnRedirect
        if (is_array($result) && ($result['_'] ?? '') === 'upload.fileCdnRedirect') {
            throw new MTProtoException('CDN file redirects are not yet supported. Use cdn_supported=false.');
        }

        return is_array($result) ? $result : ['bytes' => ''];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Metadata Extraction
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Extract MIME type from media.
     */
    protected function extractMimeType(array $media): ?string
    {
        $constructor = $media['_'] ?? '';

        if ($constructor === 'messageMediaPhoto' || $constructor === 'photo') {
            return 'image/jpeg';
        }

        $doc = match ($constructor) {
            'messageMediaDocument' => $media['document'] ?? null,
            'document'             => $media,
            default                => null,
        };

        return $doc['mime_type'] ?? null;
    }

    /**
     * Extract original file name from media.
     */
    protected function extractFileName(array $media): ?string
    {
        $doc = match ($media['_'] ?? '') {
            'messageMediaDocument' => $media['document'] ?? null,
            'document'             => $media,
            default                => null,
        };

        if (!is_array($doc) || !isset($doc['attributes'])) {
            return null;
        }

        foreach ($doc['attributes'] as $attr) {
            if (($attr['_'] ?? '') === 'documentAttributeFilename') {
                return $attr['file_name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Detect the media type (photo, sticker, video, etc.).
     */
    protected function detectMediaType(array $media): string
    {
        $constructor = $media['_'] ?? '';

        if ($constructor === 'messageMediaPhoto' || $constructor === 'photo') {
            return 'photo';
        }

        if ($constructor !== 'messageMediaDocument' && $constructor !== 'document') {
            return 'unknown';
        }

        $doc = match ($constructor) {
            'messageMediaDocument' => $media['document'] ?? $media,
            default => $media,
        };

        if (!isset($doc['attributes']) || !is_array($doc['attributes'])) {
            return 'document';
        }

        return ClientType::mediaTypeFromMessage(['media' => $media]) ?? 'document';
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Extension Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Guess file extension from MIME type.
     */
    public static function extensionFromMime(?string $mimeType): string
    {
        if ($mimeType === null) {
            return '';
        }

        return match ($mimeType) {
            'image/jpeg'       => '.jpg',
            'image/png'        => '.png',
            'image/gif'        => '.gif',
            'image/webp'       => '.webp',
            'image/bmp'        => '.bmp',
            'video/mp4'        => '.mp4',
            'video/quicktime'  => '.mov',
            'audio/mpeg'       => '.mp3',
            'audio/ogg'        => '.ogg',
            'audio/mp4'        => '.m4a',
            'audio/aac'        => '.aac',
            'audio/flac'       => '.flac',
            'application/x-tgsticker' => '.tgs',
            'image/x-tgsticker' => '.tgs',
            'application/pdf'  => '.pdf',
            'application/zip'  => '.zip',
            'application/x-rar-compressed' => '.rar',
            'text/plain'       => '.txt',
            'text/html'        => '.html',
            default            => '',
        };
    }

    /**
     * Format file size for human display.
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
