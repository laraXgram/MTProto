<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * TL Serializer
 * 
 * Handles serialization and deserialization of TL objects
 * according to the MTProto binary format.
 * 
 * Binary Format:
 * - int (32-bit little-endian)
 * - long (64-bit little-endian)
 * - double (64-bit IEEE 754)
 * - string/bytes (length-prefixed with padding)
 * - vector (0x1cb5c415 + count + elements)
 * - object (constructor_id + fields)
 */
final class TLSerializer
{
    private const VECTOR_CONSTRUCTOR = 0x1cb5c415;
    private const BOOL_TRUE = 0x997275b5;
    private const BOOL_FALSE = 0xbc799737;
    private const NULL_CONSTRUCTOR = 0x56730bcc;

    /** @var string Binary buffer */
    private string $buffer = '';

    /** @var int Read position */
    private int $position = 0;

    private TLParser $parser;

    public function __construct(?TLParser $parser = null)
    {
        $this->parser = $parser ?? new TLParser();
    }

    /**
     * Serialize a TL object
     * 
     * @param array{_: string, ...} $object Object with constructor name in '_' key
     * @param bool $boxed Whether to include constructor ID
     */
    public function serialize(array $object, bool $boxed = true): string
    {
        $this->buffer = '';
        $this->serializeObject($object, $boxed);
        return $this->buffer;
    }

    /**
     * Deserialize binary data to TL object
     * 
     * @return array Deserialized object
     */
    public function deserialize(string $data): array
    {
        $this->buffer = $data;
        $this->position = 0;
        return $this->deserializeObject();
    }

    /**
     * Serialize an object
     */
    private function serializeObject(array $object, bool $boxed = true): void
    {
        $constructorName = $object['_'] ?? throw new MTProtoException('Missing constructor name');

        // Get constructor from schema
        $constructor = $this->parser->getConstructor($constructorName) 
            ?? $this->parser->getMethod($constructorName);

        if ($constructor) {
            if ($boxed) {
                $this->writeInt($constructor->getId());
            }

            // First pass: calculate flags if not provided
            $flags = [];
            foreach ($constructor->getParams() as $param) {
                if ($param->getType() === '#') {
                    // This is a flags field - calculate it if not provided
                    $flagName = $param->getName();
                    if (!isset($object[$flagName])) {
                        $object[$flagName] = 0;
                    }
                    $flags[$flagName] = &$object[$flagName];
                }
            }
            
            // Calculate flag bits based on which optional parameters are present
            foreach ($constructor->getParams() as $param) {
                if ($param->isOptional()) {
                    $flagField = $param->getFlagField();
                    $flagIndex = $param->getFlagIndex();
                    
                    if (isset($flags[$flagField])) {
                        if (isset($object[$param->getName()])) {
                            // Set the bit if value is provided
                            $flags[$flagField] |= (1 << $flagIndex);
                        } else {
                            // Clear the bit if value is not provided
                            $flags[$flagField] &= ~(1 << $flagIndex);
                        }
                    }
                }
            }

            // Second pass: serialize
            foreach ($constructor->getParams() as $param) {
                if ($param->isOptional()) {
                    // Check if value exists
                    if (!isset($object[$param->getName()]) && $param->getType() !== 'true') {
                        continue;
                    }
                }

                $value = $object[$param->getName()] ?? null;
                $this->serializeValue($value, $param->getType(), $param->isVector(), $param->isBare());
            }
        } else {
            // Unknown constructor - serialize as raw
            if ($boxed && isset($object['_id'])) {
                $this->writeInt($object['_id']);
            }
        }
    }

    /**
     * Serialize a value based on type
     */
    private function serializeValue(mixed $value, string $type, bool $isVector, bool $isBare): void
    {
        if ($isVector) {
            $this->serializeVector($value, $type, $isBare);
            return;
        }

        match ($type) {
            'int', 'Int' => $this->writeInt((int) $value),
            'long', 'Long' => $this->writeLong((int) $value),
            'int128' => $this->writeBytes($value, 16),
            'int256' => $this->writeBytes($value, 32),
            'double', 'Double' => $this->writeDouble((float) $value),
            'string', 'String' => $this->writeString((string) $value),
            'bytes', 'Bytes' => $this->writeString((string) $value),
            'Bool' => $this->writeBool((bool) $value),
            'true' => null, // No serialization needed for true flag
            '#' => $this->writeInt((int) $value), // Flags
            default => is_array($value) 
                ? $this->serializeObject($value, !$isBare)
                : throw new MTProtoException("Cannot serialize type: {$type}"),
        };
    }

