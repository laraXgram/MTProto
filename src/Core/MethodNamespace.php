<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

/**
 * Method namespace wrapper for fluent API.
 * 
 * Allows calling methods like:
 * $client->messages->sendMessage([...])
 * $client->users->getFullUser([...])
 */
class MethodNamespace
{
    /**
     * Client instance.
     */
    private Client $client;

    /**
     * Namespace name.
     */
    private string $namespace;

    /**
     * Create a new MethodNamespace instance.
     */
    public function __construct(Client $client, string $namespace)
    {
        $this->client = $client;
        $this->namespace = $namespace;
    }

    /**
     * Call a method in this namespace.
     *
     * @param string $name Method name
     * @param array $arguments Arguments [params array]
     * @return array Method result
     */
    public function __call(string $name, array $arguments): array
    {
        $method = $this->namespace . '.' . $name;
        $params = $arguments[0] ?? [];
        
        return $this->client->invoke($method, $params);
    }
}
