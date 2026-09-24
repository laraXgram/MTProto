<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console\Concerns;

/**
 * Shared helpers for the session:encrypt / session:decrypt commands.
 */
trait ManagesSessionArtifacts
{
    /**
     * On-disk session artifacts, encrypted at rest when encryption is on:
     *   - .session      : permanent auth key + server salt + session id
     *   - .peers        : peer access hashes (needed to message any resolved peer)
     *   - _updates.json : update state (pts/qts/seq)
     *
     * @return list<string>
     */
    protected function sensitiveExtensions(): array
    {
        return ['.session', '.peers', '_updates.json'];
    }

    /**
     * The session key: --key, else mtproto.session.encryption.key, else APP_KEY.
     */
    protected function configuredKey(): ?string
    {
        foreach ([$this->option('key'), config('mtproto.session.encryption.key'), config('app.key')] as $key) {
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return null;
    }

    /**
     * Resolve the sessions directory (--path option, else storage default).
     */
    protected function resolveDirectory(): string
    {
        $path = (string) ($this->option('path') ?: '');

        if ($path === '') {
            return (string) (config('mtproto.session.path') ?: storage_path('app/clients/sessions'));
        }

        if (!str_starts_with($path, '/')) {
            return base_path(ltrim($path, './'));
        }

        return $path;
    }

    /**
     * List discovered session base names in $directory for the given file glob
     * suffix (e.g. '.session' for plaintext, '.session.encrypted' for encrypted).
     *
     * @return list<string>
     */
    protected function discoverSessions(string $directory, string $suffix): array
    {
        $names = [];
        foreach ((array) glob(rtrim($directory, '/') . '/*' . $suffix) as $file) {
            $names[] = basename($file, $suffix);
        }

        return array_values(array_unique($names));
    }
}
