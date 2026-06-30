<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\MTProto\Foundation\ClientDispatcher;
use LaraGram\MTProto\Foundation\ClientKernel;
use LaraGram\MTProto\Foundation\ClientManager;
use LaraGram\MTProto\TL\TLObject;
use LaraGram\MTProto\Updates\PumpLoop;

/**
 * Start the MTProto client update listener.
 */
class ClientStartCommand extends Command
{
    protected $signature = 'client:start
        {--session=default : Session name(s) to start — comma-separated for several}
        {--all : Start every session defined in config(mtproto.sessions)}';

    protected $description = 'Start the MTProto client and listen for updates (one or many sessions)';

    public function handle(): int
    {
        /** @var ClientManager $manager */
        $manager = $this->laragram['mtproto.manager'];

        $sessions = $this->resolveSessions();

        if ($sessions === []) {
            $this->components->error('No sessions to start. Configure mtproto.sessions or pass --session=name.');
            return self::FAILURE;
        }

        // Connect + verify each requested session; collect the ones that came up.
        $ready = [];
        foreach ($sessions as $session) {
            $actual = $this->prepareSession($manager, $session);
            if ($actual !== null) {
                $ready[] = $actual;
            }
        }

        if ($ready === []) {
            $this->components->error('No sessions connected.');
            return self::FAILURE;
        }

        return $this->startListening($manager, array_values(array_unique($ready)));
    }

