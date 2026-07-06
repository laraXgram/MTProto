<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console\Concerns;

/**
 * Shared helpers for the session:encrypt / session:decrypt commands.
 */
trait ManagesSessionArtifacts
{
    /**
     * On-disk artifacts that hold secret material and must be encrypted at rest:
     *   - .session : permanent auth key + server salt + session id
     *   - .peers   : peer access hashes (needed to message any resolved peer)
     *
     * @return list<string>
     */
    protected function sensitiveExtensions(): array
    {
        return ['.session', '.peers'];
    }

    /**
     * Resolve the sessions directory (--path option, else storage default).
     */
    protected function resolveDirectory(): string
    {
        $path = (string) ($this->option('path') ?: '');

        if ($path === '') {
            return storage_path('mtproto/sessions');
        }

        if (!str_starts_with($path, '/')) {
            return base_path(ltrim($path, './'));
        }

        return $path;
    }

    /**
     * Strip the optional `base64:` prefix from a user-supplied key.
     */
    protected function parseKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7));
        }

        return $key;
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
