<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

/**
 * TL Parameter representation.
 * 
 * Represents a parameter in a TL constructor or method.
 * Example: flags:# or id:long or user:flags.0?User
 */
final class TLParameter
{
    /**
     * Parameter name.
     */
    public string $name;

    /**
     * Parameter type.
     */
    public string $type;

    /**
     * Is this parameter optional (conditional)?
     */
    public bool $isOptional;

    /**
     * Flag index for conditional parameters.
     */
    public ?int $flagIndex;

    /**
     * Flag field name for conditional parameters.
     */
    public ?string $flagField;

    /**
     * Is this a vector type?
     */
    public bool $isVector;

    /**
     * Is this a bare type?
     */
    public bool $isBare;

    /**
     * Create a new TL parameter.
     *
     * @param string $name Parameter name
     * @param string $type Parameter type
     * @param bool $isOptional Is optional
     * @param int|null $flagIndex Flag bit index
     * @param string|null $flagField Flag field name
     * @param bool $isVector Is vector type
     * @param bool $isBare Is bare type
     */
    public function __construct(
        string $name,
        string $type,
        bool $isOptional = false,
        ?int $flagIndex = null,
        ?string $flagField = null,
        bool $isVector = false,
        bool $isBare = false
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->isOptional = $isOptional;
        $this->flagIndex = $flagIndex;
        $this->flagField = $flagField;
        $this->isVector = $isVector;
        $this->isBare = $isBare;
    }

    /**
     * Get parameter name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get parameter type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get inner type for vectors.
     */
    public function getInnerType(): string
    {
        if ($this->isVector && str_starts_with($this->type, 'Vector<')) {
            return substr($this->type, 7, -1);
        }
        return $this->type;
    }

    /**
     * Check if parameter is optional.
     */
    public function isOptional(): bool
    {
        return $this->isOptional;
    }

    /**
     * Get flag index.
     */
    public function getFlagIndex(): ?int
    {
        return $this->flagIndex;
    }

    /**
     * Get flag field name.
     */
    public function getFlagField(): ?string
    {
        return $this->flagField;
    }

    /**
     * Check if is vector type.
     */
    public function isVector(): bool
    {
        return $this->isVector;
    }

    /**
     * Check if is bare type.
     */
    public function isBare(): bool
    {
        return $this->isBare;
    }

    /**
     * Check if this is a flags field.
     */
    public function isFlags(): bool
    {
        return $this->type === '#' || $this->name === 'flags' || $this->name === 'flags2';
    }

    /**
     * Check if this is a primitive type.
     */
    public function isPrimitive(): bool
    {
        return in_array($this->type, [
            'int', 'long', 'double', 'string', 'bytes', 'int128', 'int256',
            'Bool', 'true', '#',
        ], true);
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'isOptional' => $this->isOptional,
            'flagIndex' => $this->flagIndex,
            'flagField' => $this->flagField,
            'isVector' => $this->isVector,
            'isBare' => $this->isBare,
        ];
    }
}
