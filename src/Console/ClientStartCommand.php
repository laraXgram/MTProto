<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\MTProto\Foundation\ClientKernel;
use LaraGram\MTProto\Foundation\ClientManager;
use LaraGram\MTProto\Foundation\ClientRequest;
use LaraGram\MTProto\TL\TLObject;

/**
 * Start the MTProto client update listener.
 */
class ClientStartCommand extends Command
{
    protected $signature = 'client:start
        {--session=default : Session name to use}';

    protected $description = 'Start the MTProto client and listen for updates';

    public function handle(): int
    {
        $session = $this->option('session');

        /** @var ClientManager $manager */
        $manager = $this->laragram['mtproto.manager'];

        // ── Step 1: Check session state ───────────────────────────────
        if (!$manager->sessionExists($session)) {
            // No session file at all — run interactive auth
            $this->components->warn("No session found for '{$session}'. Starting authentication...");
            return $this->authenticateAndStart($manager, $session);
        }

        // ── Step 2: Connect ───────────────────────────────────────────
        $this->components->task("Connecting session '{$session}'", function () use ($manager, $session) {
            $manager->connect($session);
        });

        if (!$manager->isConnected($session)) {
            $this->components->error('Failed to connect to Telegram.');
            return self::FAILURE;
        }

        // ── Step 3: Verify session is authorized ──────────────────────
        //    Try a lightweight API call to check if the auth key is valid.
        //    If AUTH_KEY_UNREGISTERED, the session is stale → delete & re-auth.
        try {
            $manager->client($session)->invoke('help.getConfig');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, 'AUTH_KEY_UNREGISTERED') || str_contains($msg, '401')) {
                $this->components->warn('Session auth key is invalid or expired. Re-authenticating...');

                // Disconnect and delete stale session
                $manager->disconnect($session);
                $manager->deleteSession($session);

                // Clear cached client so a fresh one is created
                return $this->authenticateAndStart($manager, $session);
            }

            // Other errors — bubble up
            $this->components->error("Connection test failed: {$msg}");
            return self::FAILURE;
        }

        // ── Step 4: Start listening ───────────────────────────────────
        return $this->startListening($manager, $session);
    }

    /**
     * Run auth flow then start listening.
     */
    protected function authenticateAndStart(ClientManager $manager, string $session): int
    {
        $exitCode = $this->call('client:auth', ['--session' => $session]);

        if ($exitCode !== self::SUCCESS) {
            $this->components->error('Authentication failed. Cannot start client.');
            return self::FAILURE;
        }

        $this->newLine();

        // After auth, the session name may have changed due to DC migration
        // (e.g. 'default' → 'user_dc4'). Get the actual session name.
        $actualSession = $manager->client($session)->getSessionName();

        if ($actualSession !== $session) {
            $this->components->info("Session migrated to DC: using '{$actualSession}'");
        }

        // Forget and reconnect with the actual session name
        $manager->forget($session);

        $this->components->task("Connecting session '{$actualSession}'", function () use ($manager, $actualSession) {
            $manager->connect($actualSession);
        });

        if (!$manager->isConnected($actualSession)) {
            $this->components->error('Failed to connect after authentication.');
            return self::FAILURE;
        }

        return $this->startListening($manager, $actualSession);
    }

    /**
     * Bootstrap kernel, load listens, wire handler, start update loop.
     */
    protected function startListening(ClientManager $manager, string $session): int
    {
        // ── Bootstrap ClientKernel ─────────────────────────────────────
        /** @var ClientKernel $kernel */
        $kernel = $this->laragram[ClientKernel::class];
        $kernel->bootstrap();

        // Load client listens (from listens/client.php or wherever configured)
        $this->loadClientListens();

        $this->components->info("Client '{$session}' connected and listening for updates...");
        $this->newLine();

        // ── Wire up update handler ─────────────────────────────────────
        $handler = $manager->handler($session);

        $handler->onUpdate(function (TLObject $update, string $type) use ($kernel, $session) {
            $this->dispatchUpdate($kernel, $update, $type, $session);
        });

        // ── Start the update loop (blocks) ─────────────────────────────
        $handler->start();

        return self::SUCCESS;
    }

    /**
     * Dispatch an MTProto update through the ClientKernel pipeline.
     */
    protected function dispatchUpdate(ClientKernel $kernel, TLObject $update, string $type, string $session): void
    {
        try {
            $request = ClientRequest::fromUpdate(
                $update->toArray(),
                $type,
                $session
            );

            // Inject the MTProto Client so $request->client() works in listens
            /** @var ClientManager $manager */
            $manager = $this->laragram['mtproto.manager'];
            $request->setClient($manager->client($session));

            $response = $kernel->handle($request);

            $kernel->terminate($request, $response);
        } catch (\Throwable $e) {
            // Log but don't crash the loop
            error_log("[client:{$session}] Error dispatching {$type}: {$e->getMessage()}");
        }
    }

    /**
     * Load client listen definitions.
     *
     * Looks for listens/client.php and loads it through the
     * Client facade's middleware group.
     */
    protected function loadClientListens(): void
    {
        $clientListenFile = $this->laragram->basePath('listens/client.php');

        if (file_exists($clientListenFile)) {
            $clientListener = $this->laragram['client.listener'];
            $clientListener->group(['middleware' => ['client']], $clientListenFile);
        }
    }
}
