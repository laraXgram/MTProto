<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Contracts\Foundation\Application;
use LaraGram\Listening\Exceptions\ListenNotFoundException;
use LaraGram\MTProto\TL\TLObject;
use Throwable;

class ClientDispatcher
{
    /**
     * Lazily-built no-clone state flusher (null when Surge is absent).
     */
    protected ?object $flusher = null;

    public function __construct(protected Application $app)
    {
    }

    /**
     * Route an update for the given session.
     */
    public function dispatch(ClientKernel $kernel, TLObject $update, string $type, string $session): void
    {
        try {
            $updateData = $update->toArray();

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
        } finally {
            $this->flushState();
        }
    }

    /**
     * Release leak-prone state after an update (Surge-hosted only).
     */
    protected function flushState(): void
    {
        if ($this->flusher === null) {
            if (!class_exists(\LaraGram\Surge\Flusher::class)) {
                return;
            }

            $this->flusher = new \LaraGram\Surge\Flusher($this->app);
        }

        $this->flusher->flush();
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
