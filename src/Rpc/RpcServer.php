<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Rpc;

use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Foundation\ClientManager;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\Log\LoggerInterface;
use Throwable;

final class RpcServer
{
    private bool $running = false;

    /** @var resource|null */
    private $server = null;

    /**
     * @param string[] $sessions
     */
    public function __construct(
        private readonly ClientManager $manager,
        private readonly Runtime $runtime,
        private readonly string $socket,
        private readonly array $sessions,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function serve(): void
    {
        if (!is_dir(dirname($this->socket))) {
            @mkdir(dirname($this->socket), 0755, true);
        }

        if (file_exists($this->socket)) {
            @unlink($this->socket);
        }

        $previous = umask(0177);
        $server = @stream_socket_server('unix://' . $this->socket, $errno, $error);
        umask($previous);

        if ($server === false) {
            $this->logger?->error("[mtproto-rpc] Unable to listen on [{$this->socket}]: {$error}");

            return;
        }

        $this->server = $server;
        $this->running = true;

        $this->logger?->info("[mtproto-rpc] Listening on [{$this->socket}] for sessions: " . implode(', ', $this->sessions));

        while ($this->running) {
            $connection = @stream_socket_accept($server, 60.0);

            if ($connection === false) {
                continue;
            }

            $this->runtime->spawn(fn () => $this->serveConnection($connection));
        }
    }

    public function stop(): void
    {
        $this->running = false;

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        if (file_exists($this->socket)) {
            @unlink($this->socket);
        }
    }

    /**
     * @param resource $connection
     */
    public function serveConnection($connection): void
    {
        try {
            while (($request = Frame::read($connection)) !== null) {
                $response = $this->handle($request);

                try {
                    Frame::write($connection, $response);
                } catch (MTProtoException $e) {
                    Frame::write($connection, ['ok' => false, 'error' => [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'code' => 0,
                    ]]);
                }
            }
        } catch (Throwable $e) {
            $this->logger?->warning('[mtproto-rpc] Connection error: ' . $e->getMessage());
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        try {
            $session = (string) ($request['session'] ?? '');
            $method = (string) ($request['method'] ?? '');
            $arguments = (array) ($request['arguments'] ?? []);

            if (!in_array($session, $this->sessions, true)) {
                throw new MTProtoException("Session [{$session}] is not served by the MTProto pump.");
            }

            $client = $this->manager->client($session);

            $result = match ($request['type'] ?? null) {
                'invoke' => $client->invoke($method, $arguments),
                'call' => ClientCall::run($client, $method, $arguments),
                default => throw new MTProtoException('Unknown MTProto RPC request type.'),
            };

            return ['ok' => true, 'result' => $result];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]];
        }
    }
}
