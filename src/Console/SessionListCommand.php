<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Console\Concerns\ManagesSessionArtifacts;

class SessionListCommand extends Command
{
    use ManagesSessionArtifacts;

    protected $signature = 'session:list
        {--path= : Sessions directory (default: mtproto.session.path)}';

    protected $description = 'List MTProto sessions on disk (dc, auth key, encryption, peer count)';

    public function handle(): int
    {
        /** @var Filesystem $files */
        $files = $this->laragram['files'] ?? new Filesystem();
        $directory = $this->resolveDirectory();

        // A session exists if either its plaintext or encrypted .session is present.
        $names = array_values(array_unique(array_merge(
            $this->discoverSessions($directory, '.session'),
            $this->discoverSessions($directory, '.session.encrypted'),
        )));
        sort($names);

        if ($names === []) {
            $this->components->warn("No sessions found in {$directory}");
            return 0;
        }

        $rows = [];
        foreach ($names as $name) {
            $rows[] = $this->describe($files, $directory, $name);
        }

        $this->components->info("Sessions in {$directory}");
        $this->newLine();
        $this->table(
            ['Session', 'DC', 'Auth key', 'Encrypted', 'Peers', 'Updated'],
            $rows,
        );

        return 0;
    }

    /**
     * @return array<int,string>
     */
    private function describe(Filesystem $files, string $directory, string $name): array
    {
        $base = rtrim($directory, '/') . '/' . $name;
        $sessionFile = $base . '.session';
        $encrypted = $files->exists($sessionFile . '.encrypted');
        $hasPlain = $files->exists($sessionFile);

        $dc = '—';
        $authKey = 'no';
        $updated = '—';

        if ($hasPlain) {
            $data = json_decode((string) $files->get($sessionFile), true);
            if (is_array($data)) {
                $dc = isset($data['dc_id']) ? (string) $data['dc_id'] : '—';
                $authKey = !empty($data['auth_key']) ? 'yes' : 'no';
                $updated = isset($data['updated_at']) ? date('Y-m-d H:i', (int) $data['updated_at']) : '—';
            }
        } elseif ($encrypted) {
            // Cannot read fields without the key.
            $authKey = 'sealed';
        }

        $peers = $this->peerCount($files, $base, $encrypted && !$hasPlain);

        return [
            $name,
            $dc,
            $authKey,
            $encrypted ? 'yes' : 'no',
            $peers,
            $updated,
        ];
    }

    private function peerCount(Filesystem $files, string $base, bool $sealed): string
    {
        $peerFile = $base . '.peers';

        if (!$files->exists($peerFile)) {
            return $sealed && $files->exists($peerFile . '.encrypted') ? 'sealed' : '0';
        }

        $data = json_decode((string) $files->get($peerFile), true);

        return is_array($data) ? (string) count($data) : '0';
    }
}
