<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Runtime\Contracts\Runtime;

/**
 * Pump-driven update loop (Phase 1.3).
 *
 * Runs the whole client inside one coroutine container: a single {@see \LaraGram\MTProto\RPC\MessagePump}
 * reader owns the socket, every pushed update is fed in its own coroutine, and
 * handler RPC calls (sendMessage, …) resolve through the pump's channels instead
 * of fighting the reader. A blocking sleep()/RPC inside a handler yields rather
 * than freezing the loop.
 *
 * Drop-in for {@see UpdateLoop} on the coroutine runtime; selected when
 * `mtproto.use_pump` is enabled.
 */
class PumpLoop
{
    private Runtime           $runtime;
    private UpdateFeed        $feed;
    private UpdateState       $state;
    private ?\LaraGram\Log\LoggerInterface $logger;

    private bool $running = false;

    public function __construct(private Client $client, ?Runtime $runtime = null)
    {
        $this->runtime = $runtime ?? $client->getRuntime();
        $this->logger  = $client->getLogger();

        $this->state = new UpdateState(
            $client->getSessionDir(),
            $client->getSessionName(),
            $client->getFiles(),
        );

        $this->feed = new UpdateFeed($client, $this->state);
        $this->feed->setRuntime($this->runtime);
    }

    public function onUpdate(callable $callback): self
    {
        $this->feed->setUpdateHandler($callback);
        return $this;
    }

    public function on(string $event, callable $callback): self
    {
        $this->feed->on($event, $callback);
        return $this;
    }

    public function off(string $event, ?callable $callback = null): self
    {
        $this->feed->off($event, $callback);
        return $this;
    }

    public function getFeed(): UpdateFeed
    {
        return $this->feed;
    }

    public function getState(): UpdateState
    {
        return $this->state;
    }

    public function run(): void
    {
        if ($this->running) {
            return;
        }

        if (!$this->runtime->isSupported()) {
            throw new \RuntimeException(
                'PumpLoop requires a coroutine runtime (swoole/openswoole). Set mtproto.driver=swoole.'
            );
        }

        $this->running = true;

        // The bootstrap socket was opened outside any coroutine, so it is a raw
        // \Socket. Close it HERE (still outside the coroutine, native path) — left
        // alive, its destructor would later run the hooked socket_shutdown() under
        // the coroutine and crash. Inside the coroutine we open a fresh one.
        try { $this->client->getConnection()->disconnect(); } catch (\Throwable) {}

        $this->runtime->run(function (): void {
            // Rebuild the connection inside the coroutine: SWOOLE_HOOK_SOCKETS
            // requires sockets created here so reads/writes are coroutine sockets.
            // Auth key is reused from the session.
            $this->client->reconnect();

            $pump = $this->client->getPump();
            $pump->setUpdateHandler(fn (array $data) => $this->onPushed($data));

            $this->client->startPump();

            $this->feed->initialise();
            $this->logger?->info('Listening for updates (pump)…');

            $saveEvery = 30;
            $ticks = 0;
            while ($this->running) {
                $this->runtime->sleep(1.0);
                if (++$ticks >= $saveEvery) {
                    $ticks = 0;
                    $this->state->save();
                }
            }
        });

        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->state->save();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function onPushed(array $data): void
    {
        if (($data['_'] ?? '') === 'updatesTooLong') {
            $this->feed->fetchDifference();
            return;
        }

        $this->feed->feed($data);
    }
}
