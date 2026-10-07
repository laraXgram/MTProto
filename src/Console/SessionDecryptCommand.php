<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Console\Concerns\ManagesSessionArtifacts;
use LaraGram\MTProto\Store\EncryptedStore;

use function LaraGram\Console\Prompts\password;

class SessionDecryptCommand extends Command
{
    use ManagesSessionArtifacts;

    protected $signature = 'session:decrypt
        {--session= : Session name (default: every session in the directory)}
        {--key= : The session key (default: CLIENT_SESSION_KEY, else APP_KEY)}
        {--path= : Sessions directory (default: mtproto.session.path)}';

    protected $description = 'Decrypt MTProto session files in place (also migrates the old .encrypted side-car files)';

    public function handle(): int
    {
        $key = $this->configuredKey();

        if ($key === null && $this->input->isInteractive()) {
            $key = password('What is the session key?');
        }

        if ($key === null || $key === '') {
            $this->components->error('A session key is required to decrypt (use --key or CLIENT_SESSION_KEY).');
            return self::FAILURE;
        }

        $encrypter = EncryptedStore::encrypter($key);

        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();
        $directory = rtrim($this->resolveDirectory(), '/');

        $session = (string) $this->option('session');
        $sessions = $session !== '' ? [$session] : array_values(array_unique(array_merge(
            $this->discoverSessions($directory, '.session'),
            $this->discoverSessions($directory, '.session.encrypted'),
        )));

        $decrypted = 0;
        foreach ($sessions as $name) {
            foreach ($this->sensitiveExtensions() as $ext) {
                $path = "{$directory}/{$name}{$ext}";

                try {
                    if ($files->exists($path) && str_starts_with($content = $files->get($path), EncryptedStore::PREFIX)) {
                        $files->replace($path, $encrypter->decryptString(substr($content, strlen(EncryptedStore::PREFIX))), 0600);
                    } elseif (!$files->exists($path) && $files->exists("{$path}.encrypted")) {
                        $files->replace($path, $encrypter->decryptString($files->get("{$path}.encrypted")), 0600);
                        $files->delete("{$path}.encrypted");
                    } else {
                        continue;
                    }
                } catch (\Throwable) {
                    $this->components->error("Cannot decrypt {$name}{$ext} - wrong key?");
                    return self::FAILURE;
                }

                $this->components->twoColumnDetail("{$name}{$ext}", '<fg=green;options=bold>DECRYPTED</>');
                $decrypted++;
            }
        }

        $this->newLine();

        if ($decrypted === 0) {
            $this->components->warn('Nothing was decrypted.');
            return self::SUCCESS;
        }

        $this->components->info("Decrypted {$decrypted} session file(s). Set CLIENT_SESSION_ENCRYPT=false, or the client re-encrypts them on its next write.");

        return self::SUCCESS;
    }
}
