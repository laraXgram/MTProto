<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\TL\SchemaSource;

class ClientSchemaPublishCommand extends Command
{
    protected $signature = 'client:schema:publish
        {--path= : Target directory (default: config mtproto.schema.path)}
        {--force : Overwrite schema files that were already published}
        {--compile : Compile the published schema right away}';

    protected $description = 'Publish the MTProto TL schema files so they can be edited (e.g. to run your own layer)';

    public function handle(): int
    {
        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();

        $target = rtrim((string) ($this->option('path') ?: config('mtproto.schema.path') ?: resource_path('mtproto/schemas')), '/');
        $files->ensureDirectoryExists($target, 0755);

        $published = 0;
        foreach (SchemaSource::files(SchemaSource::packagePath()) as $name => $source) {
            $destination = "{$target}/{$name}";

            if ($files->exists($destination) && !$this->option('force')) {
                $this->components->twoColumnDetail($name, '<fg=yellow;options=bold>SKIPPED</> <fg=gray>(exists, use --force)</>');
                continue;
            }

            $files->copy($source, $destination);
            $this->components->twoColumnDetail($name, '<fg=green;options=bold>PUBLISHED</>');
            $published++;
        }

        $this->newLine();

        if ($published === 0) {
            $this->components->warn("Nothing published: the schema files already exist in [{$target}].");
        } else {
            $this->components->info(sprintf(
                'Published layer %s schema to [%s].',
                SchemaSource::detectLayer($target) ?? '?',
                $target,
            ));
        }

        if ($this->option('compile')) {
            return $this->call('client:compile', ['--schema' => $target]);
        }

        $this->components->bulletList([
            'Edit the .tl files (keep the <fg=yellow>// LAYER N</> marker in telegram_api.tl in sync).',
            'Run <fg=yellow>php laragram client:compile</> to rebuild the Generated classes.',
        ]);

        return self::SUCCESS;
    }
}
