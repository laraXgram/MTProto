<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Contracts\Foundation\Application;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Core\StoreRateLimiter;
use LaraGram\MTProto\Core\TokenBucketRateLimiter;

class ClientManager
{
    protected Application $app;

    /**
     * Cached MTProto Client instances.
     * @var array<string, MTProtoClient>
     */
    protected array $clients = [];

    /**
     * Cached Authorization instances.
     * @var array<string, Authorization>
     */
    protected array $authorizations = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get or create a Client for the given session.
     */
    public function client(string $session = 'default'): MTProtoClient
    {
        if (isset($this->clients[$session])) {
            return $this->clients[$session];
        }

        $config = $this->sessionConfig($session);

        $apiId = (int)($config['api_id'] ?? 0);
        $apiHash = (string)($config['api_hash'] ?? '');

        if ($apiId === 0 || $apiHash === '') {
            throw new \RuntimeException(
                'MTProto API credentials not configured. Set TELEGRAM_API_ID and TELEGRAM_API_HASH in .env'
            );
        }

        $transport = \LaraGram\MTProto\Transport\TransportFactory::make(
            (string)($config['transport'] ?? 'abridged')
        );

        $proxy = $this->resolveProxy($config);

        $runtime = $this->app->make(\LaraGram\MTProto\Runtime\Contracts\Runtime::class);

        $options = [
            'transport' => $transport['transport'],
            'obfuscated' => $transport['obfuscated'],
            'protocol_tag' => $transport['tag'],
            'proxy' => $proxy,
            'peer_store' => $this->resolveStore($config, 'peer', '.peers'),
            'session_store' => $this->resolveStore($config, 'session', '.session'),
            'state_store' => $this->resolveStore($config, 'state', '_updates.json'),
            'dc_id' => (int)($config['dc_id'] ?? 2),
            'test_mode' => (bool)($config['test_mode'] ?? false),
            'timeout' => (float)($config['connection']['timeout'] ?? 10),
            'session_dir' => $this->resolveSessionPath($config),
            'layer' => (int)($config['layer'] ?? MTProtoClient::LAYER),
            'flood_sleep' => (bool)($config['flood_sleep'] ?? true),
            'flood_sleep_limit' => (int)($config['flood_sleep_limit'] ?? 60),
            'max_retries' => (int)($config['connection']['retry_count'] ?? 5),
            'use_pump' => (bool)($config['use_pump'] ?? false),
            'device' => \LaraGram\MTProto\Core\DeviceProfile::resolve((array)($config['device'] ?? [])),
            'rate_limiter' => $this->resolveRateLimiter($config, $session),
            'rate_limits' => (array)($config['rate_limit'] ?? []),
            'pacing' => (array)($config['pacing'] ?? []),
            'pool' => (array)($config['pool'] ?? []),
            'logger' => $this->app['mtproto.logger'] ?? null,
            'files' => $this->app['files'] ?? null,
            'runtime' => $runtime,
        ];

        $client = new MTProtoClient($apiId, $apiHash, $options);

        $this->clients[$session] = $client;

        return $client;
    }

    /**
     * Get or create an Authorization handler for the given session.
     */
    public function authorization(string $session = 'default'): Authorization
    {
        if (isset($this->authorizations[$session])) {
            return $this->authorizations[$session];
        }

        $auth = new Authorization($this->client($session));
        $this->authorizations[$session] = $auth;

        return $auth;
    }

    /**
     * Connect a session's client.
     */
    public function connect(string $session = 'default'): bool
    {
        return $this->client($session)->connect($session);
    }

    /**
     * Disconnect a session's client.
     */
    public function disconnect(string $session = 'default'): void
    {
        if (isset($this->clients[$session])) {
            $this->clients[$session]->disconnect();
        }
    }

    /**
     * Check if a session file exists on disk.
     */
    public function sessionExists(string $session = 'default'): bool
    {
        $config = $this->sessionConfig($session);
        $dir = $this->resolveSessionPath($config);
        $file = rtrim($dir, '/') . '/' . $session . '.session';

        return file_exists($file);
    }

