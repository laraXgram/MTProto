<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use Closure;

/**
 * Disambiguation proxy returned by {@see ClientRequest::__get()} when an
 * accessed name is BOTH an API namespace (e.g. `messages`) AND a key present in
 * the current update payload (e.g. the deleted-message-id array carried by
 * `updateDeleteMessages`).
 *
 * i.e. a method call that the namespace actually exposes is treated as a
 * namespace call; everything else falls through to the raw update value.
 */
final class PendingNamespaceOrUpdate implements \ArrayAccess, \JsonSerializable, \IteratorAggregate, \Countable
{
    /** Resolved namespace object, memoized. */
    private ?object $namespace = null;

    private bool $namespaceResolved = false;

    /** Resolved update payload value, memoized. */
    private mixed $payload = null;

    private bool $payloadResolved = false;

    /**
     * @param Closure(): ?object $namespaceResolver Returns the API namespace object (or null).
     * @param Closure(): mixed $payloadResolver Returns the wrapped update-payload value.
     */
    public function __construct(
        private readonly Closure $namespaceResolver,
        private readonly Closure $payloadResolver,
    )
    {
    }

    private function namespace(): ?object
    {
        if (!$this->namespaceResolved) {
            $this->namespace = ($this->namespaceResolver)();
            $this->namespaceResolved = true;
        }

        return $this->namespace;
    }

    private function payload(): mixed
    {
        if (!$this->payloadResolved) {
            $this->payload = ($this->payloadResolver)();
            $this->payloadResolved = true;
        }

        return $this->payload;
    }

    /**
     * A method call resolves to the namespace when the namespace defines it,
     * otherwise it is forwarded to the update payload (e.g. `toArray()`).
     */
    public function __call(string $method, array $arguments): mixed
    {
        $namespace = $this->namespace();
        if ($namespace !== null && method_exists($namespace, $method)) {
            return $namespace->{$method}(...$arguments);
        }

        $payload = $this->payload();
        if (is_object($payload)) {
            return $payload->{$method}(...$arguments);
        }

        throw new \BadMethodCallException(
            "Method '{$method}' is neither an API namespace method nor available on the update value."
        );
    }

    /**
     * Property access always means the update payload.
     */
    public function __get(string $name): mixed
    {
        $payload = $this->payload();

        if (is_object($payload)) {
            return $payload->{$name};
        }
        if (is_array($payload)) {
            return $payload[$name] ?? null;
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        $payload = $this->payload();

        if (is_object($payload)) {
            return isset($payload->{$name});
        }

        return is_array($payload) && isset($payload[$name]);
    }

    //  Value semantics -> always the update payload

    public function offsetExists(mixed $offset): bool
    {
        $payload = $this->payload();

        if ($payload instanceof \ArrayAccess) {
            return $payload->offsetExists($offset);
        }

        return is_array($payload) && isset($payload[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $payload = $this->payload();

        if ($payload instanceof \ArrayAccess) {
            return $payload->offsetGet($offset);
        }

        return is_array($payload) ? ($payload[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Update values are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Update values are read-only.');
    }

    public function getIterator(): \Iterator
    {
        $payload = $this->payload();

        if ($payload instanceof \IteratorAggregate) {
            $it = $payload->getIterator();
            return $it instanceof \Iterator ? $it : new \IteratorIterator($it);
        }
        if ($payload instanceof \Iterator) {
            return $payload;
        }

        return new \ArrayIterator(is_array($payload) ? $payload : []);
    }

    public function count(): int
    {
        $payload = $this->payload();

        if ($payload instanceof \Countable) {
            return $payload->count();
        }

        return is_array($payload) ? count($payload) : 0;
    }

    public function jsonSerialize(): mixed
    {
        return $this->payload();
    }

    /**
     * The bare update value, unwrapped, use when you want the payload
     * explicitly regardless of the proxy.
     */
    public function value(): mixed
    {
        return $this->payload();
    }
}
