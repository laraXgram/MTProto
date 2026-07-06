<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

use LaraGram\Filesystem\Filesystem;
use LaraGram\MTProto\Session\FileSession;

/**
 * Converts a foreign session (Pyrogram / Telethon / MadelineProto) into
 * LaraGram's own on-disk session format.
 */
final class SessionImporter
{
    /** @var array<string,SessionSource> */
    private array $sources;

    public function __construct(
        private Filesystem $files,
        ?array $sources = null,
    ) {
        $sources ??= [
            new PyrogramSource(),
            new TelethonSource(),
            new MadelineSource(),
        ];

        foreach ($sources as $source) {
            /** @var SessionSource $source */
            $this->sources[$source->name()] = $source;
        }
    }

    /**
     * @return array<string,SessionSource>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Decode $input as $format ('auto' to autodetect) into a ForeignSession,
     * without writing anything. Useful for a dry run.
     */
    public function decode(string $format, string $input): ForeignSession
    {
        $source = $format === 'auto'
            ? $this->detect($input)
            : ($this->sources[$format] ?? throw new \InvalidArgumentException("Unknown session format: {$format}"));

        return $source->parse($input);
    }

    /**
     * Import $input into a named LaraGram session under $directory.
     *
     * @param bool $overwrite  Allow replacing an existing session that already
     *                         holds an auth key.
     * @return ForeignSession  The decoded source (for reporting).
     */
    public function import(
        string $format,
        string $input,
        string $name,
        string $directory,
        bool $overwrite = false,
    ): ForeignSession {
        $foreign = $this->decode($format, $input);

        $directory = rtrim($directory, '/');
        $this->files->ensureDirectoryExists($directory, 0700);

        $sessionPath = $directory . '/' . $this->safe($name) . '.session';
        if (!$overwrite && $this->files->exists($sessionPath) && $this->hasAuthKey($sessionPath)) {
            throw new \RuntimeException(
                "Session '{$name}' already has an auth key at {$sessionPath}. Re-run with --force to overwrite."
            );
        }

        $this->writeSession($name, $directory, $foreign);
        $this->writePeers($name, $directory, $foreign->peers);

        return $foreign;
    }

    /**
     * Pick the first source that recognises $input.
     */
    private function detect(string $input): SessionSource
    {
        foreach ($this->sources as $source) {
            if ($source->supports($input)) {
                return $source;
            }
        }

        throw new \RuntimeException(
            'Could not autodetect the session format. Pass --from=pyrogram|telethon|madeline explicitly.'
        );
    }

    private function writeSession(string $name, string $directory, ForeignSession $foreign): void
    {
        // FileSession owns the exact on-disk JSON shape; setAuthKey/setDcId each
        // persist, so the resulting file is indistinguishable from a native one.
        $session = new FileSession($name, $directory, $this->files);
        $session->setDcId($foreign->dcId);
        $session->setAuthKey($foreign->authKey);
    }

    /**
     * Merge imported peers into the `.peers` store, keyed by raw id, without
     * dropping peers already cached for this session.
     *
     * @param list<array<string,mixed>> $peers
     */
    private function writePeers(string $name, string $directory, array $peers): void
    {
        if ($peers === []) {
            return;
        }

        $path = $directory . '/' . $this->safe($name) . '.peers';

        $existing = [];
        if ($this->files->exists($path)) {
            $decoded = json_decode((string) $this->files->get($path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (is_array($entry) && isset($entry['id'])) {
                        $existing[(int) $entry['id']] = $entry;
                    }
                }
            }
        }

        foreach ($peers as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            // Never downgrade a full entry to one lacking an access hash.
            if (isset($existing[$id]) && empty($entry['access_hash']) && !empty($existing[$id]['access_hash'])) {
                continue;
            }

            $existing[$id] = $entry;
        }

        $json = json_encode(array_values($existing), JSON_UNESCAPED_UNICODE);
        $this->files->replace($path, $json, 0600);
    }

    private function hasAuthKey(string $sessionPath): bool
    {
        try {
            $data = json_decode((string) $this->files->get($sessionPath), true);
        } catch (\Throwable) {
            return false;
        }

        return is_array($data) && !empty($data['auth_key']);
    }

    private function safe(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }
}
