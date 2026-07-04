<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\Contracts\Cache\Repository;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Runtime\Contracts\Table;

final class StoreManager
{
    private const DEFAULT_TABLE_SPECS = [
        'peer'    => ['table' => 'mtproto_peer',    'rows' => 16, 'size' => 1048576],
        'session' => ['table' => 'mtproto_session', 'rows' => 16, 'size' => 16384],
        'state'   => ['table' => 'mtproto_state',   'rows' => 16, 'size' => 131072],
        'limit'   => ['table' => 'mtproto_limit',   'rows' => 16, 'size' => 262144],
    ];

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
     * @return array{table: string, rows: int, size: int}
     */
    public static function defaultTableSpec(string $name): array
    {
        return self::DEFAULT_TABLE_SPECS[$name] ?? ['table' => "mtproto_{$name}", 'rows' => 1024, 'size' => 8192];
    }

    /**
     * @param array<string, mixed> $config
     * @param string $name
     */
    public function make(array $config, string $name = 'mtproto'): Store
    {
        $driver = strtolower((string) ($config['driver'] ?? 'file'));

        if ($driver === 'swoole-table' || $driver === 'swoole_table') {
            $defaults = self::defaultTableSpec($name);

            return new SwooleTableStore(
                $this->table(
                    (string) ($config['table'] ?? $defaults['table']),
                    (int)    ($config['rows']  ?? $defaults['rows']),
                    (int)    ($config['size']  ?? $defaults['size']),
                )
            );
        }

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
