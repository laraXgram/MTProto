<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

/**
 * Recursively wraps raw arrays into objects with property-style access.
 *
 * This lets MTProto update data be accessed exactly like Bot API:
 *
 * Arrays with sequential integer keys stay as arrays (of DataObjects).
 * Associative arrays become DataObject instances.
 */
class DataObject implements \JsonSerializable, \ArrayAccess
{
    protected array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Create from a raw array (static factory).
     */
    public static function from(array|null $data): ?static
    {
        if ($data === null) {
            return null;
        }

        return new static($data);
    }

    /**
     * Property-style access: $obj->key
     */
    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            return null;
        }

        return static::wrap($this->data[$name]);
    }

    /**
     * Property-style isset: isset($obj->key)
     */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    /**
     * ArrayAccess: $obj['key']
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!array_key_exists($offset, $this->data)) {
            return null;
        }

        return static::wrap($this->data[$offset]);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    /**
     * Wrap a value: arrays become DataObjects or arrays of DataObjects.
     */
    public static function wrap(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // Sequential arrays (lists) → array of wrapped items
        if (array_is_list($value)) {
            return array_map(fn($item) => static::wrap($item), $value);
        }

        // Associative arrays → DataObject
        return new static($value);
    }

    /**
     * Get the raw underlying array.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get the data as a JSON string.
     *
     * Uses JSON_INVALID_UTF8_SUBSTITUTE by default to safely handle
     * binary data (file references, thumbnails) in MTProto updates.
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->data, $options | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * Get a value using dot-notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $current = $this->data;

        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return $default;
            }
            $current = $current[$k];
        }

        return static::wrap($current);
    }

    /**
     * Check if key exists using dot-notation.
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $current = $this->data;

        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return false;
            }
            $current = $current[$k];
        }

        return true;
    }

    public function __toString(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    public function __debugInfo(): array
    {
        return $this->data;
    }
}
