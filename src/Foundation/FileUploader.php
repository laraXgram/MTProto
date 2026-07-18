<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Core\Client;
use RuntimeException;

final class FileUploader
{
    /** Part size - 512 KiB. Must evenly divide 1 MiB. */
    public const PART_SIZE = 524288;

    /** Files larger than this go the "big file" route (no md5). */
    public const BIG_FILE_THRESHOLD = 10 * 1024 * 1024;

    /** Telegram's hard cap on the number of parts. */
    public const MAX_PARTS = 4000;

    /** Default max parts in flight at once on the parallel path. */
    public const DEFAULT_CONCURRENCY = 4;

    private Filesystem $files;

    /** Max concurrent part uploads (parallel path only). */
    private int $concurrency = self::DEFAULT_CONCURRENCY;

    public function __construct(
        private readonly Client $client,
        ?Filesystem             $files = null,
    )
    {
        $this->files = $files ?? new Filesystem();
    }

    /**
     * Set the number of parts uploaded concurrently on the parallel path.
     */
    public function withConcurrency(int $n): self
    {
        $this->concurrency = max(1, $n);

        return $this;
    }

    /**
     * Upload a file from a filesystem path.
     *
     * @param callable|null $progress fn(int $partsDone, int $partsTotal): void
     * @return array  An `inputFile` or `inputFileBig` TL constructor.
     */
    public function fromPath(string $path, ?string $fileName = null, ?callable $progress = null): array
    {
        if (!$this->files->exists($path)) {
            throw new RuntimeException("Upload source not found: {$path}");
        }

        return $this->fromString(
            $this->files->get($path),
            $fileName ?? $this->files->basename($path),
            $progress,
        );
    }

    /**
     * Upload raw bytes.
     *
     * @param callable|null $progress fn(int $partsDone, int $partsTotal): void
     * @return array  An `inputFile` or `inputFileBig` TL constructor.
     */
    public function fromString(string $contents, string $fileName, ?callable $progress = null): array
    {
        $size = strlen($contents);

        if ($size === 0) {
            throw new RuntimeException('Refusing to upload an empty file.');
        }

        $isBig = $size > self::BIG_FILE_THRESHOLD;
        $totalParts = (int)max(1, (int)ceil($size / self::PART_SIZE));

        if ($totalParts > self::MAX_PARTS) {
            throw new RuntimeException(sprintf(
                'File too large: %d parts exceeds the %d-part limit.',
                $totalParts,
                self::MAX_PARTS,
            ));
        }

        $fileId = $this->randomFileId();

        if ($totalParts > 1 && $this->concurrency > 1 && $this->client->supportsConcurrentInvoke()) {
            $this->uploadParts($contents, $fileId, $totalParts, $isBig, $progress);
        } else {
            $this->uploadPartsSerial($contents, $fileId, $totalParts, $isBig, $progress);
        }

        if ($isBig) {
            return [
                '_' => 'inputFileBig',
                'id' => $fileId,
                'parts' => $totalParts,
                'name' => $fileName,
            ];
        }

        return [
            '_' => 'inputFile',
            'id' => $fileId,
            'parts' => $totalParts,
            'name' => $fileName,
            'md5_checksum' => md5($contents),
        ];
    }

    /**
     * Upload one part over the home connection. Serial path / single part.
     */
    private function uploadPart(string $chunk, int $fileId, int $part, int $totalParts, bool $isBig): void
    {
        $this->uploadPartOn($this->client, $chunk, $fileId, $part, $totalParts, $isBig);
    }

    /**
     * Upload one part over an explicit connection (home or a media socket).
     * Parts are keyed by file_id, so the server assembles them regardless of
     * which same-DC socket each arrived on.
     */
    private function uploadPartOn(Client $conn, string $chunk, int $fileId, int $part, int $totalParts, bool $isBig): void
    {
        if ($isBig) {
            $conn->invoke('upload.saveBigFilePart', [
                'file_id' => $fileId,
                'file_part' => $part,
                'file_total_parts' => $totalParts,
                'bytes' => $chunk,
            ]);
        } else {
            $conn->invoke('upload.saveFilePart', [
                'file_id' => $fileId,
                'file_part' => $part,
                'bytes' => $chunk,
            ]);
        }
    }

    /**
     * Sequential upload - one part after another (sync RPC path / single part).
     */
    private function uploadPartsSerial(string $contents, int $fileId, int $totalParts, bool $isBig, ?callable $progress): void
    {
        for ($part = 0; $part < $totalParts; $part++) {
            $chunk = substr($contents, $part * self::PART_SIZE, self::PART_SIZE);
            $this->uploadPart($chunk, $fileId, $part, $totalParts, $isBig);

            if ($progress !== null) {
                $progress($part + 1, $totalParts);
            }
        }
    }

    /**
     * Concurrent upload.
     */
    private function uploadParts(string $contents, int $fileId, int $totalParts, bool $isBig, ?callable $progress): void
    {
        $runtime = $this->client->getRuntime();

        // Spread parts over several media sockets to the home DC.
        try {
            $conns = $this->client->mediaSockets($this->client->getDcId(), $this->concurrency);
        } catch (\Throwable $e) {
            $this->client->getLogger()?->debug("media sockets unavailable for upload, using single connection: {$e->getMessage()}");
            $conns = [$this->client];
        }
        $socketCount = count($conns);

        $tokens = $runtime->channel($this->concurrency);
        $done = $runtime->channel($totalParts);
        $errors = [];

        $runtime->run(function () use ($runtime, $contents, $fileId, $totalParts, $isBig, $tokens, $done, &$errors, $conns, $socketCount) {
            for ($part = 0; $part < $totalParts; $part++) {
                $tokens->push(true);
                $chunk = substr($contents, $part * self::PART_SIZE, self::PART_SIZE);
                $conn = $conns[$part % $socketCount];

                $runtime->spawn(function () use ($conn, $chunk, $fileId, $part, $totalParts, $isBig, $tokens, $done, &$errors) {
                    try {
                        $this->uploadPartOn($conn, $chunk, $fileId, $part, $totalParts, $isBig);
                    } catch (\Throwable $e) {
                        $errors[$part] = $e;
                    } finally {
                        $tokens->pop();
                        $done->push(true);
                    }
                });
            }

            for ($i = 0; $i < $totalParts; $i++) {
                $done->pop();
            }
        });

        $tokens->close();
        $done->close();

        if ($errors !== []) {
            ksort($errors);
            throw new RuntimeException(
                'Parallel upload failed on part ' . array_key_first($errors) . ': ' . reset($errors)->getMessage(),
                0,
                reset($errors),
            );
        }

        if ($progress !== null) {
            $progress($totalParts, $totalParts);
        }
    }

    /**
     * Random signed 64-bit file id (the handle Telegram uses to assemble parts).
     */
    private function randomFileId(): int
    {
        return random_int(PHP_INT_MIN, PHP_INT_MAX);
    }
}
