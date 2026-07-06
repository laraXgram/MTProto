<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Session\Import\MadelineSource;
use LaraGram\MTProto\Session\Import\PyrogramSource;
use LaraGram\MTProto\Session\Import\SessionImporter;
use LaraGram\MTProto\Session\Import\TelethonSource;

use function LaraGram\Console\Prompts\text;

class SessionImportCommand extends Command
{
    protected $signature = 'session:import
        {input : Session string, or path to a Pyrogram/Telethon .session file or MadelineProto session folder}
        {--from=auto : Source format: pyrogram, telethon, madeline or auto}
        {--session= : Target LaraGram session name (prompted if omitted)}
        {--dc= : Home datacenter id (used to disambiguate MadelineProto sessions)}
        {--path= : Sessions directory (default: storage/mtproto/sessions)}
        {--force : Overwrite an existing session that already holds an auth key}
        {--dry-run : Decode and report the session without writing anything}';

    protected $description = 'Import a Pyrogram, Telethon or MadelineProto session into LaraGram format';

    public function handle(): int
    {
        $input = (string) $this->argument('input');
        $format = strtolower((string) $this->option('from')) ?: 'auto';
        $dc = $this->option('dc') !== null ? (int) $this->option('dc') : null;

        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();

        $madeline = new MadelineSource();
        $madeline->preferDc($dc);

        $importer = new SessionImporter($files, [
            new PyrogramSource(),
            new TelethonSource(),
            $madeline,
        ]);

        if (!in_array($format, ['auto', 'pyrogram', 'telethon', 'madeline'], true)) {
            $this->components->error("Unknown --from format '{$format}'. Use pyrogram, telethon, madeline or auto.");
            return 1;
        }

        // Dry run: decode and report only.
        if ($this->option('dry-run')) {
            try {
                $foreign = $importer->decode($format, $input);
            } catch (\Throwable $e) {
                $this->components->error($e->getMessage());
                return 1;
            }

            $this->report($foreign, null);
            $this->components->info('Dry run - nothing was written.');
            return 0;
        }

        $session = (string) $this->option('session');
        if ($session === '') {
            $session = trim((string) text(
                label: 'Target LaraGram session name',
                placeholder: 'main',
                default: 'main',
            ));
        }
        if ($session === '') {
            $this->components->error('A target session name is required.');
            return 1;
        }

        $directory = $this->resolveDirectory();

        try {
            $foreign = $importer->import(
                format: $format,
                input: $input,
                name: $session,
                directory: $directory,
                overwrite: (bool) $this->option('force'),
            );
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return 1;
        }

        $this->components->info("Session imported to '{$session}'.");
        $this->report($foreign, $directory . '/' . $session . '.session');

        if ($foreign->isBot) {
            $this->components->warn('Imported a bot session. Bot auth keys are tied to the bot; keep it single-instance.');
        }

        $this->components->bulletList([
            "Run: php laragram client:start --session={$session}",
        ]);

        return 0;
    }

    private function report($foreign, ?string $path): void
    {
        $this->components->twoColumnDetail('Source library', $foreign->source);
        $this->components->twoColumnDetail('Datacenter', (string) $foreign->dcId);
        $this->components->twoColumnDetail('Account id', $foreign->userId !== null ? (string) $foreign->userId : 'unknown');
        $this->components->twoColumnDetail('Type', $foreign->isBot ? 'bot' : 'user');
        $this->components->twoColumnDetail('Test mode', $foreign->testMode ? 'yes' : 'no');
        $this->components->twoColumnDetail('Peers imported', (string) $foreign->peerCount());

        if ($path !== null) {
            $this->components->twoColumnDetail('Session file', $path);
        }
    }

    private function resolveDirectory(): string
    {
        $path = (string) ($this->option('path') ?: '');

        if ($path === '') {
            $path = storage_path('mtproto/sessions');
        } elseif (!str_starts_with($path, '/')) {
            $path = base_path(ltrim($path, './'));
        }

        return $path;
    }
}
