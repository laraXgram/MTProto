<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL\Compiler;

/**
 * Receives progress from {@see SchemaCompiler} so the same compilation can be
 * shown by a Commander command, the composer hook script, or nothing at all.
 */
interface CompilerReporter
{
    /**
     * Run one compilation step and report its outcome.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function step(string $description, callable $callback): mixed;

    /**
     * Report a labelled value (a count, a path, a layer...).
     */
    public function detail(string $label, string $value): void;

    /**
     * Report something the user should act on.
     */
    public function warn(string $message): void;

    /**
     * Report verbose-only information (e.g. flat shortcut collisions).
     */
    public function note(string $message): void;
}
