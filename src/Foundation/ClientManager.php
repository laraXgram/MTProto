<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Contracts\Foundation\Application;
use LaraGram\MTProto\Auth\Authorization;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Driver\Swoole\SwooleEventLoop;
use LaraGram\MTProto\Driver\Sync\SyncEventLoop;
use LaraGram\MTProto\Updates\UpdatesHandler;

/**
 * Manages multiple MTProto client sessions.
 *
 * The manager creates and caches Client + UpdatesHandler instances per session.
 */
class ClientManager
{
    protected Application $app;

    /**
     * Cached MTProto Client instances.
     * @var array<string, MTProtoClient>
     */
    protected array $clients = [];

    /**
     * Cached UpdatesHandler instances.
     * @var array<string, UpdatesHandler>
     */
    protected array $handlers = [];

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

        $config = $this->app['config']['mtproto'] ?? [];

        $apiId   = (int)   ($config['api_id']   ?? 0);
        $apiHash = (string)($config['api_hash'] ?? '');

        if ($apiId === 0 || $apiHash === '') {
            throw new \RuntimeException(
                'MTProto API credentials not configured. Set TELEGRAM_API_ID and TELEGRAM_API_HASH in .env'
            );
        }

        $eventLoop = $this->resolveEventLoop($config['driver'] ?? 'sync');

        $options = [
            'dc_id'       => (int) ($config['dc_id'] ?? 2),
            'test_mode'   => (bool) ($config['test_mode'] ?? false),
            'timeout'     => (float) ($config['connection']['timeout'] ?? 10),
            'session_dir' => $this->resolveSessionPath($config),
            'event_loop'  => $eventLoop,
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
     * Get or create an UpdatesHandler for the given session.
     */
    public function handler(string $session = 'default'): UpdatesHandler
    {
        if (isset($this->handlers[$session])) {
            return $this->handlers[$session];
        }

        $config = $this->app['config']['mtproto'] ?? [];
        $eventLoop = $this->resolveEventLoop($config['driver'] ?? 'sync');

        $handler = new UpdatesHandler($this->client($session), $eventLoop);
        $this->handlers[$session] = $handler;

        return $handler;
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
        $config = $this->app['config']['mtproto'] ?? [];
        $dir    = $this->resolveSessionPath($config);
        $file   = rtrim($dir, '/') . '/' . $session . '.session';

        return file_exists($file);
    }

    /**
     * Check if a session file contains a valid auth key.
     *
     * A session file can exist but have no auth key (freshly created)
     * or have an auth key that was never used to complete login.
     */
    public function sessionHasAuthKey(string $session = 'default'): bool
    {
        $config = $this->app['config']['mtproto'] ?? [];
        $dir    = $this->resolveSessionPath($config);
        $file   = rtrim($dir, '/') . '/' . $session . '.session';

        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file), true);

        return is_array($data) && !empty($data['auth_key']);
    }

    /**
     * Delete a session file (for stale/invalid sessions).
     */
    public function deleteSession(string $session = 'default'): bool
    {
        $config = $this->app['config']['mtproto'] ?? [];
        $dir    = $this->resolveSessionPath($config);
        $file   = rtrim($dir, '/') . '/' . $session . '.session';

        if (file_exists($file)) {
            return unlink($file);
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
     * Forget a cached session (client, handler, authorization).
     *
     * After calling this, the next client/handler/authorization call
     * for this session will create fresh instances.
     */
    public function forget(string $session = 'default'): void
    {
        $this->disconnect($session);
        unset(
            $this->clients[$session],
            $this->handlers[$session],
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
     * Resolve session directory to an absolute path.
     *
     * Handles relative paths (e.g. './storage/...') by prepending base_path().
     */
    protected function resolveSessionPath(array $config): string
    {
        $path = $config['session']['path'] ?? storage_path('mtproto/sessions');

        // If the path is relative, make it absolute
        if (!str_starts_with($path, '/')) {
            $path = base_path(ltrim($path, './' . DIRECTORY_SEPARATOR));
        }

        return $path;
    }

    /**
     * Resolve the event loop driver from config name.
     */
    protected function resolveEventLoop(string $driver): EventLoopInterface
    {
        return match ($driver) {
            'swoole' => new SwooleEventLoop(),
            default  => new SyncEventLoop(),
        };
    }

    public function __destruct()
    {
        $this->disconnectAll();
    }
}
