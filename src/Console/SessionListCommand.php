<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Console\Concerns\ManagesSessionArtifacts;
use LaraGram\MTProto\Foundation\ClientManager;
use LaraGram\MTProto\Store\EncryptedStore;

class SessionListCommand extends Command
{
    use ManagesSessionArtifacts;

    protected $signature = 'session:list
        {--path= : Sessions directory (default: mtproto.session.path)}
        {--key= : Session key to read encrypted sessions (default: CLIENT_SESSION_KEY, else APP_KEY)}';

    protected $description = 'List MTProto sessions (source, dc, auth key, encryption, lock, peer count)';

    public function handle(): int
    {
        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();
        $directory = rtrim($this->resolveDirectory(), '/');
        /** @var ClientManager $manager */
        $manager = $this->laragram['mtproto.manager'];

        $names = array_merge(
            $this->discoverSessions($directory, '.session'),
            $this->discoverSessions($directory, '.session.encrypted'),
        );
        $configured = array_merge(
            [(string) config('mtproto.session.name', 'default')],
            array_keys((array) config('mtproto.sessions', [])),
        );
        foreach ($configured as $name) {
            if ($manager->authString((string) $name) !== null) {
                $names[] = (string) $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        if ($names === []) {
            $this->components->warn("No sessions found in {$directory}");
            return self::SUCCESS;
        }

        $key = $this->configuredKey();
        $encrypter = $key !== null ? EncryptedStore::encrypter($key) : null;

        $rows = [];
        foreach ($names as $name) {
            $rows[] = $this->describe($files, $directory, $name, $manager->authString($name) !== null, $encrypter);
        }

        $this->components->info("Sessions in {$directory}");
        $this->newLine();
        $this->table(['Session', 'Source', 'DC', 'Auth key', 'Encrypted', 'In use', 'Peers', 'Updated'], $rows);

        return self::SUCCESS;
    }

    private function describe(Filesystem $files, string $directory, string $name, bool $authString, ?\LaraGram\Encryption\Encrypter $encrypter): array
    {
        $base = "{$directory}/{$name}";
        [$session, $sealed] = $this->read($files, "{$base}.session", $encrypter);
        [$peers] = $this->read($files, "{$base}.peers", $encrypter);

        $encrypted = $sealed !== null || $files->exists("{$base}.session.encrypted");

        return [
            $name,
            $authString ? ($files->exists("{$base}.session") ? 'auth string + file' : 'auth string') : 'file',
            isset($session['dc_id']) ? (string) $session['dc_id'] : '—',
            $sealed === true ? 'sealed' : (!empty($session['auth_key']) || $authString ? 'yes' : 'no'),
            $encrypted ? 'yes' : 'no',
            $this->lockOwner("{$base}.lock"),
            is_array($peers) ? (string) count($peers) : ($sealed === true ? 'sealed' : '0'),
            isset($session['updated_at']) ? date('Y-m-d H:i', (int) $session['updated_at']) : '—',
        ];
    }

    /**
     * Decode a session artifact.
     *
     * @return array{0: array|null, 1: bool|null} [data, sealed] - sealed is
     *         null for plain text, false when decrypted, true when unreadable
     */
    private function read(Filesystem $files, string $path, ?\LaraGram\Encryption\Encrypter $encrypter): array
    {
        if (!$files->exists($path)) {
            return [null, null];
        }

        $content = (string) $files->get($path);
        if (!str_starts_with($content, EncryptedStore::PREFIX)) {
            return [json_decode($content, true), null];
        }

        if ($encrypter === null) {
            return [null, true];
        }

        try {
            return [json_decode($encrypter->decryptString(substr($content, strlen(EncryptedStore::PREFIX))), true), false];
        } catch (\Throwable) {
            return [null, true];
        }
    }

    /**
     * The pid holding the session lock, or "no".
     */
    private function lockOwner(string $path): string
    {
        if (!is_file($path) || ($handle = @fopen($path, 'r')) === false) {
            return 'no';
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);
        $pid = trim((string) stream_get_contents($handle));
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $free ? 'no' : 'pid ' . ($pid !== '' ? $pid : '?');
    }
}
