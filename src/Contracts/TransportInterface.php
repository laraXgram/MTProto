<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

/**
 * Transport interface for MTProto transport protocols.
 * 
 * Handles framing and protocol-specific wrapping of messages.
 * Supports: Abridged, Intermediate, Padded Intermediate, Full
 */
interface TransportInterface
{
    /**
     * Get the transport name.
     */
    public function getName(): string;

    /**
     * Get the initial handshake bytes to send on connection.
     * 
     * @return string Initial bytes (e.g., 0xef for Abridged)
     */
    public function getInitialBytes(): string;

    /**
     * Wrap a payload for sending.
     *
     * @param string $payload Raw MTProto payload
     * @return string Wrapped data ready for transport
     */
    public function wrap(string $payload): string;

    /**
     * Unwrap received data to extract payload.
     *
     * @param string $data Raw received data
     * @return string Unwrapped MTProto payload
     * @throws \LaraGram\MTProto\Exceptions\TransportException
     */
    public function unwrap(string $data): string;

    /**
     * Read the length prefix from connection.
     *
     * @param ConnectionInterface $connection
     * @return int Expected payload length
     * @throws \LaraGram\MTProto\Exceptions\TransportException
     */
    public function readLength(ConnectionInterface $connection): int;
}
