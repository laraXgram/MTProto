<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Contracts\Foundation\Application;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\TL\TLObject;
use LaraGram\MTProto\Updates\PumpLoop;

class PumpProcess
{
    public function __construct(protected Application $app)
    {
    }

    /**
     * @param Application $application
     * @param mixed $process
     * @param mixed $server
     */
    public function __invoke($application = null, $process = null, $server = null): void
    {
        $app = $application instanceof Application ? $application : $this->app;

        /** @var ClientManager $manager */
        $manager = $app['mtproto.manager'];
        /** @var ClientKernel $kernel */
        $kernel = $app[ClientKernel::class];
        $kernel->bootstrap();

        /** @var ClientDispatcher $dispatcher */
        $dispatcher = $app[ClientDispatcher::class];
        /** @var Runtime $runtime */
        $runtime = $app->make(Runtime::class);
        $logger = $app['mtproto.logger'] ?? null;

        $sessions = $this->resolveSessions($app, $manager);

        if ($sessions === []) {
            $logger?->warning('[pump-process] No authorized sessions to start.');
            return;
        }

        $pumps = [];
        foreach ($sessions as $session) {
            $pumps[$session] = (new PumpLoop($manager->client($session)))
                ->onUpdate(fn(TLObject $u, string $t) => $dispatcher->dispatch($kernel, $u, $t, $session));
        }

        $logger?->info('[pump-process] Starting sessions: ' . implode(', ', array_keys($pumps)));

        $runtime->run(function () use ($runtime, $manager, $pumps, $logger) {
            foreach ($pumps as $session => $pump) {
                $runtime->spawn(function () use ($manager, $pump, $session, $logger) {
                    try {
                        $manager->connect($session);
                        $pump->boot();
                    } catch (\Throwable $e) {
                        $logger?->error(
                            "[pump-process] Session '{$session}' stopped: {$e->getMessage()} "
                            . "(if AUTH_KEY_UNREGISTERED, run: php laragram client:auth --session={$session})",
                            ['exception' => $e],
                        );
                        try {
                            $pump->stop();
                        } catch (\Throwable) {
                        }
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
    }

    /**
     * Resolve which sessions this process should run - authorized only.
     *
     * @return string[]
     */
    protected function resolveSessions(Application $app, ClientManager $manager): array
    {
        $config = (array)($app['config']['mtproto'] ?? []);

        $list = (array)($config['surge']['sessions']
            ?? array_keys((array)($config['sessions'] ?? [])));

        if ($list === []) {
            return $manager->authorizedSessions();
        }

        return array_values(array_filter(
            array_unique($list),
            fn($session) => $manager->sessionHasAuthKey((string)$session),
        ));
    }
}