    /**
     * Resolve which sessions to start from the options.
     *
     * @return string[]
     */
    protected function resolveSessions(): array
    {
        if ($this->option('all')) {
            $configured = array_keys((array) ($this->laragram['config']['mtproto.sessions'] ?? []));
            return $configured !== [] ? $configured : ['default'];
        }

        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('session'))
        )));
    }

    /**
     * Connect + verify a single session, authenticating if needed. Returns the
     * actual (possibly DC-migrated) session name, or null on failure.
     */
    protected function prepareSession(ClientManager $manager, string $session): ?string
    {
        if (!$manager->sessionExists($session)) {
            $this->components->warn("No session found for '{$session}'. Starting authentication...");
            return $this->authenticateAndConnect($manager, $session);
        }

        $this->components->task("Connecting session '{$session}'", function () use ($manager, $session) {
            $manager->connect($session);
        });

        if (!$manager->isConnected($session)) {
            $this->components->error("Failed to connect session '{$session}'.");
            return null;
        }

        // Lightweight call to validate the auth key; stale -> delete & re-auth.
        try {
            $manager->client($session)->invoke('help.getConfig');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, 'AUTH_KEY_UNREGISTERED') || str_contains($msg, '401')) {
                $this->components->warn("Session '{$session}' auth key invalid/expired. Re-authenticating...");
                $manager->disconnect($session);
                $manager->deleteSession($session);
                return $this->authenticateAndConnect($manager, $session);
            }

            $this->components->error("Connection test failed for '{$session}': {$msg}");
            return null;
        }

        return $session;
    }

    /**
     * Run the auth flow for a session then connect it. Returns the actual
     * session name (DC migration may rename it), or null on failure.
     */
    protected function authenticateAndConnect(ClientManager $manager, string $session): ?string
    {
        if ($this->call('client:auth', ['--session' => $session]) !== self::SUCCESS) {
            $this->components->error("Authentication failed for '{$session}'.");
            return null;
        }

        $this->newLine();

        // After auth the session name may change due to DC migration.
        $actualSession = $manager->client($session)->getSessionName();

        if ($actualSession !== $session) {
            $this->components->info("Session migrated to DC: using '{$actualSession}'");
        }

        $manager->forget($session);

        $this->components->task("Connecting session '{$actualSession}'", function () use ($manager, $actualSession) {
            $manager->connect($actualSession);
        });

        if (!$manager->isConnected($actualSession)) {
            $this->components->error("Failed to connect '{$actualSession}' after authentication.");
            return null;
        }

        return $actualSession;
    }

    /**
     * Bootstrap kernel, load listens, then run the update loop(s).
     *
     * @param  string[]  $sessions
     */
    protected function startListening(ClientManager $manager, array $sessions): int
    {
        /** @var ClientKernel $kernel */
        $kernel = $this->laragram[ClientKernel::class];
        $kernel->bootstrap();

        // Listen files (with their per-file session bindings) load once.
        $this->loadClientListens();

        $usePump = (bool) ($this->laragram['config']['mtproto.use_pump'] ?? false);

        if (!$usePump) {
            if (count($sessions) > 1) {
                $this->components->error(
                    'Multiple sessions require mtproto.use_pump=true (the legacy handler is single-session).'
                );
                return self::FAILURE;
            }

            $session = $sessions[0];
            $this->components->info("Client '{$session}' connected and listening for updates...");
            $this->newLine();

            $manager->handler($session)
                ->onUpdate(fn (TLObject $u, string $t) => $this->dispatchUpdate($kernel, $u, $t, $session))
                ->start();

            return self::SUCCESS;
        }

        $pumps = [];
        foreach ($sessions as $session) {
            $pumps[$session] = (new PumpLoop($manager->client($session)))
                ->onUpdate(fn (TLObject $u, string $t) => $this->dispatchUpdate($kernel, $u, $t, $session));
        }

        // Single session keeps its self-contained runner.
        if (count($pumps) === 1) {
            $session = array_key_first($pumps);
            $this->components->info("Client '{$session}' connected and listening for updates...");
            $this->newLine();

            $pumps[$session]->run();
            return self::SUCCESS;
        }

        return $this->runMultiSession($manager, $pumps);
    }

    /**
     * Run several sessions concurrently inside ONE shared coroutine container.
     *
     * @param  array<string, PumpLoop>  $pumps
     */
    protected function runMultiSession(ClientManager $manager, array $pumps): int
    {
        $runtime = $manager->client(array_key_first($pumps))->getRuntime();

        if (!$runtime->isSupported()) {
            $this->components->error('Multi-session requires a coroutine runtime (mtproto.driver=swoole).');
            return self::FAILURE;
        }

        // Close every bootstrap socket BEFORE entering the coroutine container.
        foreach ($pumps as $pump) {
            $pump->disconnectBootstrapSocket();
        }

        $this->components->info('Listening on '.count($pumps).' sessions: '.implode(', ', array_keys($pumps)));
        $this->newLine();

        $runtime->run(function () use ($runtime, $pumps) {
            foreach ($pumps as $session => $pump) {
                $runtime->spawn(function () use ($pump, $session) {
                    try {
                        $pump->boot();
                    } catch (\Throwable $e) {
                        \LaraGram\Support\Facades\Log::error(
                            "[client:{$session}] stopped: {$e->getMessage()} "
                            ."(if AUTH_KEY_UNREGISTERED, run: php laragram client:auth --session={$session})",
                            ['exception' => $e],
                        );
                        try { $pump->stop(); } catch (\Throwable) {}
                    }
                });
            }

            $saveEvery = 30;
            $ticks = 0;
            while (true) {
                $runtime->sleep(1.0);
                if (++$ticks >= $saveEvery) {
                    $ticks = 0;
                    foreach ($pumps as $pump) {
                        $pump->saveState();
                    }
                }
            }
        });

        return self::SUCCESS;
    }

    /**
     * Dispatch an MTProto update through the shared ClientDispatcher.
     */
    protected function dispatchUpdate(ClientKernel $kernel, TLObject $update, string $type, string $session): void
    {
        $this->laragram[ClientDispatcher::class]->dispatch($kernel, $update, $type, $session);
    }

    /**
     * Ensure client listens are loaded.
     */
    protected function loadClientListens(): void
    {
        $listener = $this->laragram['client.listener'];

        if (count($listener->getListens()) > 0) {
            return;
        }

        $file = $this->laragram->basePath('listens/client.php');

        if (file_exists($file)) {
            $listener->group(['middleware' => ['client']], $file);
        }
    }
}
