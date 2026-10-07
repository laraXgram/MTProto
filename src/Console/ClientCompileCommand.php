<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\MTProto\TL\Compiler\CompileResult;
use LaraGram\MTProto\TL\Compiler\SchemaCompiler;
use LaraGram\MTProto\TL\SchemaSource;

class ClientCompileCommand extends Command
{
    protected $signature = 'client:compile
        {--schema= : Schema directory (default: the published schemas when present, else the package copy)}
        {--package : Compile the package schemas even when a published copy exists}
        {--layer= : API layer to compile for (default: the "// LAYER N" marker of the schema)}
        {--dry-run : Build and report without replacing the Generated classes}';

    protected $description = 'Compile the MTProto TL schema into the Generated method and type classes';

    public function handle(): int
    {
        $layer = $this->option('layer');
        if ($layer !== null && (!ctype_digit((string) $layer) || (int) $layer < 1)) {
            $this->components->error('The --layer option must be a positive integer.');
            return self::FAILURE;
        }

        $compiler = new SchemaCompiler(
            schemaDirectory: $this->schemaDirectory(),
            layer: $layer !== null ? (int) $layer : null,
        );

        $this->components->info(sprintf(
            'Compiling the %s TL schema from [%s].',
            SchemaSource::isPackage($compiler->schemaDirectory()) ? 'package' : 'published',
            $compiler->schemaDirectory(),
        ));

        try {
            $result = $compiler->compile(
                new CommanderReporter($this->components, $this->output),
                (bool) $this->option('dry-run'),
            );
        } catch (\Throwable $e) {
            $this->components->error('Compilation failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->report($result);

        return self::SUCCESS;
    }

    private function schemaDirectory(): ?string
    {
        if ($this->option('package')) {
            return SchemaSource::packagePath();
        }

        $path = $this->option('schema') ?: config('mtproto.schema.path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function report(CompileResult $result): void
    {
        $this->newLine();

        $layer = (string) $result->layer;
        if ($result->previousLayer !== null && $result->previousLayer !== $result->layer) {
            $layer = "<fg=gray>{$result->previousLayer} →</> <fg=green>{$result->layer}</>";
        }

        $this->components->twoColumnDetail('<fg=green;options=bold>Layer</>', $layer);
        $this->components->twoColumnDetail('Methods', "{$result->methods} in {$result->namespaces} namespaces");
        $this->components->twoColumnDetail('Constructors', "{$result->constructors} ({$result->typeClasses} type classes)");
        $this->components->twoColumnDetail('Flat shortcuts', $result->shortcuts . ' (' . count($result->collisions) . ' shadowed, see -v)');

        if ($result->diff !== null) {
            $this->components->twoColumnDetail('Changes', sprintf(
                '<fg=green>+%d</> <fg=red>-%d</> <fg=yellow>~%d</>',
                count($result->diff['added']),
                count($result->diff['removed']),
                count($result->diff['changed']),
            ));

            if ($this->output->isVerbose()) {
                $this->listChanges('Added', $result->diff['added'], 'green');
                $this->listChanges('Removed', $result->diff['removed'], 'red');
                $this->listChanges('Changed', $result->diff['changed'], 'yellow');
            }
        }

        $this->components->twoColumnDetail('Output', $result->outputDirectory);
        $this->components->twoColumnDetail('Duration', number_format($result->duration * 1000) . 'ms');
        $this->newLine();

        $configured = config('mtproto.layer');
        if ($configured !== null && $configured !== '' && (int) $configured !== $result->layer) {
            $this->components->warn(
                "config('mtproto.layer') forces layer {$configured}, but the schema is layer {$result->layer}. "
                . 'Set CLIENT_LAYER to null unless you really need to override it.'
            );
        }

        if ($result->dryRun) {
            $this->components->info('Dry run: the Generated classes were not changed.');
            return;
        }

        $this->components->info('Generated classes compiled. Restart running pumps (client:start, surge) to load them.');
    }

    /**
     * @param list<string> $names
     */
    private function listChanges(string $label, array $names, string $color): void
    {
        if ($names === []) {
            return;
        }

        $this->components->twoColumnDetail("<fg={$color}>{$label}</>");
        $this->components->bulletList(array_map(static fn (string $name): string => "<fg={$color}>{$name}</>", $names));
    }
}
