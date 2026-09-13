<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

use LaraGram\MTProto\Contracts\Invoker;
use LaraGram\MTProto\Exceptions\MTProtoException;

final class RemoteInvoker implements Invoker
{
    public function __construct(
        private readonly string $socket,
        private readonly string $session,
        private readonly float $timeout = 30.0,
    ) {
    }

    public function session(): string
    {
        return $this->session;
    }

    public function invoke(string $method, array $params = []): mixed
    {
        return $this->request(['type' => 'invoke', 'method' => $method, 'arguments' => $params]);
    }

    public function call(string $method, array $arguments = []): mixed
    {
        return $this->request(['type' => 'call', 'method' => $method, 'arguments' => $arguments]);
    }

    public function isRemote(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function request(array $request): mixed
    {
        $stream = @stream_socket_client('unix://' . $this->socket, $errno, $error, $this->timeout);

        if ($stream === false) {
            throw new MTProtoException("The MTProto pump is not reachable at [{$this->socket}]: {$error}");
        }

        try {
            stream_set_timeout($stream, (int) $this->timeout, (int) (fmod($this->timeout, 1.0) * 1_000_000));

            Frame::write($stream, ['session' => $this->session, ...$request]);

            $response = Frame::read($stream)
                ?? throw new MTProtoException('The MTProto pump closed the connection without a response.');
        } finally {
            fclose($stream);
        }

        if (($response['ok'] ?? false) === true) {
            return $response['result'] ?? null;
        }

        throw new RemoteInvocationException(
            (string) ($response['error']['message'] ?? 'Unknown MTProto RPC error.'),
            (int) ($response['error']['code'] ?? 0),
            (string) ($response['error']['class'] ?? MTProtoException::class),
        );
    }
}
