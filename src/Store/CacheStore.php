<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\Contracts\Cache\Repository;
use LaraGram\MTProto\Contracts\Store;

final class CacheStore implements Store
{
    public function __construct(
        private Repository $cache,
        private string $prefix = 'mtproto:',
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($this->prefix . $key);

        return $value === null ? null : (string) $value;
    }

    public function put(string $key, string $value): void
    {
        $this->cache->forever($this->prefix . $key, $value);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): bool
    {
        return (bool) $this->cache->forget($this->prefix . $key);
    }
}
