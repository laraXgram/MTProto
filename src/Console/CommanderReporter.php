<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\OutputStyle;
use LaraGram\Console\View\Components\Factory;
use LaraGram\MTProto\TL\Compiler\CompilerReporter;

/**
 * Shows {@see \LaraGram\MTProto\TL\Compiler\SchemaCompiler} progress with the
 * Commander view components (task rows, two-column details, warnings).
 */
final class CommanderReporter implements CompilerReporter
{
    private bool $inStep = false;

    /** @var list<string> Warnings raised mid-step, shown once the task row is done. */
    private array $deferred = [];

    public function __construct(
        private readonly Factory $components,
        private readonly OutputStyle $output,
    ) {
    }

    public function step(string $description, callable $callback): mixed
    {
        $result = null;
        $this->inStep = true;

        try {
            $this->components->task($description, function () use ($callback, &$result): bool {
                $result = $callback();

                return true;
            });
        } finally {
            $this->inStep = false;
            $this->flush();
        }

        return $result;
    }

    public function detail(string $label, string $value): void
    {
        $this->components->twoColumnDetail($label, $value);
    }

    public function warn(string $message): void
    {
        if ($this->inStep) {
            $this->deferred[] = $message;
            return;
        }

        $this->components->warn($message);
    }

    public function note(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->components->twoColumnDetail("<fg=gray>{$message}</>");
        }
    }

    private function flush(): void
    {
        foreach ($this->deferred as $message) {
            $this->components->warn($message);
        }

        $this->deferred = [];
    }
}