    /**
     * Serialize a vector
     */
    private function serializeVector(array $values, string $elementType, bool $isBare): void
    {
        if (!$isBare) {
            $this->writeInt(self::VECTOR_CONSTRUCTOR);
        }
        
        $this->writeInt(count($values));
        
        foreach ($values as $value) {
            $this->serializeValue($value, $elementType, false, false);
        }
    }

    /**
     * Deserialize an object
     */
    private function deserializeObject(): array
    {
        $constructorId = $this->readUint();

        // Handle special constructors
        if ($constructorId === self::BOOL_TRUE) {
            return ['_' => 'boolTrue', 'value' => true];
        }
        if ($constructorId === self::BOOL_FALSE) {
            return ['_' => 'boolFalse', 'value' => false];
        }
        if ($constructorId === self::VECTOR_CONSTRUCTOR) {
            // Vector constructor already consumed, just read elements
            return $this->deserializeVectorElements(null);
        }
        if ($constructorId === self::NULL_CONSTRUCTOR) {
            return ['_' => 'null'];
        }

        // Get constructor from schema
        $constructor = $this->parser->getConstructorById($constructorId)
            ?? $this->parser->getMethodById($constructorId);

        if (!$constructor) {
            // Gracefully handle unknown constructors instead of crashing.
            // Skip remaining data for this object - we cannot know its layout.
            $hex = sprintf('0x%08x', $constructorId);
            error_log("[MTProto] Unknown constructor ID: {$hex} at position {$this->position}, skipping");
            // Consume all remaining data since we don't know the layout
            $remaining = strlen($this->buffer) - $this->position;
            if ($remaining > 0) {
                $this->position = strlen($this->buffer);
            }
            return ['_' => 'unknown', '_constructor_id' => $hex];
        }

        $result = ['_' => $constructor->getFullName()];
        $flags = [];

        foreach ($constructor->getParams() as $param) {
            // Handle flags field
            if ($param->getType() === '#') {
                $flags[$param->getName()] = $this->readInt();
                $result[$param->getName()] = $flags[$param->getName()];
                continue;
            }

            // Check optional field
            if ($param->isOptional()) {
                $flagValue = $flags[$param->getFlagField()] ?? 0;
                if (!($flagValue & (1 << $param->getFlagIndex()))) {
                    continue;
                }
                
                // Handle true type
                if ($param->getType() === 'true') {
                    $result[$param->getName()] = true;
                    continue;
                }
            }

            $result[$param->getName()] = $this->deserializeValue(
                $param->getType(),
                $param->isVector(),
                $param->isBare()
            );
        }

        return $result;
    }

    /**
     * Deserialize a value based on type
     */
    private function deserializeValue(string $type, bool $isVector, bool $isBare): mixed
    {
        if ($isVector) {
            // For boxed vectors, read and verify the vector constructor ID first
            if (!$isBare) {
                $vectorId = $this->readUint();
                if ($vectorId !== self::VECTOR_CONSTRUCTOR) {
                    throw new MTProtoException(sprintf(
                        'Expected vector constructor 0x1cb5c415, got 0x%08x',
                        $vectorId
                    ));
                }
            }
            return $this->deserializeVectorElements($type);
        }

        return match ($type) {
            'int', 'Int', '#' => $this->readInt(),
            'long', 'Long' => $this->readLong(),
            'int128' => $this->readBytes(16),
            'int256' => $this->readBytes(32),
            'double', 'Double' => $this->readDouble(),
            'string', 'String', 'bytes', 'Bytes' => $this->readString(),
            'Bool' => $this->readBool(),
            'Object', 'X' => $this->deserializeObject(),
            default => $this->deserializeObject(),
        };
    }

    /**
     * Deserialize a bare vector (without constructor ID)
     * This is kept for backwards compatibility but shouldn't be called directly anymore.
     */
    private function deserializeVector(?string $elementType = null): array
    {
        return $this->deserializeVectorElements($elementType);
    }
    
