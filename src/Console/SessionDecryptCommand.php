<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Encryption\Encrypter;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Console\Concerns\ManagesSessionArtifacts;

use function LaraGram\Console\Prompts\password;

class SessionDecryptCommand extends Command
{
    use ManagesSessionArtifacts;

    protected $signature = 'session:decrypt
        {--session= : Session name (default: every encrypted session in the directory)}
        {--key= : The encryption key (required)}
        {--cipher= : The encryption cipher (default AES-256-CBC)}
        {--path= : Sessions directory (default: storage/mtproto/sessions)}
        {--prune : Delete the .encrypted files after decrypting}
        {--force : Overwrite existing plaintext files}';

    protected $description = 'Decrypt MTProto session files encrypted with session:encrypt';

    public function handle(): int
    {
        $cipher = (string) ($this->option('cipher') ?: 'AES-256-CBC');
        $key = $this->option('key');

        if (!$key && $this->input->isInteractive()) {
            $key = password('What is the encryption key?');
        }

        if (!$key) {
            $this->components->error('An encryption key is required to decrypt (use --key).');
            return 1;
        }

        try {
            $encrypter = new Encrypter($this->parseKey((string) $key), $cipher);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return 1;
        }

        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();
        $directory = $this->resolveDirectory();

        $sessions = $this->targetSessions($directory);
        if ($sessions === []) {
            $this->components->warn('No encrypted sessions found to decrypt.');
            return 0;
        }

        $decrypted = 0;

        foreach ($sessions as $session) {
            foreach ($this->sensitiveExtensions() as $ext) {
                $source = rtrim($directory, '/') . '/' . $session . $ext . '.encrypted';
                $target = rtrim($directory, '/') . '/' . $session . $ext;

                if (!$files->exists($source)) {
                    continue;
                }

                if ($files->exists($target) && !$this->option('force')) {
                    $this->components->warn("Skipped {$session}{$ext} - plaintext already exists (use --force).");
                    continue;
                }

                try {
                    $plain = $encrypter->decryptString($files->get($source));
                    $files->replace($target, $plain, 0600);
                } catch (\Throwable $e) {
                    $this->components->error(
                        "Failed to decrypt {$session}{$ext} - wrong key or cipher? (" . $e->getMessage() . ')'
                    );
                    return 1;
                }

                if ($this->option('prune')) {
                    $files->delete($source);
                }

                $decrypted++;
            }
        }

        if ($decrypted === 0) {
            $this->components->warn('Nothing was decrypted.');
            return 0;
        }

        $this->components->info('Session artifacts successfully decrypted.');
        $this->components->twoColumnDetail('Sessions', implode(', ', $sessions));
        $this->components->twoColumnDetail('Files decrypted', (string) $decrypted);
        $this->newLine();

        return 0;
    }

    /**
     * @return list<string>
     */
    private function targetSessions(string $directory): array
    {
        $session = (string) $this->option('session');
        if ($session !== '') {
            return [$session];
        }

        // Discover from encrypted .session files, stripping the compound suffix.
        return $this->discoverSessions($directory, '.session.encrypted');
    }
}
