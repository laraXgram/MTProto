<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Encryption\Encrypter;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Console\Concerns\ManagesSessionArtifacts;
use LaraGram\MTProto\Store\EncryptedStore;

use function LaraGram\Console\Prompts\password;
use function LaraGram\Console\Prompts\select;

class SessionEncryptCommand extends Command
{
    use ManagesSessionArtifacts;

    protected $signature = 'session:encrypt
        {--session= : Session name (default: every session in the directory)}
        {--key= : The session key (default: CLIENT_SESSION_KEY, else APP_KEY; generated when none)}
        {--path= : Sessions directory (default: mtproto.session.path)}';

    protected $description = 'Encrypt MTProto session files (auth keys, peer access hashes, update state) in place';

    public function handle(): int
    {
        $key = $this->configuredKey();
        $generated = false;

        if ($key === null && $this->input->isInteractive()) {
            $choice = select(
                label: 'No session key is configured. What key would you like to use?',
                options: ['generate' => 'Generate a random key', 'ask' => 'Provide a key'],
                default: 'generate',
            );
            $key = $choice === 'ask' ? password('What is the session key?') : null;
        }

        if ($key === null || $key === '') {
            $key = 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));
            $generated = true;
        }

        $encrypter = EncryptedStore::encrypter($key);

        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();
        $directory = rtrim($this->resolveDirectory(), '/');
        $sessions = $this->targetSessions($directory);

        if ($sessions === []) {
            $this->components->warn('No sessions found to encrypt.');
            return self::SUCCESS;
        }

        $encrypted = 0;
        foreach ($sessions as $session) {
            foreach ($this->sensitiveExtensions() as $ext) {
                $path = "{$directory}/{$session}{$ext}";

                // A file left by the old side-car format: migrate it with the same key.
                if (!$files->exists($path) && $files->exists("{$path}.encrypted")) {
                    try {
                        $files->replace($path, $encrypter->decryptString($files->get("{$path}.encrypted")), 0600);
                        $files->delete("{$path}.encrypted");
                    } catch (\Throwable) {
                        $this->components->warn("Skipped {$session}{$ext}.encrypted - it was encrypted with another key (run session:decrypt with that key first).");
                        continue;
                    }
                }

                if (!$files->exists($path)) {
                    continue;
                }

                $content = $files->get($path);
                if (str_starts_with($content, EncryptedStore::PREFIX)) {
                    continue; // already encrypted
                }

                $files->replace($path, EncryptedStore::PREFIX . $encrypter->encryptString($content), 0600);
                $this->components->twoColumnDetail("{$session}{$ext}", '<fg=green;options=bold>ENCRYPTED</>');
                $encrypted++;
            }
        }

        $this->newLine();

        if ($encrypted === 0) {
            $this->components->info('Nothing to do: the sessions are already encrypted.');
            return self::SUCCESS;
        }

        $this->components->info("Encrypted {$encrypted} session file(s). Enable encryption so the client can read them:");
        $this->components->bulletList(array_filter([
            'CLIENT_SESSION_ENCRYPT=true',
            $generated || $this->option('key') ? "CLIENT_SESSION_KEY={$key}" : null,
        ]));

        if ($generated) {
            $this->components->warn('Store this key securely - without it the encrypted sessions cannot be recovered.');
        }

        return self::SUCCESS;
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

        return array_values(array_unique(array_merge(
            $this->discoverSessions($directory, '.session'),
            $this->discoverSessions($directory, '.session.encrypted'),
        )));
    }
}
