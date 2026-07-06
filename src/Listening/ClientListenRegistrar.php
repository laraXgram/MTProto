<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Listening;

use LaraGram\Listening\ListenRegistrar;

class ClientListenRegistrar extends ListenRegistrar
{
    /**
     * Scope the following listens to one or more sessions (accounts).
     *
     * @param  array|string  $sessions
     * @return $this
     */
    public function forSessions(array|string $sessions)
    {
        return $this->attribute('for_connections', (array) $sessions);
    }

    /**
     * Scope the following listens to incoming messages only (received, not sent by this session).
     *
     * @return $this
     */
    public function incomming()
    {
        return $this->attribute('middleware', array_merge(
            (array) ($this->attributes['middleware'] ?? []), ['direction:in']
        ));
    }

    /**
     * Scope the following listens to outgoing messages only (sent by this session).
     *
     * @return $this
     */
    public function outgoing()
    {
        return $this->attribute('middleware', array_merge(
            (array) ($this->attributes['middleware'] ?? []), ['direction:out']
        ));
    }

    /**
     * Register a listen through the listener, carrying this registrar's
     * attributes (middleware, session scope, …) via a transient group.
     *
     * @param  string  $method
     * @param  mixed  ...$parameters
     * @return \LaraGram\Listening\Listen
     */
    protected function registerListen($method, ...$parameters)
    {
        $listen = null;

        $this->listener->group($this->attributes, function () use ($method, $parameters, &$listen) {
            $listen = $this->listener->{$method}(...$parameters);
        });

        return $listen;
    }


    /**
     * Dynamically handle calls into the registrar.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return \LaraGram\Listening\Listen|$this
     */
    public function __call($method, $parameters)
    {
        if (str_starts_with(strtolower($method), 'on')) {
            return $this->registerListen($method, ...$parameters);
        }

        return parent::__call($method, $parameters);
    }
}
