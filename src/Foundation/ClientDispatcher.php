<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Contracts\Foundation\Application;
use LaraGram\Listening\Exceptions\ListenNotFoundException;
use LaraGram\MTProto\Events\UpdateReceived;
use LaraGram\MTProto\Runtime\Contracts\Channel;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\TL\TLObject;
use Throwable;

class ClientDispatcher
{
    /**
     * Lazily-built Surge state flusher (null when Surge is absent).
     */
    protected ?object $flusher = null;

    /**
     * Lets one sandboxed update run at a time (null outside of a coroutine runtime).
     */
    protected ?Channel $lock = null;

    public function __construct(protected Application $app)
    {
    }

    /**
     * Route an update for the given session.
     *
     * Under Surge, "mtproto.surge.isolation" decides how state is reset between updates:
     * "sandbox" runs each update in a fresh application sandbox, one update at a time;
     * "concurrent" keeps updates concurrent and flushes the state once none is running.
     */
    public function dispatch(ClientKernel $kernel, TLObject $update, string $type, string $session): void
    {
        $flusher = $this->flusher();

        if ($flusher === null) {
            $this->dispatchUpdate($kernel, $update, $type, $session);

            return;
        }

        if (($this->app['config']['mtproto.surge.isolation'] ?? 'concurrent') === 'sandbox') {
            $this->exclusively(fn () => $flusher->sandbox(function (Application $sandbox) use ($kernel, $update, $type, $session): void {
                $listener = $kernel->getListener();

                $kernel->setApplication($sandbox);
                $listener->setContainer($sandbox);

                try {
                    $this->dispatchUpdate($kernel, $update, $type, $session);
                } finally {
                    $kernel->setApplication($this->app);
                    $listener->setContainer($this->app);
                }
            }));

            return;
        }

        $flusher->begin();

        try {
            $this->dispatchUpdate($kernel, $update, $type, $session);
        } finally {
            $flusher->flush();
        }
    }

    /**
     * Match and handle an update through the kernel.
     */
    protected function dispatchUpdate(ClientKernel $kernel, TLObject $update, string $type, string $session): void
    {
        try {
            $updateData = $update->toArray();

            $this->notifyReceived($updateData, $type, $session);

            $passes = [];

            if (ClientType::isMessage($type)) {
                $message = $updateData['message'] ?? $updateData;
                $text = is_array($message) ? ($message['message'] ?? '') : '';

                if (is_string($text) && str_starts_with($text, '/')) {
                    $passes[] = ClientType::COMMAND->name;
                    $passes[] = ClientType::REFERRAL->name;
                }

                if (is_array($message) && isset($message['media'])) {
                    if (($message['media']['_'] ?? '') === 'messageMediaDice') {
                        $passes[] = ClientType::DICE->name;
                    }

                    if (ClientType::mediaTypeFromMessage($message) !== null) {
                        $passes[] = ClientType::MESSAGE->name;
                    }
                }

                if (is_array($message) && !empty($message['entities'])) {
                    $passes[] = ClientType::ENTITIES->name;
                }

                $passes[] = ClientType::TEXT->name;
            }

            if (ClientType::isCallback($type)) {
                $passes[] = ClientType::CALLBACK_DATA->name;
            }

            $passes[] = ClientType::UPDATE->name;

            $state = (object) ['done' => false];

            foreach ($passes as $verb) {
                $this->handle($kernel, $updateData, $type, $session, $verb, $state);
            }
        } catch (Throwable $e) {
            $this->logError($session, $type, $e);
        }
    }

    /**
     * Dispatch the UpdateReceived event, isolated from matching: listeners receive a
     * copy of the update and their exceptions never stop it from being dispatched.
     *
     * @param array<string, mixed> $updateData
     */
    protected function notifyReceived(array $updateData, string $type, string $session): void
    {
        try {
            $events = $this->app['events'];

            if ($events->hasListeners(UpdateReceived::class)) {
                $events->dispatch(new UpdateReceived($updateData, $type, $session));
            }
        } catch (Throwable $e) {
            $this->logError($session, $type, $e);
        }
    }

    /**
     * Get the Surge state flusher (Surge-hosted only).
     */
    protected function flusher(): ?\LaraGram\Surge\Flusher
    {
        if ($this->flusher === null) {
            if (!class_exists(\LaraGram\Surge\Flusher::class) || !$this->app->bound('surge')) {
                return null;
            }

            $this->flusher = new \LaraGram\Surge\Flusher($this->app);
        }

        return $this->flusher;
    }

    /**
     * Run the callback while no other update is being handled.
     */
    protected function exclusively(callable $callback): void
    {
        $runtime = $this->app->make(Runtime::class);

        if (!$runtime->isSupported() || !$runtime->inCoroutine()) {
            $callback();

            return;
        }

        $this->lock ??= $runtime->channel(1);
        $this->lock->push(true);

        try {
            $callback();
        } finally {
            $this->lock->pop();
        }
    }

    /**
     * Build a session-tagged ClientRequest and run it through the kernel.
     */
    protected function handle(
        ClientKernel $kernel,
        array        $updateData,
        string       $type,
        string       $session,
        ?string      $verb = null,
        ?object      $state = null,
    ): void
    {
        $request = ClientRequest::fromUpdate($updateData, $type, $session);

        $request->setClient($this->app['mtproto.manager']->client($session));

        if ($verb !== null) {
            $request->setListenVerb($verb);
        }

        if ($state !== null) {
            $request->setDispatchState($state);
        }

        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } catch (ListenNotFoundException) {
            //
        }
    }

    protected function logError(string $session, string $type, Throwable $e): void
    {
        try {
            \LaraGram\Support\Facades\Log::error(
                "[client:{$session}] Error dispatching {$type}: {$e->getMessage()}",
                ['exception' => $e],
            );
        } catch (Throwable) {
            error_log("[client:{$session}] Error dispatching {$type}: {$e->getMessage()}");
        }
    }
}
