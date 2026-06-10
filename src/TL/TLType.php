<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

/**
 * TL Type representation.
 * 
 * Represents a type from the TL schema that can have multiple constructors.
 * Example: User can be constructed by user, userEmpty, etc.
 */
final class TLType
{
    /**
     * Type name.
     */
    private string $name;

    /**
     * Namespace.
     */
    private string $namespace;

    /**
     * Constructors for this type.
     * 
     * @var TLConstructor[]
     */
    private array $constructors;

    /**
     * Create a new TL type.
     *
     * @param string $name Type name
     * @param string $namespace Namespace
     * @param TLConstructor[] $constructors Constructors
     */
    public function __construct(
        string $name,
        string $namespace = '',
        array $constructors = []
    ) {
        $this->name = $name;
        $this->namespace = $namespace;
        $this->constructors = $constructors;
    }

    /**
     * Get type name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get full name with namespace.
     */
    public function getFullName(): string
    {
        if (!empty($this->namespace)) {
            return $this->namespace . '.' . $this->name;
        }
        return $this->name;
    }

    /**
     * Get namespace.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get constructors.
     *
     * @return TLConstructor[]
     */
    public function getConstructors(): array
    {
        return $this->constructors;
    }

    /**
     * Get constructor by name.
     */
    public function getConstructor(string $name): ?TLConstructor
    {
        foreach ($this->constructors as $constructor) {
            if ($constructor->getName() === $name || $constructor->getFullName() === $name) {
                return $constructor;
            }
        }
        return null;
    }

    /**
     * Get constructor by ID.
     */
    public function getConstructorById(int $id): ?TLConstructor
    {
        foreach ($this->constructors as $constructor) {
            if ($constructor->getId() === $id) {
                return $constructor;
            }
        }
        return null;
    }

    /**
     * Check if has multiple constructors.
     */
    public function hasMultipleConstructors(): bool
    {
        return count($this->constructors) > 1;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'constructors' => array_map(fn($c) => $c->toArray(), $this->constructors),
        ];
    }
}
