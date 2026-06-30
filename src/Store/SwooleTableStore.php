<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Runtime\Contracts\Table;

final class SwooleTableStore implements Store
{
    public function __construct(private Table $table)
    {
    }

    public function get(string $key): ?string
    {
        return $this->table->get($key);
    }

    public function put(string $key, string $value): void
    {
        $this->table->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->table->exists($key);
    }

    public function forget(string $key): bool
    {
        return $this->table->del($key);
    }
}