    /**
     * Deserialize vector elements (after constructor has been handled)
     */
    private function deserializeVectorElements(?string $elementType = null): array
    {
        $count = $this->readInt();
        $result = ['_' => 'vector', 'items' => []];

        for ($i = 0; $i < $count; $i++) {
            if ($elementType) {
                $result['items'][] = $this->deserializeValue($elementType, false, false);
            } else {
                $result['items'][] = $this->deserializeObject();
            }
        }

        return $result['items'];
    }

    // ==================== Primitive Writers ====================

    public function writeInt(int $value): void
    {
        $this->buffer .= pack('V', $value);
    }

    public function writeLong(int $value): void
    {
        $this->buffer .= pack('P', $value);
    }

    public function writeDouble(float $value): void
    {
        $this->buffer .= pack('e', $value);
    }

    public function writeString(string $value): void
    {
        $length = strlen($value);

        if ($length < 254) {
            $this->buffer .= chr($length);
            $this->buffer .= $value;
            $padding = (4 - (($length + 1) % 4)) % 4;
        } else {
            $this->buffer .= chr(254);
            $this->buffer .= substr(pack('V', $length), 0, 3);
            $this->buffer .= $value;
            $padding = (4 - ($length % 4)) % 4;
        }

        if ($padding > 0) {
            $this->buffer .= str_repeat("\x00", $padding);
        }
    }

    public function writeBool(bool $value): void
    {
        $this->writeInt($value ? self::BOOL_TRUE : self::BOOL_FALSE);
    }

    public function writeBytes(string $bytes, int $length): void
    {
        if (strlen($bytes) !== $length) {
            throw new MTProtoException("Expected {$length} bytes, got " . strlen($bytes));
        }
        $this->buffer .= $bytes;
    }

    // ==================== Primitive Readers ====================

    public function readInt(): int
    {
        $this->ensureBytes(4);
        $value = unpack('V', substr($this->buffer, $this->position, 4))[1];
        $this->position += 4;
        
        // Handle signed int
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        
        return $value;
    }

    public function readUint(): int
    {
        $this->ensureBytes(4);
        $value = unpack('V', substr($this->buffer, $this->position, 4))[1];
        $this->position += 4;
        return $value;
    }

    public function readLong(): int
    {
        $this->ensureBytes(8);
        $value = unpack('P', substr($this->buffer, $this->position, 8))[1];
        $this->position += 8;
        return $value;
    }

    public function readDouble(): float
    {
        $this->ensureBytes(8);
        $value = unpack('e', substr($this->buffer, $this->position, 8))[1];
        $this->position += 8;
        return $value;
    }

    public function readString(): string
    {
        $this->ensureBytes(1);
        $firstByte = ord($this->buffer[$this->position]);
        $this->position++;

        if ($firstByte === 254) {
            $this->ensureBytes(3);
            $lengthBytes = substr($this->buffer, $this->position, 3) . "\x00";
            $length = unpack('V', $lengthBytes)[1];
            $this->position += 3;
            $headerLen = 4;
        } else {
            $length = $firstByte;
            $headerLen = 1;
        }

        $this->ensureBytes($length);
        $value = substr($this->buffer, $this->position, $length);
        $this->position += $length;

        // Skip padding
        $totalLen = $headerLen + $length;
        $padding = (4 - ($totalLen % 4)) % 4;
        $this->position += $padding;

        return $value;
    }

    public function readBool(): bool
    {
        $value = $this->readUint();
        return $value === self::BOOL_TRUE;
    }

    public function readBytes(int $length): string
    {
        $this->ensureBytes($length);
        $value = substr($this->buffer, $this->position, $length);
        $this->position += $length;
        return $value;
    }

    private function ensureBytes(int $count): void
    {
        if ($this->position + $count > strlen($this->buffer)) {
            throw new MTProtoException(sprintf(
                'Unexpected end of buffer at position %d, need %d bytes, have %d',
                $this->position,
                $count,
                strlen($this->buffer) - $this->position
            ));
        }
    }

    /**
     * Get current buffer
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Get remaining bytes in buffer
     */
    public function getRemainingBytes(): string
    {
        return substr($this->buffer, $this->position);
    }

    /**
     * Check if there are more bytes to read
     */
    public function hasMore(): bool
    {
        return $this->position < strlen($this->buffer);
    }
}
