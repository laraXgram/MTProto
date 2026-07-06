<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

/**
 * Shared decoding helpers for the concrete {@see SessionSource} implementations.
 */
abstract class AbstractSource implements SessionSource
{
    /**
     * Decode a URL-safe (or standard) base64 string, tolerating missing padding
     * as produced by Pyrogram/Telethon session strings.
     */
    protected function base64(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');

        $mod = strlen($value) % 4;
        if ($mod !== 0) {
            $value .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Open a SQLite database file read-only via PDO.
     *
     * @throws \RuntimeException when the pdo_sqlite extension is missing or the
     *                           file cannot be opened.
     */
    protected function openSqlite(string $path): \PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException(
                'Importing a file-based session requires the pdo_sqlite PHP extension, which is not loaded. '
                . 'Install it, or import from a session string instead.'
            );
        }

        if (!is_file($path)) {
            throw new \RuntimeException("Session file not found: {$path}");
        }

        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Cannot open SQLite session '{$path}': " . $e->getMessage(), 0, $e);
        }

        return $pdo;
    }

    /**
     * Whether the input points at an existing regular file on disk.
     */
    protected function isFile(string $input): bool
    {
        return $input !== '' && @is_file($input);
    }

    /**
     * Read the leading magic bytes of a file without slurping the whole thing.
     */
    protected function fileMagic(string $path, int $bytes = 16): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $magic = (string) fread($handle, $bytes);
        fclose($handle);

        return $magic;
    }
}
