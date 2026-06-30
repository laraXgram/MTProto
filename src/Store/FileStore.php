<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Contracts\Store;

final class FileStore implements Store
{
    private Filesystem $files;

    public function __construct(
        private string $directory,
        private string $extension = '',
        ?Filesystem $files = null,
    ) {
        $this->files = $files ?? new Filesystem();
        $this->files->ensureDirectoryExists($this->directory, 0700);
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);

        if (!$this->files->exists($path)) {
            return null;
        }

        try {
            return $this->files->get($path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function put(string $key, string $value): void
    {
        $this->files->replace($this->path($key), $value);
    }

    public function has(string $key): bool
    {
        return $this->files->exists($this->path($key));
    }

    public function forget(string $key): bool
    {
        $path = $this->path($key);

        if (!$this->files->exists($path)) {
            return true;
        }

        return (bool) $this->files->delete($path);
    }

    /**
     * Resolve a key to its on-disk path, sanitising it to a safe filename.
     */
    private function path(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);

        return rtrim($this->directory, '/') . '/' . $safe . $this->extension;
    }
}
