<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\Contracts\Cache\Repository;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Runtime\Contracts\Table;

final class StoreManager
{
    /**
     * @param (callable(?string): Repository)|null    $cacheFactory
     * @param (callable(string, int, int): Table)|null $tableFactory
     */
    public function __construct(
        private $cacheFactory = null,
        private ?Filesystem $files = null,
        private $tableFactory = null,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function make(array $config): Store
    {
        $driver = strtolower((string) ($config['driver'] ?? 'file'));

        return match ($driver) {
            'file' => new FileStore(
                (string) ($config['path'] ?? './stores'),
                (string) ($config['extension'] ?? ''),
                $this->files,
            ),

            'array', 'memory', 'sync' => new ArrayStore(),

            'cache' => new CacheStore(
                $this->cache(($config['cache_store'] ?? null) ? (string) $config['cache_store'] : null),
                (string) ($config['prefix'] ?? 'mtproto:'),
            ),

            'database', 'redis' => new CacheStore(
                $this->cache($driver),
                (string) ($config['prefix'] ?? 'mtproto:'),
            ),

            'swoole-table', 'swoole_table' => new SwooleTableStore(
                $this->table(
                    (string) ($config['table'] ?? 'mtproto'),
                    (int)    ($config['rows']  ?? 1024),
                    (int)    ($config['size']  ?? 8192),
                )
            ),

            default => throw new \InvalidArgumentException("Unknown store driver [{$driver}]."),
        };
    }

    private function cache(?string $name): Repository
    {
        if ($this->cacheFactory === null) {
            throw new \RuntimeException(
                "Store driver requires a cache factory, but none was provided to the StoreManager."
            );
        }

        return ($this->cacheFactory)($name);
    }

    private function table(string $name, int $rows, int $size): Table
    {
        if ($this->tableFactory === null) {
            throw new \RuntimeException(
                "The 'swoole-table' store driver requires a table factory (Runtime::table), "
                . 'but none was provided to the StoreManager; use file/database/redis instead.'
            );
        }

        return ($this->tableFactory)($name, $rows, $size);
    }
}
