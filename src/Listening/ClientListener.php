<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Container\Container;
use LaraGram\Contracts\Events\Dispatcher;
use LaraGram\Listening\Listen;
use LaraGram\Listening\ListenCollection;
use LaraGram\Listening\Listener;
use LaraGram\Listening\Pipeline;
use LaraGram\MTProto\Foundation\ClientHandlerTrait;
use LaraGram\MTProto\Foundation\ClientType;

/**
 * Client Listener — routing/dispatch for MTProto updates.
 *
 * Since the framework Listening engine now reads the matchable value from the
 * request via the `ProvidesListenContext` contract (which `ClientRequest`
 * implements), this listener no longer needs to re-implement matching/binding.
 * It is a thin subclass of the framework `Listener` that:
 *   - swaps the Bot-API verbs/handlers for native MTProto ones (ClientHandlerTrait),
 *   - registers fallbacks against MTProto verbs (not Bot-API verbs),
 *   - strips the per-listen `connection` action (which would reach for the
 *     Bot-API `app('request')` that does not exist in the MTProto lifecycle).
 *
 * Everything else — find/match/bind/run, middleware, events — is inherited.
 */
class ClientListener extends Listener
{
    use ClientHandlerTrait;

    /**
     * Create a new ClientListener instance with its own listen collection so
     * Bot and Client listens never collide.
     */
    public function __construct(Dispatcher $events, ?Container $container = null)
    {
        $this->events = $events;
        $this->listens = new ListenCollection;
        $this->container = $container ?: new Container;
    }

    /**
     * Register a fallback listen across every MTProto verb.
     *
     * Overrides the base implementation, which spreads the fallback across the
     * Bot-API verb table (Listener::$verbs).
     *
     * @param  array|string|callable|null  $action
     * @return \LaraGram\Listening\Listen
     */
    public function fallback($action)
    {
        $placeholder = 'clientFallbackPlaceholder';

        return $this->addListen(
            ClientType::verbs(), "{{$placeholder}}", $action
        )->where($placeholder, '.*')->fallback();
    }

    /**
     * Run the given listen within a Stack "onion" instance.
     *
     * Identical to the parent, except it removes the `connection` action — that
     * action triggers `app('request')` in Listen::run(), which is a Bot-API
     * concept absent from the MTProto Client lifecycle.
     *
     * @param  \LaraGram\Listening\Listen  $listen
     * @param  \LaraGram\Listening\Contracts\ProvidesListenContext  $request
     * @return mixed
     */
    protected function runListenWithinStack(Listen $listen, $request)
    {
        unset($listen->action['connection']);

        $shouldSkipMiddleware = $this->container->bound('middleware.disable') &&
            $this->container->make('middleware.disable') === true;

        $middleware = $shouldSkipMiddleware ? [] : $this->gatherListenMiddleware($listen);

        return (new Pipeline($this->container))
            ->send($request)
            ->through($middleware)
            ->then(fn ($request) => $this->prepareResponse(
                $request, $listen->run()
            ));
    }
}
