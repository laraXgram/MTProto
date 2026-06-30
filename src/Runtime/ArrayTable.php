<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime;

use LaraGram\MTProto\Runtime\Contracts\Table;

final class ArrayTable implements Table
{
    /** @var array<string, string> */
    private array $rows = [];

    public function __construct(private int $valueSize = PHP_INT_MAX)
    {
    }

    public function get(string $key): ?string
    {
        return $this->rows[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $length = strlen($value);

        if ($length > $this->valueSize) {
            throw new \LengthException(
                "Value ({$length} bytes) exceeds the table column width ({$this->valueSize} bytes)."
            );
        }

        $this->rows[$key] = $value;
    }

    public function exists(string $key): bool
    {
        return isset($this->rows[$key]);
    }

    public function del(string $key): bool
    {
        unset($this->rows[$key]);

        return true;
    }
}
