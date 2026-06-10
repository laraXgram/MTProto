<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

/**
 * Connection interface for MTProto transport layer.
 * 
 * Handles raw TCP/socket connections to Telegram servers.
 * Both sync and async drivers must implement this interface.
 */
interface ConnectionInterface
{
    /**
     * Connect to the server.
     *
     * @param string $address Server address (IP or hostname)
     * @param int $port Server port
     * @param float $timeout Connection timeout in seconds
     * @return bool True on success
     * @throws \LaraGram\MTProto\Exceptions\ConnectionException
     */
    public function connect(string $address, int $port, float $timeout = 10.0): bool;

    /**
     * Disconnect from the server.
     */
    public function disconnect(): void;

    /**
     * Check if connected.
     */
    public function isConnected(): bool;

    /**
     * Send raw data.
     *
     * @param string $data Raw binary data to send
     * @return int Number of bytes sent
     * @throws \LaraGram\MTProto\Exceptions\ConnectionException
     */
    public function send(string $data): int;

    /**
     * Receive raw data.
     *
     * @param int $length Number of bytes to receive (0 = all available)
     * @param float $timeout Read timeout in seconds
     * @return string|null Received data or null on timeout
     * @throws \LaraGram\MTProto\Exceptions\ConnectionException
     */
    public function receive(int $length = 0, float $timeout = 30.0): ?string;

    /**
     * Get the remote address.
     */
    public function getRemoteAddress(): ?string;

    /**
     * Get the remote port.
     */
    public function getRemotePort(): ?int;
}