    /**
     * Check if a session file contains a valid auth key.
     */
    public function sessionHasAuthKey(string $session = 'default'): bool
    {
        $config = $this->sessionConfig($session);
        $dir = $this->resolveSessionPath($config);
        $file = rtrim($dir, '/') . '/' . $session . '.session';

        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file), true);

        return is_array($data) && !empty($data['auth_key']);
    }

    /**
     * Delete a session (for stale/invalid sessions or an identity reset).
     */
    public function deleteSession(string $session = 'default'): bool
    {
        $config = $this->sessionConfig($session);

        $this->resolveStore($config, 'session', '.session')?->forget($session);

        $dir = $this->resolveSessionPath($config);
        $file = rtrim($dir, '/') . '/' . $session . '.session';
        if (file_exists($file)) {
            @unlink($file);
        }

        return true;
    }

    /**
     * Check if a specific session's client is connected and authorized.
     */
    public function isConnected(string $session = 'default'): bool
    {
        return isset($this->clients[$session]) && $this->clients[$session]->isReady();
    }

    /**
     * Disconnect all sessions.
     */
    public function disconnectAll(): void
    {
        foreach ($this->clients as $session => $client) {
            $this->disconnect($session);
        }
    }

    /**
     * Forget a cached session (client, authorization).
     */
    public function forget(string $session = 'default'): void
    {
        $this->disconnect($session);
        unset(
            $this->clients[$session],
            $this->authorizations[$session],
        );
    }

    /**
     * Get all active session names.
     *
     * @return string[]
     */
    public function activeSessions(): array
    {
        return array_keys($this->clients);
    }

    /**
     * Discover every session on disk that already has an auth key. used to
     * auto-start the pump with zero config (one command boots whatever is
     * authorized).
     *
     * @return string[]
     */
    public function authorizedSessions(): array
    {
        $dir = $this->resolveSessionPath($this->app['config']['mtproto'] ?? []);

        $sessions = [];
        foreach (glob(rtrim($dir, '/') . '/*.session') ?: [] as $file) {
            $name = basename($file, '.session');
            if ($this->sessionHasAuthKey($name)) {
                $sessions[] = $name;
            }
        }

        return $sessions;
    }

    /**
     * Resolve the effective config for a session: the global `mtproto` config
     * with any per-session overrides from `mtproto.sessions.<name>` merged on
     * top. Lets each account define its own api_id/api_hash/device/dc while
     * defaulting to the shared config, multi-account from one config file.
     */
    protected function sessionConfig(string $session): array
    {
        $base = $this->app['config']['mtproto'] ?? [];

        $override = $base['sessions'][$session] ?? [];

        return is_array($override) && $override !== []
            ? array_replace_recursive($base, $override)
            : $base;
    }

    /**
     * Resolve session directory to an absolute path.
     */
    protected function resolveSessionPath(array $config): string
    {
        $path = $config['session']['path'] ?? storage_path('mtproto/sessions');

        if (!str_starts_with($path, '/')) {
            $path = base_path(ltrim($path, './' . DIRECTORY_SEPARATOR));
        }

        return $path;
    }

    /**
     * Build the MTProxy relay settings from session config, or null when the
     * proxy is absent/disabled. Routes the obfuscated2 stream through an MTProxy
     * server instead of a direct DC socket.
     */
    protected function resolveProxy(array $config): ?\LaraGram\MTProto\Transport\ProxySettings
    {
        $proxy = $config['proxy'] ?? null;

        if (!is_array($proxy) || ($proxy['enabled'] ?? false) !== true) {
            return null;
        }

        return \LaraGram\MTProto\Transport\ProxySettings::fromConfig(
            (string)($proxy['host'] ?? ''),
            (int)($proxy['port'] ?? 0),
            (string)($proxy['secret'] ?? ''),
        );
    }

    /**
     * Resolve a named state store from config.
     *
     * @param string $fileExt
     */
    protected function resolveStore(array $config, string $name, string $fileExt): ?\LaraGram\MTProto\Contracts\Store
    {
        $store = $config['stores'][$name] ?? null;

        if (!is_array($store) || ($store['driver'] ?? null) === null) {
            return null;
        }

        $manager = $this->storeManager();
        $primary = $manager->make($this->withFileDefaults($store, $config, $fileExt), $name);

        $seen = [strtolower((string)$store['driver'])];
        $legacy = [];

        foreach ((array)($store['migrate_from'] ?? []) as $from) {
            $fromCfg = is_array($from) ? $from : ['driver' => $from];
            $driver = strtolower((string)($fromCfg['driver'] ?? ''));

            if ($driver === '' || in_array($driver, $seen, true)) {
                continue;
            }

            $seen[] = $driver;
            $legacy[] = $manager->make($this->withFileDefaults($fromCfg, $config, $fileExt), $name);
        }

        $resolved = $legacy === []
            ? $primary
            : new \LaraGram\MTProto\Store\MigratingStore($primary, ...$legacy);

        if (!in_array('file', $seen, true)) {
            $mirror = $manager->make($this->withFileDefaults(['driver' => 'file'], $config, $fileExt));

            return new \LaraGram\MTProto\Store\MirrorStore($resolved, $mirror);
        }

        return $resolved;
    }

    /**
     * Apply the legacy session-dir path + extension to a `file` store config so
     * on-disk data loads unchanged; other drivers pass through untouched.
     *
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function withFileDefaults(array $store, array $config, string $fileExt): array
    {
        if (strtolower((string)($store['driver'] ?? '')) === 'file') {
            $store['path'] = $store['path'] ?? $this->resolveSessionPath($config);
            $store['extension'] = $store['extension'] ?? $fileExt;
        }

        return $store;
    }

    /**
     * Resolve the rate limiter.
     */
    protected function resolveRateLimiter(array $config, string $session = 'default'): \LaraGram\MTProto\Contracts\RateLimiterInterface
    {
        $rate = (float)($config['rate_limit']['global']['rate'] ?? 30);
        $capacity = (float)($config['rate_limit']['global']['capacity'] ?? 30);

        $store = $this->resolveStore($config, 'limit', '.limits');

        if ($store !== null) {
            return new StoreRateLimiter($store, $rate, $capacity, "rl:{$session}");
        }

        return new TokenBucketRateLimiter($rate, $capacity);
    }

    /**
     * Build a {@see \LaraGram\MTProto\Store\StoreManager} wired to the framework
     * cache and the Runtime table primitive.
     */
    protected function storeManager(): \LaraGram\MTProto\Store\StoreManager
    {
        $runtime = $this->app->make(\LaraGram\MTProto\Runtime\Contracts\Runtime::class);

        return new \LaraGram\MTProto\Store\StoreManager(
            fn(?string $name) => $this->app['cache']->store($name),
            $this->app['files'] ?? null,
            fn(string $table, int $rows, int $size) => $runtime->table($table, $rows, $size),
        );
    }

    public function __destruct()
    {
        $this->disconnectAll();
    }
}
