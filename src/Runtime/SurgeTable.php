<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime;

use LaraGram\MTProto\Runtime\Contracts\Table;

final class SurgeTable implements Table
{
    public function __construct(private \Swoole\Table $table, private int $valueSize)
    {
    }

    public function get(string $key): ?string
    {
        $row = $this->table->get($this->hash($key));

        if ($row === false || !isset($row['v'])) {
            return null;
        }

        return (string) $row['v'];
    }

    public function set(string $key, string $value): void
    {
        $length = strlen($value);

        if ($length > $this->valueSize) {
            throw new \LengthException(
                "Value ({$length} bytes) exceeds the swoole-table column width "
                . "({$this->valueSize} bytes); raise the surge table's column size or use file/redis."
            );
        }

        $this->table->set($this->hash($key), ['v' => $value]);
    }

    public function exists(string $key): bool
    {
        return $this->table->exists($this->hash($key));
    }

    public function del(string $key): bool
    {
        $hashed = $this->hash($key);

        if (!$this->table->exists($hashed)) {
            return true;
        }

        return $this->table->del($hashed);
    }

    private function hash(string $key): string
    {
        return md5($key);
    }
}
