<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

use Generator;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Exceptions\MTProtoException;
use ReflectionMethod;

final class ClientCall
{
    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function run(Client $client, string $method, array $arguments): mixed
    {
        if (str_starts_with($method, '__') || !method_exists($client, $method)) {
            throw new MTProtoException("Unknown MTProto client method [{$method}].");
        }

        $reflection = new ReflectionMethod($client, $method);

        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new MTProtoException("MTProto client method [{$method}] is not callable.");
        }

        $result = $client->{$method}(...$arguments);

        return $result instanceof Generator ? iterator_to_array($result, false) : $result;
    }
}
