<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Container\Container;
use LaraGram\Contracts\Events\Dispatcher;
use LaraGram\Listening\Listen;
use LaraGram\Listening\ListenCollection;
use LaraGram\Listening\Listener;
use LaraGram\Listening\Pipeline;
use LaraGram\Listening\Events\ListenMatched;
use LaraGram\Listening\Events\Listening;
use LaraGram\Listening\Events\PreparingResponse;
use LaraGram\Listening\Events\ResponsePrepared;
use LaraGram\Listening\Exceptions\ListenNotFoundException;
use LaraGram\Listening\HandlerTrait;
use LaraGram\MTProto\Foundation\ClientHandlerTrait;
use LaraGram\MTProto\Foundation\ClientRequest;
use LaraGram\MTProto\Listening\Matching\ClientPatternValidator;
use LaraGram\Request\Response;
use LaraGram\Support\Str;

/**
 * Client Listener — dedicated routing/dispatch for MTProto updates.
 */
class ClientListener extends Listener
{
    use ClientHandlerTrait;

    /**
     * All of the verbs supported by the client listener.
     *
     * @var string[]
     */
    public static $verbs = [
        'NEW_MESSAGE', 'EDIT_MESSAGE', 'DELETED_MESSAGES',
        'CALLBACK_QUERY', 'INLINE_QUERY',
        'TYPING', 'READ_HISTORY', 'REACTIONS',
        'USER_STATUS', 'CHAT_PARTICIPANT',
        'PRE_CHECKOUT', 'SHIPPING',
        'UPDATE',
    ];

    /**
     * Overridden validators for the client listener.
     *
     * @var array|null
     */
    protected static $clientValidators = null;

    /**
     * Create a new ClientListener instance.
     */
    public function __construct(Dispatcher $events, ?Container $container = null)
    {
        $this->events = $events;
        $this->listens = new ListenCollection;
        $this->container = $container ?: new Container;
    }

    /**
     * Register a fallback listen for client updates.
     */
    public function fallback($action)
    {
        $placeholder = 'clientFallbackPlaceholder';

        return $this->addListen(
            static::$verbs, "{{$placeholder}}", $action
        )->where($placeholder, '.*')->fallback();
    }

    /**
     * Dispatch a ClientRequest to the listener.
     *
     * @param  ClientRequest  $request
     * @return Response
     */
    public function dispatch($request)
    {
        $this->currentRequest = $request;

        return $this->dispatchToListen($request);
    }

    /**
     * Dispatch the request to a listen and return the response.
     *
     * @param  ClientRequest  $request
     * @return Response
     */
    public function dispatchToListen($request)
    {
        return $this->runListen($request, $this->findListen($request));
    }

    /**
     * Find the listen matching a given client request.
     *
     * @param  ClientRequest  $request
     * @return Listen
     */
    protected function findListen($request)
    {
        $this->events->dispatch(new Listening($request));

        $this->current = $listen = $this->matchClientListen($request);

        $listen->setContainer($this->container);

        $this->container->instance(Listen::class, $listen);

        return $listen;
    }

    /**
     * Match a client request against registered listens.
     *
     * @param  ClientRequest  $request
     * @return Listen
     */
    protected function matchClientListen(ClientRequest $request): Listen
    {
        $verb = $request->method();
        $listens = $this->listens->get($verb);

        if (!empty($listens)) {
            $listen = $this->matchAgainstClientListens($listens, $request);
            if ($listen !== null) {
                return $listen->bind($request);
            }
        }

        // Try UPDATE verb (catch-all)
        if ($verb !== 'UPDATE') {
            $updateListens = $this->listens->get('UPDATE');
            if (!empty($updateListens)) {
                $listen = $this->matchAgainstClientListens($updateListens, $request);
                if ($listen !== null) {
                    return $listen->bind($request);
                }
            }
        }

        // Check all verbs for a fallback
        $allListens = $this->listens->getListens();
        foreach ($allListens as $listen) {
            if ($listen->isFallback) {
                return $listen->bind($request);
            }
        }

        throw new ListenNotFoundException(sprintf(
            'The client listen for "%s" could not be found.',
            $request->type()
        ));
    }

    /**
     * Match against an array of listens.
     *
     * @param  array  $listens
     * @param  ClientRequest  $request
     * @return Listen|null
     */
    protected function matchAgainstClientListens(array $listens, ClientRequest $request): ?Listen
    {
        $validator = new ClientPatternValidator;

        $fallbacks = [];
        $regular = [];

        foreach ($listens as $listen) {
            if ($listen->isFallback) {
                $fallbacks[] = $listen;
            } else {
                $regular[] = $listen;
            }
        }

        foreach (array_merge($regular, $fallbacks) as $listen) {
            // Check method (verb) match
            if (!in_array($request->method(), $listen->methods())) {
                continue;
            }

            // Check pattern match
            if ($validator->matchesClient($listen, $request)) {
                return $listen;
            }
        }

        return null;
    }

    /**
     * Return the response for the given listen.
     *
     * @param  ClientRequest  $request
     * @param  Listen  $listen
     * @return Response
     */
    protected function runListen($request, Listen $listen)
    {
        $request->setListenResolver(fn () => $listen);

        $this->events->dispatch(new ListenMatched($listen, $request));

        return $this->prepareResponse($request,
            $this->runListenWithinStack($listen, $request)
        );
    }

    /**
     * Run the given listen within a Stack "onion" instance.
     *
     * @param  Listen  $listen
     * @param  ClientRequest  $request
     * @return mixed
     */
    protected function runListenWithinStack(Listen $listen, $request)
    {
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

    /**
     * Create a response instance from the given value.
     *
     * @param  mixed  $request
     * @param  mixed  $response
     * @return Response
     */
    public function prepareResponse($request, $response)
    {
        $this->events->dispatch(new PreparingResponse($request, $response));

        return tap(static::toResponse($request, $response), function ($response) use ($request) {
            $this->events->dispatch(new ResponsePrepared($request, $response));
        });
    }

    /**
     * Dynamically handle calls into the listener instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if ($method === 'middleware') {
            return (new ClientListenRegistrar($this))->attribute($method, is_array($parameters[0]) ? $parameters[0] : $parameters);
        }

        if ($method !== 'where' && Str::startsWith($method, 'where')) {
            return (new ClientListenRegistrar($this))->{$method}(...$parameters);
        }

        return (new ClientListenRegistrar($this))->attribute($method, array_key_exists(0, $parameters) ? $parameters[0] : true);
    }
}
