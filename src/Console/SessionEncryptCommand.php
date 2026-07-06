<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Encryption\Encrypter;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Console\Concerns\ManagesSessionArtifacts;

use function LaraGram\Console\Prompts\password;
use function LaraGram\Console\Prompts\select;

class SessionEncryptCommand extends Command
{
    use ManagesSessionArtifacts;

    protected $signature = 'session:encrypt
        {--session= : Session name (default: every session in the directory)}
        {--key= : The encryption key (generated when omitted)}
        {--cipher= : The encryption cipher (default AES-256-CBC)}
        {--path= : Sessions directory (default: storage/mtproto/sessions)}
        {--prune : Delete the plaintext originals after encrypting}
        {--force : Overwrite existing .encrypted files}';

    protected $description = 'Encrypt MTProto session files (auth keys + peer access hashes) at rest';

    public function handle(): int
    {
        $cipher = (string) ($this->option('cipher') ?: 'AES-256-CBC');
        $key = $this->option('key');

        if (!$key && $this->input->isInteractive()) {
            $choice = select(
                label: 'What encryption key would you like to use?',
                options: [
                    'generate' => 'Generate a random encryption key',
                    'ask' => 'Provide an encryption key',
                ],
                default: 'generate',
            );

            if ($choice === 'ask') {
                $key = password('What is the encryption key?');
            }
        }

        $keyPassed = $key !== null && $key !== '';

        try {
            $rawKey = $keyPassed ? $this->parseKey((string) $key) : Encrypter::generateKey($cipher);
            $encrypter = new Encrypter($rawKey, $cipher);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return 1;
        }

        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();
        $directory = $this->resolveDirectory();

        $sessions = $this->targetSessions($directory);
        if ($sessions === []) {
            $this->components->warn('No sessions found to encrypt.');
            return 0;
        }

        $encrypted = 0;
        $skipped = 0;

        foreach ($sessions as $session) {
            foreach ($this->sensitiveExtensions() as $ext) {
                $source = rtrim($directory, '/') . '/' . $session . $ext;
                $target = $source . '.encrypted';

                if (!$files->exists($source)) {
                    continue;
                }

                if ($files->exists($target) && !$this->option('force')) {
                    $this->components->warn("Skipped {$session}{$ext} - encrypted file already exists (use --force).");
                    $skipped++;
                    continue;
                }

                try {
                    $files->replace($target, $encrypter->encryptString($files->get($source)), 0600);
                } catch (\Throwable $e) {
                    $this->components->error("Failed to encrypt {$session}{$ext}: " . $e->getMessage());
                    return 1;
                }

                if ($this->option('prune')) {
                    $files->delete($source);
                }

                $encrypted++;
            }
        }

        if ($encrypted === 0) {
            $this->components->warn('Nothing was encrypted' . ($skipped ? ' (all targets already encrypted).' : '.'));
            return 0;
        }

        $this->components->info('Session artifacts successfully encrypted.');
        $this->components->twoColumnDetail('Sessions', implode(', ', $sessions));
        $this->components->twoColumnDetail('Files encrypted', (string) $encrypted);
        $this->components->twoColumnDetail('Key', $keyPassed ? (string) $key : 'base64:' . base64_encode($rawKey));
        $this->components->twoColumnDetail('Cipher', $cipher);
        $this->newLine();
        $this->components->warn('Store this key securely - without it the encrypted sessions cannot be recovered.');

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

        return $this->discoverSessions($directory, '.session');
    }
}
