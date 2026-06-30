<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Runtime\Contracts\Runtime;

class PumpLoop
{
    private Runtime $runtime;
    private UpdateFeed $feed;
    private UpdateState $state;
    private ?\LaraGram\Log\LoggerInterface $logger;

    private bool $running = false;

    public function __construct(private Client $client, ?Runtime $runtime = null)
    {
        $this->runtime = $runtime ?? $client->getRuntime();
        $this->logger = $client->getLogger();

        $this->state = new UpdateState(
            $client->getSessionDir(),
            $client->getSessionName(),
            $client->getFiles(),
            $client->getStateStore(),
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

        $this->disconnectBootstrapSocket();

        $this->runtime->run(function (): void {
            $this->boot();

            $saveEvery = 30;
            $ticks = 0;
            while ($this->running) {
                $this->runtime->sleep(1.0);
                if (++$ticks >= $saveEvery) {
                    $ticks = 0;
                    $this->saveState();
                }
            }
        });

        $this->running = false;
    }

    /**
     * Close the bootstrap socket opened outside the coroutine. Call from a
     * NATIVE (non-coroutine) context before entering the runtime container.
     */
    public function disconnectBootstrapSocket(): void
    {
        try {
            $this->client->getConnection()->disconnect();
        } catch (\Throwable) {
        }
    }

    /**
     * Set up this session's reader inside an ALREADY-RUNNING coroutine container.
     */
    public function boot(): void
    {
        $this->running = true;

        $this->client->reconnect();

        $pump = $this->client->getPump();
        $pump->setUpdateHandler(fn(array $data) => $this->onPushed($data));

        $this->client->startPump();

        $this->feed->initialise();

        $this->client->primePeerCache();

        $this->logger?->info("Listening for updates (pump) [{$this->client->getSessionName()}]…");
    }

    /**
     * Persist this session's update state (pts/qts/seq/date) - called on the
     * shared keep-alive tick.
     */
    public function saveState(): void
    {
        $this->state->save();
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
