<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL\Compiler;

final class NullReporter implements CompilerReporter
{
    public function step(string $description, callable $callback): mixed
    {
        return $callback();
    }

    public function detail(string $label, string $value): void
    {
    }

    public function warn(string $message): void
    {
    }

    public function note(string $message): void
    {
    }
}
