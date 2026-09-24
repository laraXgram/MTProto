<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL\Compiler;

use LaraGram\MTProto\TL\TLMethod;

/**
 * TL type -> PHP type/PHPDoc mapping used by the schema compiler.
 */
final class TypeMap
{
    /**
     * TL primitives and the PHP scalar each one maps to.
     */
    private const PRIMITIVES = [
        'int' => 'int',
        'int53' => 'int',
        'long' => 'int',
        'int128' => 'string',
        'int256' => 'string',
        'double' => 'float',
        'string' => 'string',
        'bytes' => 'string',
        'Bool' => 'bool',
        'true' => 'bool',
        '#' => 'int',
    ];

    /**
     * Generic/boxed types with no concrete PHP shape.
     */
    private const GENERIC = ['X', '!X', 'Object'];

    /**
     * Complex input types the ParamPreprocessor also accepts in a simplified form.
     */
    private const SIMPLIFIED_INPUTS = [
        'InputPeer' => 'array|int|string',
        'InputUser' => 'array|int|string',
        'InputChannel' => 'array|int|string',
        'InputDialogPeer' => 'array|int|string',
        'InputMessage' => 'array|int',
        'TextWithEntities' => 'array|string',
        'InputCheckPasswordSRP' => 'array|string',
        'DataJSON' => 'mixed',
    ];

    /**
     * PHP parameter type for a TL parameter type.
     */
    public static function php(string $type, bool $isVector = false): string
    {
        if ($isVector) {
            return 'array';
        }

        return self::PRIMITIVES[$type] ?? 'array';
    }

    /**
     * PHPDoc type for a TL parameter type.
     */
    public static function doc(string $type, bool $isVector = false): string
    {
        $doc = self::PRIMITIVES[$type] ?? (in_array($type, self::GENERIC, true) ? 'mixed' : 'array');

        return $isVector ? $doc . '[]' : $doc;
    }

    /**
     * The simplified PHP type a complex input accepts, or null when none.
     */
    public static function simplified(string $type): ?string
    {
        return self::SIMPLIFIED_INPUTS[$type] ?? null;
    }

    /**
     * PHPDoc type of a Type-class property, resolving complex types to the
     * generated Type class short name (same namespace).
     */
    public static function property(string $type, bool $isVector = false): string
    {
        $doc = self::PRIMITIVES[$type] ?? (in_array($type, self::GENERIC, true) ? 'mixed' : self::className($type));

        return $isVector ? $doc . '[]' : $doc;
    }

    /**
     * Generated Type class short name for a TL type ("messages.Messages" -> "MessagesMessages").
     */
    public static function className(string $type): string
    {
        return implode('', array_map('ucfirst', explode('.', $type)));
    }

    /**
     * Whether the TL type is a primitive (no Type class).
     */
    public static function isPrimitive(string $type): bool
    {
        return isset(self::PRIMITIVES[$type]) && $type !== '#';
    }

    /**
     * Split a `Vector<T>` return type into [isVector, innerType].
     *
     * @return array{0: bool, 1: string}
     */
    public static function unwrapVector(string $type): array
    {
        if (preg_match('/^[Vv]ector<(.+)>$/', $type, $matches)) {
            return [true, $matches[1]];
        }

        return [false, $type];
    }

    /**
     * PHPDoc return type referencing generated Type classes.
     */
    public static function returnDoc(string $type, string $namespace): string
    {
        if (in_array($type, self::GENERIC, true)) {
            return 'array';
        }

        [$isVector, $inner] = self::unwrapVector($type);

        if (isset(self::PRIMITIVES[$inner])) {
            $doc = self::PRIMITIVES[$inner];
        } else {
            $doc = '\\' . $namespace . '\\Types\\' . self::className($inner);
        }

        return $isVector ? $doc . '[]' : $doc;
    }

    /**
     * FQCN of the Type class a method returns, or null for primitives and generics.
     */
    public static function returnClass(string $type, string $namespace): ?string
    {
        if (in_array($type, self::GENERIC, true)) {
            return null;
        }

        [, $inner] = self::unwrapVector($type);

        if (isset(self::PRIMITIVES[$inner])) {
            return null;
        }

        return $namespace . '\\Types\\' . self::className($inner);
    }

    /**
     * PHP return type hint for a method signature.
     */
    public static function returnHint(string $type): string
    {
        if (isset(self::PRIMITIVES[$type]) && $type !== '#') {
            return self::PRIMITIVES[$type];
        }

        [$isVector] = self::unwrapVector($type);

        return $isVector ? 'array' : 'TLObject';
    }

    /**
     * Whether a method carries both `entities` and a `message`/`caption` text
     * field - the shape ParamPreprocessor::applyParseMode() fills from `parse_mode`.
     */
    public static function supportsParseMode(TLMethod $method): bool
    {
        $hasEntities = false;
        $hasText = false;

        foreach ($method->getParams() as $param) {
            $name = $param->getName();
            $hasEntities = $hasEntities || $name === 'entities';
            $hasText = $hasText || $name === 'message' || $name === 'caption';
        }

        return $hasEntities && $hasText;
    }
}
