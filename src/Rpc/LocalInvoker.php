<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

use LaraGram\MTProto\Contracts\Invoker;
use LaraGram\MTProto\Core\Client;

final class LocalInvoker implements Invoker
{
    public function __construct(private readonly Client $client, private readonly string $session)
    {
    }

    public function session(): string
    {
        return $this->session;
    }

    public function invoke(string $method, array $params = []): mixed
    {
        return $this->client->invoke($method, $params);
    }

    public function call(string $method, array $arguments = []): mixed
    {
        return ClientCall::run($this->client, $method, $arguments);
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function client(): Client
    {
        return $this->client;
    }
}
