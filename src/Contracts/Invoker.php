<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

interface Invoker
{
    /**
     * The session the calls run on.
     */
    public function session(): string;

    /**
     * Invoke a raw TL method, e.g. "messages.getHistory".
     *
     * @param array<string, mixed> $params
     */
    public function invoke(string $method, array $params = []): mixed;

    /**
     * Call a public method of the session's Client, e.g. "sendPhoto" or "getMe".
     * Generators are collected into arrays; arguments must be serializable.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function call(string $method, array $arguments = []): mixed;

    /**
     * Whether the calls are forwarded to another process.
     */
    public function isRemote(): bool;
}
