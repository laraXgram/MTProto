<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Foundation;

use LaraGram\Contracts\Debug\ExceptionHandler;
use LaraGram\Foundation\Application;
use LaraGram\Foundation\Events\Terminating;
use LaraGram\Listening\Exceptions\ListenNotFoundException;
use LaraGram\Listening\Pipeline;
use LaraGram\MTProto\Listening\ClientListener;
use LaraGram\Request\Response;
use LaraGram\Support\Facades\Facade;
use Throwable;

/**
 * Kernel for dispatching MTProto Client updates.
 *
 * Mirrors LaraGram\Foundation\Bot\Kernel but uses:
 * - Its own Listener instance ('client.listener') with its own listens
 * - Its own middleware group ('client')
 * - ClientRequest instead of Request
 * - Binds to 'client.request' in the container (not 'request')
 *
 * Shares the same bootstrappers so all framework services
 * (DB, Cache, Redis, Queue, Filesystem, etc.) are available.
 */
class ClientKernel
{
    protected Application    $app;
    protected ClientListener $listener;

    /**
     * The bootstrap classes for the application.
     */
    protected array $bootstrappers = [
        \LaraGram\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        \LaraGram\Foundation\Bootstrap\LoadConfiguration::class,
        \LaraGram\Foundation\Bootstrap\HandleExceptions::class,
        \LaraGram\Foundation\Bootstrap\RegisterFacades::class,
        \LaraGram\Foundation\Bootstrap\RegisterProviders::class,
        \LaraGram\Foundation\Bootstrap\BootProviders::class,
    ];

    /**
     * The application's global middleware stack for client updates.
     */
    protected array $middleware = [];

    /**
     * The application's middleware groups.
     */
    protected array $middlewareGroups = [
        'client' => [
            \LaraGram\MTProto\Listening\Middleware\ClientSubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     */
    protected array $middlewareAliases = [];

    /**
     * Priority-sorted list of middleware.
     */
    public array $middlewarePriority = [];

    public function __construct(Application $app, ClientListener $listener)
    {
        $this->app = $app;
        $this->listener = $listener;

        $this->syncMiddlewareToListener();
    }

    /**
     * Handle an incoming MTProto update.
     *
     * @throws ListenNotFoundException when no handler is registered for this update type
     */
    public function handle(ClientRequest $request): Response
    {
        try {
            $response = $this->sendRequestThroughListener($request);
        } catch (ListenNotFoundException $e) {
            // Let this propagate - caller decides whether to ignore it
            throw $e;
        } catch (Throwable $e) {
            $this->reportException($e);
            $response = new Response($e->getLine() . ':' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Send the request through middleware → Listener → dispatch.
     */
    protected function sendRequestThroughListener(ClientRequest $request): Response
    {
        $this->app->instance('client.request', $request);
        $this->app->instance(ClientRequest::class, $request);

        Facade::clearResolvedInstance('client.request');

        $this->bootstrap();

        return (new Pipeline($this->app))
            ->send($request)
            ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
            ->then($this->dispatchToListener());
    }

    /**
     * Bootstrap the application (only once).
     */
    public function bootstrap(): void
    {
        if (!$this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers);
        }
    }

    /**
     * Get the listen dispatcher callback.
     */
    protected function dispatchToListener(): \Closure
    {
        return function ($request) {
            $this->app->instance('client.request', $request);
            return $this->listener->dispatch($request);
        };
    }

    /**
     * Terminate the request lifecycle.
     */
    public function terminate(ClientRequest $request, Response $response): void
    {
        $this->app['events']->dispatch(new Terminating);
        $this->app->terminate();
    }

    public function setGlobalMiddleware(array $middleware): static
    {
        $this->middleware = $middleware;
        $this->syncMiddlewareToListener();
        return $this;
    }

    public function setMiddlewareGroups(array $groups): static
    {
        $this->middlewareGroups = $groups;
        $this->syncMiddlewareToListener();
        return $this;
    }

    public function setMiddlewareAliases(array $aliases): static
    {
        $this->middlewareAliases = $aliases;
        $this->syncMiddlewareToListener();
        return $this;
    }

    public function setMiddlewarePriority(array $priority): static
    {
        $this->middlewarePriority = $priority;
        $this->syncMiddlewareToListener();
        return $this;
    }

    protected function syncMiddlewareToListener(): void
    {
        $this->listener->middlewarePriority = $this->middlewarePriority;

        foreach ($this->middlewareGroups as $key => $middleware) {
            $this->listener->middlewareGroup($key, $middleware);
        }

        foreach ($this->middlewareAliases as $key => $middleware) {
            $this->listener->aliasMiddleware($key, $middleware);
        }
    }

    public function getApplication(): Application
    {
        return $this->app;
    }

    protected function reportException(Throwable $e): void
    {
        try {
            $this->app[ExceptionHandler::class]->report($e);
        } catch (Throwable) {
            // ExceptionHandler not bound - try Log facade
            try {
                \LaraGram\Support\Facades\Log::error("[ClientKernel] {$e->getMessage()}", [
                    'exception' => $e,
                ]);
            } catch (Throwable) {
                error_log("[ClientKernel] Exception: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
            }
        }
    }
}
