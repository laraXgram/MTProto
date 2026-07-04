<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening\Middleware;

use Closure;
use LaraGram\MTProto\Foundation\ClientRequest;

/**
 * Filter MTProto listens by message direction (incoming vs outgoing).
 */
class Direction
{
    /**
     * Handle an incoming client request.
     *
     * @param  ClientRequest  $request
     * @param  Closure  $next
     * @param  string  $direction  'in' or 'out'
     */
    public function handle($request, Closure $next, string $direction)
    {
        $isOutgoing = $request->isOutgoing();

        if ($direction === 'out' ? $isOutgoing : !$isOutgoing) {
            return $next($request);
        }

        return false;
    }
}
