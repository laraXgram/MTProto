<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL\Compiler;

/**
 * Summary of one {@see SchemaCompiler::compile()} run.
 */
final class CompileResult
{
    /**
     * @param array<string, list<string>> $collisions shortcut => [winner, ...shadowed]
     * @param list<string> $warnings
     * @param array{added: list<string>, removed: list<string>, changed: list<string>}|null $diff
     *        constructor/method changes against the previously compiled schema
     */
    public function __construct(
        public readonly int $layer,
        public readonly ?int $previousLayer,
        public readonly string $schemaDirectory,
        public readonly bool $published,
        public readonly string $outputDirectory,
        public readonly string $hash,
        public readonly int $methods,
        public readonly int $constructors,
        public readonly int $namespaces,
        public readonly int $typeClasses,
        public readonly int $shortcuts,
        public readonly array $collisions,
        public readonly array $warnings,
        public readonly ?array $diff,
        public readonly bool $dryRun,
        public readonly float $duration,
    ) {
    }

    /**
     * Whether the compiled schema differs from the previous compilation.
     */
    public function changed(): bool
    {
        return $this->diff === null
            || $this->diff['added'] !== []
            || $this->diff['removed'] !== []
            || $this->diff['changed'] !== [];
    }
}
