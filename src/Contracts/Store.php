<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

interface Store
{
    /**
     * Fetch a stored value, or null when the key is absent.
     */
    public function get(string $key): ?string;

    /**
     * Store (or overwrite) a value for the key.
     */
    public function put(string $key, string $value): void;

    /**
     * Whether a value exists for the key.
     */
    public function has(string $key): bool;

    /**
     * Remove a value. Returns true if a value was removed (or already absent).
     */
    public function forget(string $key): bool;
}
