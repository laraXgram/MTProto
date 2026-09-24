<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Transfer\ParallelTransfer;
use RuntimeException;

final class FileUploader
{
    /**
     * Part size - 512 KiB, the maximum Telegram allows (`524288 % part_size == 0`
     * per https://core.telegram.org/api/files). Bigger parts are rejected, so a
     * large file scales in the number of parts, not their size.
     */
    public const PART_SIZE = 524288;

    /** Files larger than this go the "big file" route (no md5). */
    public const BIG_FILE_THRESHOLD = 10 * 1024 * 1024;

    /**
     * Upper bound on parts. Telegram's real cap is the appConfig value
     * `upload_max_fileparts_default` (non-premium) / `_premium` - currently 4000
     * / 8000. At the 512 KiB max part size that is ~2 GiB / ~4 GiB. The server
     * still enforces the true per-account limit, surfacing a clean RPC error.
     */
    public const MAX_PARTS = 8000;

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
     * Upload a file from a filesystem path. Parts are read from disk as they
     * are sent, so memory use stays flat for multi-gigabyte files.
     *
     * @param callable|null $progress fn(int $partsDone, int $partsTotal): void
     * @return array  An `inputFile` or `inputFileBig` TL constructor.
     */
    public function fromPath(string $path, ?string $fileName = null, ?callable $progress = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Upload source not found: {$path}");
        }

        clearstatcache(true, $path);

        return $this->upload(
            (int) filesize($path),
            static function (int $offset, int $length) use ($path): string {
                $bytes = file_get_contents($path, false, null, $offset, $length);
                if ($bytes === false || strlen($bytes) !== $length) {
                    throw new RuntimeException("Cannot read {$length} bytes at offset {$offset} of {$path}");
                }

                return $bytes;
            },
            $fileName ?? $this->files->basename($path),
            $progress,
            static fn (): string => (string) md5_file($path),
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
        return $this->upload(
            strlen($contents),
            static fn (int $offset, int $length): string => substr($contents, $offset, $length),
            $fileName,
            $progress,
            static fn (): string => md5($contents),
        );
    }

    /**
     * @param callable(int $offset, int $length): string $read
     * @param callable(): string $md5
     */
    private function upload(int $size, callable $read, string $fileName, ?callable $progress, callable $md5): array
    {
        if ($size === 0) {
            throw new RuntimeException('Refusing to upload an empty file.');
        }

        $isBig = $size > self::BIG_FILE_THRESHOLD;
        $totalParts = (int) ceil($size / self::PART_SIZE);

        if ($totalParts > self::MAX_PARTS) {
            throw new RuntimeException(sprintf(
                'File too large: %d parts exceeds the %d-part limit.',
                $totalParts,
                self::MAX_PARTS,
            ));
        }

        $fileId = random_int(PHP_INT_MIN, PHP_INT_MAX);
        $part = static fn (int $index): string => $read($index * self::PART_SIZE, min(self::PART_SIZE, $size - $index * self::PART_SIZE));

        if ($totalParts > 1 && $this->concurrency > 1 && $this->client->supportsConcurrentInvoke()) {
            $this->uploadParallel($part, $fileId, $totalParts, $isBig, $size, $progress);
        } else {
            for ($index = 0; $index < $totalParts; $index++) {
                $this->client->invoke(...$this->partCall($fileId, $index, $totalParts, $isBig, $part($index)));
                $progress !== null && $progress($index + 1, $totalParts);
            }
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
            'md5_checksum' => $md5(),
        ];
    }

    /**
     * The upload.saveFilePart / saveBigFilePart call for one part.
     *
     * @return array{0: string, 1: array}
     */
    private function partCall(int $fileId, int $index, int $totalParts, bool $isBig, string $bytes): array
    {
        return $isBig
            ? ['upload.saveBigFilePart', ['file_id' => $fileId, 'file_part' => $index, 'file_total_parts' => $totalParts, 'bytes' => $bytes]]
            : ['upload.saveFilePart', ['file_id' => $fileId, 'file_part' => $index, 'bytes' => $bytes]];
    }

    /**
     * Upload parts concurrently over the media sockets of the home DC. Parts
     * are keyed by file_id, so the server assembles them whichever socket each
     * one arrived on.
     *
     * @param callable(int $index): string $part
     */
    private function uploadParallel(callable $part, int $fileId, int $totalParts, bool $isBig, int $size, ?callable $progress): void
    {
        $logger = $this->client->getLogger();

        try {
            $sockets = $this->client->mediaSockets($this->client->getDcId());
        } catch (\Throwable $e) {
            $logger?->warning("FileUploader: media sockets unavailable, using single connection: {$e->getMessage()}");
            $sockets = [$this->client];
        }
        $sockets = array_values(array_filter($sockets, static fn (Client $c): bool => $c->supportsConcurrentInvoke())) ?: [$this->client];

        $logger?->info(sprintf(
            'FileUploader: parallel upload - %d socket(s), window %d, %d part(s)',
            count($sockets),
            $this->concurrency,
            $totalParts,
        ));

        $transfer = new ParallelTransfer($this->client->getRuntime(), $sockets, $this->concurrency, $logger);
        $done = 0;

        $this->client->getRuntime()->run(function () use ($transfer, $part, $fileId, $totalParts, $isBig, $size, $progress, &$done): void {
            $transfer->run(
                $totalParts,
                function (int $index, Client $socket) use ($part, $fileId, $totalParts, $isBig) {
                    $result = $socket->invokeTransfer(...$this->partCall($fileId, $index, $totalParts, $isBig, $part($index)));
                    if ($result !== true) {
                        throw new RuntimeException('RPC_CALL_FAIL: part ' . $index . ' was not saved (server returned false)');
                    }

                    return $result;
                },
                static function () use ($progress, $totalParts, &$done): void {
                    $done++;
                    $progress !== null && $progress($done, $totalParts);
                },
                static fn (int $index): int => min(self::PART_SIZE, $size - $index * self::PART_SIZE),
            );
        });

        $stats = $transfer->stats();
        if ($stats['retries'] > 0) {
            $logger?->info("FileUploader: {$stats['parts']} part(s), {$stats['retries']} retried, {$stats['floods']} flood wait(s)");
        }
    }
}
