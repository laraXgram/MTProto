<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Driver\Sync;

use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\DriverInterface;
use LaraGram\MTProto\Exceptions\ConnectionException;
use Socket;

/**
 * Synchronous TCP connection implementation.
 * 
 * Uses PHP's native socket functions for blocking I/O.
 * This is the default driver - no external dependencies required.
 */
class SyncConnection implements ConnectionInterface, DriverInterface
{
    /**
     * Socket resource.
     */
    private ?Socket $socket = null;

    /**
     * Remote address.
     */
    private ?string $remoteAddress = null;

    /**
     * Remote port.
     */
    private ?int $remotePort = null;

    /**
     * Read buffer (unused but kept for future use).
     */
    private string $buffer = '';

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'sync';
    }

    /**
     * {@inheritdoc}
     */
    public function isAsync(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $address, int $port, float $timeout = 10.0): bool
    {
        // Close existing connection
        $this->disconnect();

        // Create socket
        $socket = @socket_create(
            $this->isIPv6($address) ? AF_INET6 : AF_INET,
            SOCK_STREAM,
            SOL_TCP
        );

        if ($socket === false) {
            throw ConnectionException::connectionFailed(
                $address,
                $port,
                socket_strerror(socket_last_error())
            );
        }

        // Set socket options
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $this->timeoutToArray($timeout));
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $this->timeoutToArray($timeout));
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);

        // Connect with timeout
        $startTime = microtime(true);
        socket_set_nonblock($socket);

        $result = @socket_connect($socket, $address, $port);
        
        if ($result === false) {
            $error = socket_last_error($socket);
            
            // EINPROGRESS is expected for non-blocking connect
            if ($error !== SOCKET_EINPROGRESS && $error !== SOCKET_EALREADY) {
                socket_close($socket);
                throw ConnectionException::connectionFailed($address, $port, socket_strerror($error));
            }

            // Wait for connection with timeout
            $read = [];
            $write = [$socket];
            $except = [];
            
            $timeoutSec = (int) $timeout;
            $timeoutUsec = (int) (($timeout - $timeoutSec) * 1000000);

            $result = socket_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($result === false) {
                socket_close($socket);
                throw ConnectionException::connectionFailed($address, $port, socket_strerror(socket_last_error()));
            }

            if ($result === 0) {
                socket_close($socket);
                throw ConnectionException::timeout($address, $port, $timeout);
            }

            // Check if connection succeeded
            $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
            if ($error !== 0) {
                socket_close($socket);
                throw ConnectionException::connectionFailed($address, $port, socket_strerror($error));
            }
        }

        // Switch back to blocking mode
        socket_set_block($socket);

        // Recalculate remaining timeout
        $elapsed = microtime(true) - $startTime;
        $remainingTimeout = max(1.0, $timeout - $elapsed);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $this->timeoutToArray($remainingTimeout));
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $this->timeoutToArray($remainingTimeout));

        $this->socket = $socket;
        $this->remoteAddress = $address;
        $this->remotePort = $port;
        $this->buffer = '';

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @socket_shutdown($this->socket, 2);
            @socket_close($this->socket);
            $this->socket = null;
        }

        $this->remoteAddress = null;
        $this->remotePort = null;
        $this->buffer = '';
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $data): int
    {
        if (!$this->isConnected()) {
            throw ConnectionException::notConnected();
        }

        $totalSent = 0;
        $length = strlen($data);

        while ($totalSent < $length) {
            $sent = @socket_send($this->socket, substr($data, $totalSent), $length - $totalSent, 0);

            if ($sent === false) {
                $error = socket_last_error($this->socket);
                $this->disconnect();
                throw ConnectionException::sendFailed(socket_strerror($error));
            }

            if ($sent === 0) {
                $this->disconnect();
                throw ConnectionException::closed();
            }

            $totalSent += $sent;
        }

        return $totalSent;
    }

    /**
     * {@inheritdoc}
     */
    public function receive(int $length = 0, float $timeout = 30.0): ?string
    {
        if (!$this->isConnected()) {
            throw ConnectionException::notConnected();
        }

        // Set read timeout
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $this->timeoutToArray($timeout));

        // If length is 0, read all available data
        if ($length === 0) {
            $data = @socket_read($this->socket, 65536, PHP_BINARY_READ);
            
            if ($data === false) {
                $error = socket_last_error($this->socket);
                if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) {
                    return null; // Timeout
                }
                $this->disconnect();
                throw ConnectionException::receiveFailed(socket_strerror($error));
            }

            if ($data === '') {
                $this->disconnect();
                throw ConnectionException::closed();
            }

            return $data;
        }

        // Read exact number of bytes
        $data = '';
        $remaining = $length;
        $startTime = microtime(true);

        while ($remaining > 0) {
            // Check timeout
            if (microtime(true) - $startTime > $timeout) {
                return null;
            }

            $chunk = @socket_read($this->socket, $remaining, PHP_BINARY_READ);

            if ($chunk === false) {
                $error = socket_last_error($this->socket);
                if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) {
                    continue; // Retry on timeout
                }
                $this->disconnect();
                throw ConnectionException::receiveFailed(socket_strerror($error));
            }

            if ($chunk === '') {
                $this->disconnect();
                throw ConnectionException::closed();
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function getRemoteAddress(): ?string
    {
        return $this->remoteAddress;
    }

    /**
     * {@inheritdoc}
     */
    public function getRemotePort(): ?int
    {
        return $this->remotePort;
    }

    /**
     * Check if address is IPv6.
     */
    private function isIPv6(string $address): bool
    {
        return str_contains($address, ':');
    }

    /**
     * Convert float timeout to array for socket options.
     *
     * @return array{sec: int, usec: int}
     */
    private function timeoutToArray(float $timeout): array
    {
        return [
            'sec' => (int) $timeout,
            'usec' => (int) (($timeout - (int) $timeout) * 1000000),
        ];
    }

    /**
     * Destructor - ensure socket is closed.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
