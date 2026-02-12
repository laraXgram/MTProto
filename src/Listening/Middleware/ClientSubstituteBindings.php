<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening\Middleware;

use Closure;
use LaraGram\MTProto\Listening\ClientListener;

/**
 * Substitute bindings for MTProto Client listens.
 *
 * This replaces the default SubstituteBindings middleware (which resolves
 * the Bot API Registrar and calls app('request')). For MTProto Client
 * listens, we use the ClientListener directly and handle the ClientRequest
 * object that is already available.
 */
class ClientSubstituteBindings
{
    protected ClientListener $listener;

    public function __construct(ClientListener $listener)
    {
        $this->listener = $listener;
    }

    /**
     * Handle an incoming client request.
     */
    public function handle($request, Closure $next)
    {
        $listen = $request->listen();

        if ($listen !== null) {
            $this->listener->substituteBindings($listen);

            // Skip implicit bindings — they rely on Bot API Request object
            // which doesn't exist in MTProto context. ClientRequest parameters
            // are already resolved by ClientListenParameterBinder.
        }

        return $next($request);
    }
}
