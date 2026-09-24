<?php

declare(strict_types=1);

namespace LaraGram\MTProto\TL\Compiler;

/**
 * Plain-text reporter for contexts without Commander (the composer hook script).
 */
final class PlainReporter implements CompilerReporter
{
    /**
     * @param resource|null $stream
     */
    public function __construct(
        private bool $verbose = false,
        private mixed $stream = null,
    ) {
        $this->stream ??= STDOUT;
    }

    public function step(string $description, callable $callback): mixed
    {
        $start = microtime(true);

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->write("  ✗ {$description} - FAILED\n");
            throw $e;
        }

        $this->write(sprintf("  ✓ %s (%.0fms)\n", $description, (microtime(true) - $start) * 1000));

        return $result;
    }

    public function detail(string $label, string $value): void
    {
        $this->write("    {$label}: {$value}\n");
    }

    public function warn(string $message): void
    {
        $this->write("  ⚠ {$message}\n");
    }

    public function note(string $message): void
    {
        if ($this->verbose) {
            $this->write("    · {$message}\n");
        }
    }

    private function write(string $text): void
    {
        fwrite($this->stream, $text);
    }
}
