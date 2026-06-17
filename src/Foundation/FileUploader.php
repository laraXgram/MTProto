<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Core\Client;
use RuntimeException;

/**
 * Uploads a local file (or raw bytes) to Telegram and returns the matching
 * `InputFile` / `InputFileBig` constructor for use in `InputMedia*` builders.
 *
 * MTProto upload rules implemented here:
 *   - Files are split into <= 512 KiB parts (the part size must divide 1 MiB).
 *   - Files <= 10 MiB use `upload.saveFilePart` and carry an md5 checksum in
 *     the resulting `inputFile` (Telegram verifies it).
 *   - Files  > 10 MiB use `upload.saveBigFilePart` (no md5) and produce an
 *     `inputFileBig`; every part must declare the total part count.
 *   - At most 4000 parts (≈ 2 GiB at 512 KiB/part).
 */
final class FileUploader
{
    /** Part size — 512 KiB. Must evenly divide 1 MiB. */
    public const PART_SIZE = 524288;

    /** Files larger than this go the "big file" route (no md5). */
    public const BIG_FILE_THRESHOLD = 10 * 1024 * 1024;

    /** Telegram's hard cap on the number of parts. */
    public const MAX_PARTS = 4000;

    private Filesystem $files;

    public function __construct(
        private readonly Client $client,
        ?Filesystem $files = null,
    ) {
        $this->files = $files ?? new Filesystem();
    }

    /**
     * Upload a file from a filesystem path.
     *
     * @param  callable|null  $progress  fn(int $partsDone, int $partsTotal): void
     * @return array  An `inputFile` or `inputFileBig` TL constructor.
     */
    public function fromPath(string $path, ?string $fileName = null, ?callable $progress = null): array
    {
        if (! $this->files->exists($path)) {
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
     * @param  callable|null  $progress  fn(int $partsDone, int $partsTotal): void
     * @return array  An `inputFile` or `inputFileBig` TL constructor.
     */
    public function fromString(string $contents, string $fileName, ?callable $progress = null): array
    {
        $size = strlen($contents);

        if ($size === 0) {
            throw new RuntimeException('Refusing to upload an empty file.');
        }

        $isBig      = $size > self::BIG_FILE_THRESHOLD;
        $totalParts = (int) max(1, (int) ceil($size / self::PART_SIZE));

        if ($totalParts > self::MAX_PARTS) {
            throw new RuntimeException(sprintf(
                'File too large: %d parts exceeds the %d-part limit.',
                $totalParts,
                self::MAX_PARTS,
            ));
        }

        $fileId = $this->randomFileId();

        for ($part = 0; $part < $totalParts; $part++) {
            $chunk = substr($contents, $part * self::PART_SIZE, self::PART_SIZE);

            if ($isBig) {
                $this->client->invoke('upload.saveBigFilePart', [
                    'file_id'          => $fileId,
                    'file_part'        => $part,
                    'file_total_parts' => $totalParts,
                    'bytes'            => $chunk,
                ]);
            } else {
                $this->client->invoke('upload.saveFilePart', [
                    'file_id'   => $fileId,
                    'file_part' => $part,
                    'bytes'     => $chunk,
                ]);
            }

            if ($progress !== null) {
                $progress($part + 1, $totalParts);
            }
        }

        if ($isBig) {
            return [
                '_'     => 'inputFileBig',
                'id'    => $fileId,
                'parts' => $totalParts,
                'name'  => $fileName,
            ];
        }

        return [
            '_'            => 'inputFile',
            'id'           => $fileId,
            'parts'        => $totalParts,
            'name'         => $fileName,
            'md5_checksum' => md5($contents),
        ];
    }

    /**
     * Random signed 64-bit file id (the handle Telegram uses to assemble parts).
     */
    private function randomFileId(): int
    {
        return random_int(PHP_INT_MIN, PHP_INT_MAX);
    }
}
