<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

/**
 * TL Constructor representation.
 * 
 * Represents a constructor from the TL schema.
 * Example: user#d23c81a6 flags:# id:long ... = User;
 */
final class TLConstructor
{
    /**
     * Constructor name.
     */
    private string $name;

    /**
     * Constructor ID (CRC32).
     */
    public int $id;

    /**
     * Result type.
     */
    public string $type;

    /**
     * Parameters.
     * 
     * @var TLParameter[]
     */
    public array $params;

    /**
     * Namespace (e.g., 'messages', 'users').
     */
    private ?string $namespace;

    /**
     * Create a new TL constructor.
     *
     * @param string $name Constructor name
     * @param int $id Constructor ID
     * @param string $type Result type
     * @param TLParameter[] $params Parameters
     * @param string|null $namespace Namespace
     */
    public function __construct(
        string $name,
        int $id,
        string $type,
        array $params = [],
        ?string $namespace = null
    ) {
        $this->name = $name;
        $this->id = $id;
        $this->type = $type;
        $this->params = $params;
        $this->namespace = $namespace;
    }

    /**
     * Get constructor name.
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
        if ($this->namespace !== null && $this->namespace !== '') {
            return $this->namespace . '.' . $this->name;
        }
        return $this->name;
    }

    /**
     * Get constructor ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get result type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get parameters.
     *
     * @return TLParameter[]
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get namespace.
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * Check if has specific parameter.
     */
    public function hasParam(string $name): bool
    {
        foreach ($this->params as $param) {
            if ($param->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get parameter by name.
     */
    public function getParam(string $name): ?TLParameter
    {
        foreach ($this->params as $param) {
            if ($param->getName() === $name) {
                return $param;
            }
        }
        return null;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'id' => $this->id,
            'type' => $this->type,
            'params' => array_map(fn($p) => $p->toArray(), $this->params),
            'namespace' => $this->namespace,
        ];
    }
}
