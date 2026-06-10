<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

/**
 * Base class for all TL type objects.
 *
 * Wraps a raw Telegram response array and provides:
 *  - Property access:    $obj->first_name
 *  - Array access:       $obj['first_name']
 *  - Constructor name:   $obj->getConstructor()   // e.g. "user"
 *  - To array:           $obj->toArray()
 *  - To JSON:            $obj->toJson()
 *  - json_encode():      json_encode($obj)
 *  - isset():            isset($obj->first_name)  /  isset($obj['first_name'])
 *  - foreach:            foreach ($obj as $key => $value)
 *
 * @property-read mixed $message
 * @property-read mixed $id
 * @property-read mixed $user_id
 * @property-read mixed $chat_id
 * @property-read mixed $channel_id
 * @property-read mixed $from_id
 * @property-read mixed $peer_id
 * @property-read mixed $date
 * @property-read mixed $text
 * @property-read mixed $out
 * @property-read mixed $mentioned
 * @property-read mixed $media
 * @property-read mixed $reply_to
 * @property-read mixed $entities
 * @property-read mixed $pts
 * @property-read mixed $pts_count
 * @property-read mixed $qts
 * @property-read mixed $seq
 * @property-read mixed $update
 * @property-read mixed $updates
 * @property-read mixed $users
 * @property-read mixed $chats
 * @property-read mixed $fwd_from
 * @property-read mixed $via_bot_id
 * @property-read mixed $reply_markup
 * @property-read mixed $silent
 * @property-read mixed $media_unread
 * @property-read mixed $ttl_period
 */
#[\AllowDynamicProperties]
class TLObject implements \ArrayAccess, \JsonSerializable, \IteratorAggregate, \Countable
{
    /** @var array<string, mixed> Raw TL data */
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Property access — auto-wraps nested TL objects
    // ════════════════════════════════════════════════════════════════════

    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            return null;
        }

        $value = $this->data[$name];

        // Single nested TL object (has '_' constructor key) → wrap
        if (is_array($value) && isset($value['_'])) {
            $wrapped = self::fromArray($value);
            $this->data[$name] = $wrapped; // cache
            return $wrapped;
        }

        // Array of TL objects (e.g. chats, users, updates) → wrap each
        if (is_array($value) && !empty($value)) {
            $first = reset($value);
            if (is_array($first) && isset($first['_'])) {
                $wrapped = array_map(static fn(array $item) => self::fromArray($item), $value);
                $this->data[$name] = $wrapped; // cache
                return $wrapped;
            }
        }

        return $value;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    // ════════════════════════════════════════════════════════════════════
    //  Factory — resolve '_' constructor to the right Type subclass
    // ════════════════════════════════════════════════════════════════════

    /**
     * Create the appropriate Type subclass from a raw TL array.
     *
     * Maps the '_' constructor name to a Generated\Types class via ConstructorMap.
     * e.g. ['_' => 'user', ...] → new User(...)
     *      ['_' => 'updateShortMessage', ...] → new Updates(...)
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['_'])) {
            return new self($data);
        }

        $constructor = $data['_'];

        // Use the generated constructor→class mapping
        static $map = null;
        if ($map === null) {
            $mapClass = 'LaraGram\\MTProto\\Generated\\Types\\ConstructorMap';
            $map = class_exists($mapClass) ? $mapClass::MAP : [];
        }

        $class = $map[$constructor] ?? null;
        if ($class !== null) {
            return new $class($data);
        }

        return new self($data);
    }

    // ════════════════════════════════════════════════════════════════════
    //  TL helpers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Get the TL constructor name, e.g. "user", "updateShortMessage", "messages.messages".
     */
    public function getConstructor(): ?string
    {
        return $this->data['_'] ?? null;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Conversion
    // ════════════════════════════════════════════════════════════════════

    /**
     * Get the raw underlying array.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get a JSON string of the data.
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT): string
    {
        $result = json_encode($this->data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($result === false) {
            // Fallback: convert non-serializable values to string representation
            return json_encode(
                $this->sanitizeForJson($this->data),
                $flags | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '{}';
        }

        return $result;
    }

    /**
     * Recursively sanitize data for JSON encoding.
     */
    private function sanitizeForJson(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeForJson'], $data);
        }

        if (is_string($data) && !mb_check_encoding($data, 'UTF-8')) {
            return '(binary:' . bin2hex($data) . ')';
        }

        return $data;
    }

    /**
     * Support json_encode($obj).
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * Alias for getConstructor().
     */
    public function __toString(): string
    {
        return $this->getConstructor() ?? static::class;
    }

    // ════════════════════════════════════════════════════════════════════
    //  ArrayAccess
    // ════════════════════════════════════════════════════════════════════

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('TLObject is read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('TLObject is read-only');
    }

    // ════════════════════════════════════════════════════════════════════
    //  Iterable & Countable
    // ════════════════════════════════════════════════════════════════════

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
