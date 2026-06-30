<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\MTProto\Contracts\Store;

final class ArrayStore implements Store
{
    /** @var array<string, string> */
    private array $data = [];

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function put(string $key, string $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function forget(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }
}
