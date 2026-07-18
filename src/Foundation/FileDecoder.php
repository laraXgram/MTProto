<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\TL\TLObject;

class FileDecoder
{
    /**
     * Default chunk size for downloads (1 MB - Telegram's maximum).
     * Must be divisible by 4096, <= 1MB (1048576), and offsets stay
     * chunk-aligned so a request never crosses a 1MB file boundary
     * (required with the `precise` flag).
     */
    protected const CHUNK_SIZE = 1048576; // 1024 * 1024

    /**
     * Maximum chunk size (1 MB, Telegram's limit).
     */
    protected const MAX_CHUNK_SIZE = 1048576; // 1024 * 1024

    /** Default number of concurrent chunk fetches on the parallel path. */
    public const DEFAULT_CONCURRENCY = 4;

    protected Client $client;
    protected \LaraGram\Filesystem\Filesystem $files;

    /**
     * Max concurrent chunk fetches. 1 = serial (default, preserves the legacy
     * behaviour). Raise via {@see withConcurrency()} to parallelise downloads.
     */
    private int $concurrency = 1;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->files = $client->getFiles();
    }

    /**
     * Set how many chunks are fetched concurrently. The parallel path only
     * engages when the size is known, the runtime is coroutine-capable, and the
     * target DC connection supports concurrent invokes (pump running); otherwise
     * it transparently falls back to the serial loop.
     */
    public function withConcurrency(int $n): self
    {
        $this->concurrency = max(1, $n);

        return $this;
    }

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
        if ($this->canParallelize($dcId, $size)) {
            $chunks = [];
            $this->downloadChunksInOrder($location, $dcId, $size, static function (int $index, string $bytes) use (&$chunks): void {
                $chunks[$index] = $bytes;
            });
            ksort($chunks);

            $content = implode('', $chunks);

            return ($size > 0 && strlen($content) > $size) ? substr($content, 0, $size) : $content;
        }

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
        $this->files->ensureDirectoryExists(dirname($path), 0755);

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new MTProtoException("Cannot open file for writing: {$path}");
        }

        if ($this->canParallelize($dcId, $size)) {
            try {
                // A single consumer coroutine owns the handle, so ordered
                // writes never race even though fetches run concurrently.
                return $this->downloadChunksInOrder($location, $dcId, $size, static function (int $index, string $bytes) use ($handle, $path): void {
                    if (fwrite($handle, $bytes) === false) {
                        throw new MTProtoException("Failed to write to file: {$path}");
                    }
                });
            } finally {
                fclose($handle);
            }
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
            'messageMediaPhoto' => $this->resolvePhotoLocation($media, $thumbSize),
            'messageMediaDocument' => $this->resolveDocumentLocation($media),
            'photo' => $this->resolvePhotoDirectLocation($media, $thumbSize),
            'document' => $this->resolveDocumentDirectLocation($media),
            'message' => isset($media['media']) && is_array($media['media'])
                ? $this->resolveMediaLocation($media['media'], $thumbSize)
                : null,
            'messageMediaStory' => isset($media['story']['media']) && is_array($media['story']['media'])
                ? $this->resolveMediaLocation($media['story']['media'], $thumbSize)
                : null,
            'storyItem' => isset($media['media']) && is_array($media['media'])
                ? $this->resolveMediaLocation($media['media'], $thumbSize)
                : null,
            default => null,
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

        $targetSize = null;
        $targetSizeBytes = 0;

        if ($thumbSize !== null) {
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

        if (!isset($photo['id'], $photo['access_hash'])) {
            throw new MTProtoException('Photo is missing id/access_hash - cannot build a download location (a "min" object needs re-resolving first).');
        }

        return [
            'location' => [
                '_' => 'inputPhotoFileLocation',
                'id' => $photo['id'],
                'access_hash' => $photo['access_hash'],
                'file_reference' => $photo['file_reference'] ?? '',
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
        if (!isset($doc['id'], $doc['access_hash'])) {
            throw new MTProtoException('Document is missing id/access_hash - cannot build a download location (a "min" object needs re-resolving first).');
        }

        return [
            'location' => [
                '_' => 'inputDocumentFileLocation',
                'id' => $doc['id'],
                'access_hash' => $doc['access_hash'],
                'file_reference' => $doc['file_reference'] ?? '',
                'thumb_size' => '',
            ],
            'dc_id' => $doc['dc_id'] ?? $this->client->getDcId(),
            'size' => $doc['size'] ?? 0,
        ];
    }

    /**
     * Whether the parallel download path is eligible for this transfer:
     * concurrency asked for, size known (needed to compute chunk offsets), and
     * the HOME client running its pump inside a coroutine runtime. The file's
     * DC deliberately does NOT gate this: cross-DC concurrency comes from the
     * pump-enabled media sockets minted inside {@see downloadChunksInOrder()}
     * (the primary cross-DC pool sibling is sync and would always fail a
     * supportsConcurrentInvoke() check, wrongly forcing serial downloads for
     * any file living on another DC).
     */
    private function canParallelize(int $dcId, int $size): bool
    {
        if ($this->concurrency < 2 || $size <= self::CHUNK_SIZE) {
            return false;
        }

        if (!$this->client->getRuntime()->isSupported()) {
            return false;
        }

        return $this->client->supportsConcurrentInvoke();
    }

    /**
     * Fetch every chunk of a known-size file concurrently (bounded by
     * {@see $concurrency}) and hand them to $sink IN ORDER (0,1,2,…). A dedicated
     * producer coroutine keeps at most $concurrency fetches in flight; this
     * (consumer) coroutine reorders out-of-order arrivals and emits contiguous
     * chunks, so $sink is always called sequentially and may safely own a file
     * handle. Returns total bytes emitted.
     *
     * @param callable(int $index, string $bytes): void $sink
     * @throws MTProtoException
     */
    private function downloadChunksInOrder(array $location, int $dcId, int $size, callable $sink): int
    {
        $runtime = $this->client->getRuntime();
        $chunkSize = self::CHUNK_SIZE;
        $total = (int) ceil($size / $chunkSize);
        $concurrency = min($this->concurrency, $total);

        // Socket count comes from config (transfer.media_sockets); the in-flight
        // window ($concurrency) is independent - depth >1 per socket hides RTT.
        try {
            $conns = $this->client->mediaSockets($dcId);
        } catch (\Throwable $e) {
            $this->client->getLogger()?->warning("media sockets unavailable for DC{$dcId}, using single connection: {$e->getMessage()}");
            $conns = [$this->client->pool()->connection($dcId)];
        }

        // Only pump-driven connections tolerate concurrent invokes; a sync
        // fallback (e.g. a cross-DC pool sibling) must never see overlapping
        // RPCs, so collapse the window to strictly-serial in that case.
        $concurrent = array_values(array_filter($conns, static fn (Client $c): bool => $c->supportsConcurrentInvoke()));
        if ($concurrent !== []) {
            $conns = $concurrent;
        } else {
            $conns = [$conns[0]];
            $concurrency = 1;
        }
        $socketCount = count($conns);

        $tokens = $runtime->channel($concurrency);
        $results = $runtime->channel($concurrency);
        $errors = [];
        $written = 0;

        $runtime->run(function () use ($runtime, $location, $dcId, $chunkSize, $total, $tokens, $results, &$errors, &$written, $sink, $size, $conns, $socketCount): void {
            // Producer: spawn fetches, capped at $concurrency in flight.
            $runtime->spawn(function () use ($runtime, $location, $dcId, $chunkSize, $total, $tokens, $results, &$errors, $conns, $socketCount): void {
                for ($i = 0; $i < $total; $i++) {
                    $tokens->push(true);
                    $offset = $i * $chunkSize;
                    $conn = $conns[$i % $socketCount];

                    $runtime->spawn(function () use ($i, $conn, $location, $dcId, $offset, $chunkSize, $tokens, $results, &$errors): void {
                        $bytes = '';
                        try {
                            $chunk = $this->getFileChunkOn($conn, $dcId, $location, $offset, $chunkSize);
                            $bytes = $chunk['bytes'] ?? '';
                        } catch (\Throwable $e) {
                            $errors[$i] = $e;
                        } finally {
                            $tokens->pop();
                        }
                        $results->push([$i, $bytes]);
                    });
                }
            });

            // Consumer: reorder arrivals, emit contiguous chunks in order.
            $pending = [];
            $next = 0;
            for ($received = 0; $received < $total; $received++) {
                [$idx, $bytes] = $results->pop();
                $pending[$idx] = $bytes;

                while (array_key_exists($next, $pending)) {
                    $b = $pending[$next];
                    unset($pending[$next]);

                    // Trim the final chunk to the exact file size.
                    $chunkOffset = $next * $chunkSize;
                    if ($size > 0 && ($chunkOffset + strlen($b)) > $size) {
                        $b = substr($b, 0, $size - $chunkOffset);
                    }

                    // Integrity: every chunk must be exactly as long as expected
                    // (offsets are pre-computed at CHUNK_SIZE stride). A short
                    // chunk would silently corrupt the file - fail loudly instead.
                    $expected = min($chunkSize, $size - $chunkOffset);
                    if (strlen($b) !== $expected && !isset($errors[$next])) {
                        $errors[$next] = new MTProtoException(
                            "Short chunk at offset {$chunkOffset}: got " . strlen($b) . " of {$expected} bytes"
                        );
                    }

                    if ($b !== '') {
                        $sink($next, $b);
                        $written += strlen($b);
                    }
                    $next++;
                }
            }
        });

        $tokens->close();
        $results->close();

        if ($errors !== []) {
            ksort($errors);
            $first = reset($errors);
            throw new MTProtoException(
                'Parallel download failed on chunk ' . array_key_first($errors) . ': ' . $first->getMessage(),
                0,
                $first,
            );
        }

        return $written;
    }

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
        return $this->getFileChunkOn($this->client->pool()->connection($dcId), $dcId, $location, $offset, $limit);
    }

    /**
     * Fetch one chunk over an explicit connection (a primary pool client or a
     * media socket). Same error handling as {@see getFileChunk}.
     *
     * @return array{_: string, type: array, mtime: int, bytes: string}
     * @throws MTProtoException
     */
    protected function getFileChunkOn(Client $conn, int $dcId, array $location, int $offset, int $limit): array
    {
        try {
            $result = $this->fetchChunk($conn, $location, $offset, $limit);
        } catch (MTProtoException $e) {
            $msg = $e->getMessage();

            if ($dcId > 0 && $dcId !== $this->client->getDcId()
                && str_contains($msg, 'AUTH_KEY_UNREGISTERED')) {
                $this->client->pool()->ensureAuthorized($dcId);
                $result = $this->fetchChunk($conn, $location, $offset, $limit);
            } elseif (str_contains($msg, 'FILE_REFERENCE_EXPIRED') || str_contains($msg, 'FILE_REFERENCE_INVALID')) {
                throw new MTProtoException(
                    'File reference expired - re-fetch the source message (getMessages) to obtain a fresh '
                    . 'file_reference, then download again.',
                    0,
                    $e,
                );
            } else {
                throw $e;
            }
        }

        if (is_array($result) && ($result['_'] ?? '') === 'upload.fileCdnRedirect') {
            throw new MTProtoException('Server returned a CDN redirect, which is not supported by this downloader.');
        }

        return is_array($result) ? $result : ['bytes' => ''];
    }

    /**
     * Fetch one chunk over a specific (possibly cross-DC) connection.
     */
    private function fetchChunk(Client $conn, array $location, int $offset, int $limit): mixed
    {
        return $conn->invoke('upload.getFile', [
            'precise' => true,
            'location' => $location,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

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
            'document' => $media,
            default => null,
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
            'document' => $media,
            default => null,
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

    /**
     * Guess file extension from MIME type.
     */
    public static function extensionFromMime(?string $mimeType): string
    {
        if ($mimeType === null) {
            return '';
        }

        return match ($mimeType) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'image/bmp' => '.bmp',
            'video/mp4' => '.mp4',
            'video/quicktime' => '.mov',
            'audio/mpeg' => '.mp3',
            'audio/ogg' => '.ogg',
            'audio/mp4' => '.m4a',
            'audio/aac' => '.aac',
            'audio/flac' => '.flac',
            'application/x-tgsticker' => '.tgs',
            'image/x-tgsticker' => '.tgs',
            'application/pdf' => '.pdf',
            'application/zip' => '.zip',
            'application/x-rar-compressed' => '.rar',
            'text/plain' => '.txt',
            'text/html' => '.html',
            default => '',
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
