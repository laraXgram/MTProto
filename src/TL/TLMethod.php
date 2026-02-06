<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

/**
 * TL Method representation.
 * 
 * Represents an RPC method from the TL schema.
 * Example: auth.sendCode#d16ff372 ... = auth.SentCode;
 */
final class TLMethod
{
    /**
     * Method name.
     */
    private string $name;

    /**
     * Method ID (CRC32).
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
     * Namespace (e.g., 'auth', 'messages').
     */
    private ?string $namespace;

    /**
     * Create a new TL method.
     *
     * @param string $name Method name
     * @param int $id Method ID
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
     * Get method name.
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
     * Get method ID.
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
     * Get required parameters only.
     *
     * @return TLParameter[]
     */
    public function getRequiredParams(): array
    {
        return array_filter($this->params, fn($p) => !$p->isOptional());
    }

    /**
     * Get optional parameters only.
     *
     * @return TLParameter[]
     */
    public function getOptionalParams(): array
    {
        return array_filter($this->params, fn($p) => $p->isOptional());
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
