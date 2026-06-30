<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime\Contracts;

interface Table
{
    /**
     * Fetch a stored value, or null when the key is absent.
     */
    public function get(string $key): ?string;

    /**
     * Store (or overwrite) a value for the key. The value must fit the table's
     * configured byte width; longer values are rejected by the implementation.
     */
    public function set(string $key, string $value): void;

    /**
     * Whether a value exists for the key.
     */
    public function exists(string $key): bool;

    /**
     * Remove a value. Returns true when a row was removed (or already absent).
     */
    public function del(string $key): bool;
}
