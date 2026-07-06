<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Container\Container;
use LaraGram\Contracts\Events\Dispatcher;
use LaraGram\Listening\Listen;
use LaraGram\Listening\ListenCollection;
use LaraGram\Listening\Listener;
use LaraGram\Listening\Pipeline;
use LaraGram\Listening\Contracts\ProvidesListenContext;
use LaraGram\MTProto\Core\Client as MTProtoClient;
use LaraGram\MTProto\Foundation\ClientHandlerTrait;
use LaraGram\MTProto\Foundation\ClientRequest;
use LaraGram\Support\Str;

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
     * @param array|string|callable|null $action
     * @return \LaraGram\Listening\Listen
     */
    public function fallback($action)
    {
        return $this->addListen('UPDATE', '*', $action)->fallback();
    }

    /**
     * Dispatch an update to a listen, enforcing single-primary semantics.
     *
     * @param \LaraGram\Listening\Contracts\ProvidesListenContext $request
     * @return mixed
     */
    public function dispatchToListen(ProvidesListenContext $request)
    {
        $listen = $this->findListen($request);

        $isClient = $request instanceof ClientRequest;

        $suppress = $isClient && $request->dispatchDone() && ! $listen->overlap;

        if ($suppress) {
            $response = $this->prepareResponse($request, null);
        } else {
            $response = $this->runListen($request, $listen);

            if ($isClient && ! $listen->isFallback && ! $listen->overlap) {
                $request->markDispatchDone();
            }
        }

        $this->runOverlapListens($request, $listen);

        return $response;
    }

    /**
     * Register a listen — but ONLY when inside a Client group context.
     *
     * @param array|string $methods
     * @param string $pattern
     * @param array|string|callable|null $action
     * @return \LaraGram\Listening\Listen
     */
    public function addListen($methods, $pattern, $action)
    {
        if (!$this->hasGroupStack()) {
            return $this->createListen($methods, $pattern, $action);
        }

        return parent::addListen($methods, $pattern, $action);
    }

    /**
     * Resolve the live MTProto Client for a given session. the entry point for
     * sending *outside* an update handler, or to a non-originating account:
     */
    public function session(string $name = 'default'): MTProtoClient
    {
        return $this->container->make('mtproto.manager')->client($name);
    }

    /**
     * Create a new Listen object.
     *
     * @param array|string $methods
     * @param string $pattern
     * @param mixed $action
     * @return \LaraGram\Listening\Listen
     */
    public function newListen($methods, $pattern, $action)
    {
        return (new ClientListen($methods, $pattern, $action))
            ->setListener($this)
            ->setContainer($this->container);
    }

    /**
     * Dynamically handle calls into the listener.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if ($method === 'middleware') {
            return (new ClientListenRegistrar($this))->attribute(
                $method, is_array($parameters[0]) ? $parameters[0] : $parameters
            );
        }

        if (in_array($method, ['forSessions', 'forConnections', 'incomming', 'outgoing'], true)) {
            return (new ClientListenRegistrar($this))->{$method}(...$parameters);
        }

        if ($method !== 'where' && Str::startsWith($method, 'where')) {
            return (new ClientListenRegistrar($this))->{$method}(...$parameters);
        }

        return (new ClientListenRegistrar($this))->attribute(
            $method, array_key_exists(0, $parameters) ? $parameters[0] : true
        );
    }

    /**
     * Run the given listen within a Stack "onion" instance.
     *
     * @param \LaraGram\Listening\Listen $listen
     * @param \LaraGram\Listening\Contracts\ProvidesListenContext $request
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
            ->then(fn($request) => $this->prepareResponse(
                $request, $listen->run()
            ));
    }
}
