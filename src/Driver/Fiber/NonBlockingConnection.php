<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Driver\Fiber;

use Fiber;
use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\DriverInterface;
use LaraGram\MTProto\Exceptions\ConnectionException;

/**
 * Non-blocking TCP connection using PHP streams + Fiber suspension.
 *
 * Unlike SyncConnection (which uses blocking socket_read()), this class
 * uses stream_socket_client() with non-blocking mode. When data is not
 * immediately available, the current Fiber suspends instead of blocking.
 *
 * This is the key architectural change inspired by MadelineProto:
 * MadelineProto uses amphp's async Socket which wraps PHP streams with
 * Revolt's event loop. We achieve the same effect using raw Fibers.
 *
 * How it works:
 *   1. connect() creates a non-blocking stream via stream_socket_client()
 *   2. send() writes in a loop, yielding Fiber if buffer is full
 *   3. receive() reads in a loop, yielding Fiber if no data available
 *   4. The FiberEventLoop resumes the Fiber when stream_select() says ready
 *
 * When NOT in a Fiber context (e.g. during auth key generation, initial
 * setup), the connection falls back to blocking mode automatically.
 *
 * Benefits:
 *   - 100 concurrent handlers each calling $client->invoke() will NOT block
 *     each other — they yield and let other Fibers run during I/O waits
 *   - Single TCP connection shared across all Fibers (no fork overhead)
 *   - Compatible with FiberEventLoop's round-robin scheduler
 */
class NonBlockingConnection implements ConnectionInterface, DriverInterface
{
    /**
     * PHP stream resource.
     * @var resource|null
     */
    private $stream = null;

    /**
     * The underlying Socket object (for socket_select compatibility).
     */
    private ?\Socket $socket = null;

    private ?string $remoteAddress = null;
    private ?int $remotePort = null;

    /**
     * Whether to use non-blocking I/O (true when Fibers are available).
     * Falls back to blocking for non-Fiber contexts (auth key gen, etc.)
     */
    private bool $nonBlocking = true;

    public function getName(): string
    {
        return 'fiber';
    }

    public function isAsync(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(string $address, int $port, float $timeout = 10.0): bool
    {
        $this->disconnect();

        $isIPv6 = str_contains($address, ':');
        $scheme = 'tcp://';
        $target = $isIPv6 ? "[{$address}]:{$port}" : "{$address}:{$port}";

        $context = stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            $scheme . $target,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw ConnectionException::connectionFailed($address, $port, "({$errno}) {$errstr}");
        }

        // Set non-blocking mode
        stream_set_blocking($stream, false);

        // Set read/write buffer sizes
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);

        // Enable keepalive via socket option
        if (function_exists('socket_import_stream')) {
            $this->socket = socket_import_stream($stream);
            if ($this->socket !== false && $this->socket !== null) {
                @socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                @socket_set_option($this->socket, SOL_TCP, TCP_NODELAY, 1);
            } else {
                $this->socket = null;
            }
        }

        $this->stream = $stream;
        $this->remoteAddress = $address;
        $this->remotePort = $port;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
        $this->socket = null;
        $this->remoteAddress = null;
        $this->remotePort = null;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }

    /**
     * {@inheritdoc}
     *
     * Writes data to the stream. If running inside a Fiber, yields
     * when the write buffer is full instead of blocking.
     */
    public function send(string $data): int
    {
        if (!$this->isConnected()) {
            throw ConnectionException::notConnected();
        }

        $totalSent = 0;
        $length = strlen($data);
        $startTime = microtime(true);
        $timeout = 30.0;

        while ($totalSent < $length) {
            if (microtime(true) - $startTime > $timeout) {
                $this->disconnect();
                throw ConnectionException::sendFailed("Write timeout after {$timeout}s");
            }

            $chunk = substr($data, $totalSent);
            $sent = @fwrite($this->stream, $chunk);

            if ($sent === false) {
                $this->disconnect();
                throw ConnectionException::sendFailed('fwrite() failed');
            }

            if ($sent === 0) {
                // Buffer full — yield if in Fiber, otherwise wait
                $this->yieldOrWaitWritable($timeout - (microtime(true) - $startTime));
                continue;
            }

            $totalSent += $sent;
        }

        return $totalSent;
    }

    /**
     * {@inheritdoc}
     *
     * Reads data from the stream. If running inside a Fiber, yields
     * when no data is available instead of blocking.
     *
     * This is THE key difference from SyncConnection: socket_read()
     * blocks the entire PHP process. This method yields the Fiber,
     * allowing other Fibers to run while waiting for data.
     */
    public function receive(int $length = 0, float $timeout = 30.0): ?string
    {
        if (!$this->isConnected()) {
            throw ConnectionException::notConnected();
        }

        // Variable-length read
        if ($length === 0) {
            return $this->readAny($timeout);
        }

        // Exact-length read
        return $this->readExact($length, $timeout);
    }

    /**
     * Read any available data (variable length).
     */
    private function readAny(float $timeout): ?string
    {
        $startTime = microtime(true);

        while (true) {
            if (microtime(true) - $startTime > $timeout) {
                return null; // Timeout
            }

            $data = @fread($this->stream, 65536);

            if ($data === false) {
                if (!$this->isConnected()) {
                    throw ConnectionException::closed();
                }
                // Transient error — yield and retry
                $this->yieldOrWaitReadable($timeout - (microtime(true) - $startTime));
                continue;
            }

            if ($data === '') {
                if (feof($this->stream)) {
                    $this->disconnect();
                    throw ConnectionException::closed();
                }
                // No data yet — yield Fiber
                $this->yieldOrWaitReadable($timeout - (microtime(true) - $startTime));
                continue;
            }

            return $data;
        }
    }

    /**
     * Read exact number of bytes, yielding Fiber while waiting.
     */
    private function readExact(int $length, float $timeout): string
    {
        $data = '';
        $remaining = $length;
        $startTime = microtime(true);

        while ($remaining > 0) {
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $timeout) {
                throw ConnectionException::receiveFailed(
                    "Timeout reading {$length} bytes (got " . ($length - $remaining) . ")"
                );
            }

            $chunk = @fread($this->stream, $remaining);

            if ($chunk === false) {
                if (!$this->isConnected()) {
                    throw ConnectionException::closed();
                }
                $this->yieldOrWaitReadable($timeout - $elapsed);
                continue;
            }

            if ($chunk === '') {
                if (feof($this->stream)) {
                    $this->disconnect();
                    throw ConnectionException::closed();
                }
                // No data available — yield to other Fibers
                $this->yieldOrWaitReadable($timeout - $elapsed);
                continue;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Get the underlying stream resource (for stream_select in the event loop).
     *
     * @return resource|null
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Get the underlying Socket object (for socket_select compatibility).
     */
    public function getSocket(): ?\Socket
    {
        return $this->socket;
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
     * Yield the current Fiber while waiting for the stream to become readable.
     *
     * If we're not in a Fiber, falls back to stream_select() blocking.
     * This is the core non-blocking mechanism: instead of blocking on
     * socket_read(), we yield the Fiber so other handlers can run.
     *
     * The suspend value is ['io_read', $stream] — an array containing
     * the I/O type and the specific stream resource. The FiberEventLoop's
     * classifySuspendedFiber() reads this value (returned by $fiber->start()
     * or $fiber->resume()) to know which stream to watch with stream_select().
     */
    private function yieldOrWaitReadable(float $remainingTimeout): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null && $this->nonBlocking && $this->stream !== null) {
            // Inside a Fiber — yield with the stream resource so
            // the event loop can track it for stream_select().
            Fiber::suspend(['io_read', $this->stream]);
            return;
        }

        // Not in a Fiber — blocking fallback
        if ($this->stream === null) {
            return;
        }

        $read = [$this->stream];
        $write = [];
        $except = [];

        $sec = max(0, (int) $remainingTimeout);
        $usec = max(0, (int) (($remainingTimeout - $sec) * 1_000_000));

        @stream_select($read, $write, $except, $sec, $usec);
    }

    /**
     * Yield the current Fiber while waiting for the stream to become writable.
     *
     * See yieldOrWaitReadable() for details on the suspend protocol.
     */
    private function yieldOrWaitWritable(float $remainingTimeout): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber !== null && $this->nonBlocking && $this->stream !== null) {
            Fiber::suspend(['io_write', $this->stream]);
            return;
        }

        // Not in a Fiber — blocking fallback
        if ($this->stream === null) {
            return;
        }

        $read = [];
        $write = [$this->stream];
        $except = [];

        $sec = max(0, (int) $remainingTimeout);
        $usec = max(0, (int) (($remainingTimeout - $sec) * 1_000_000));

        @stream_select($read, $write, $except, $sec, $usec);
    }

    /**
     * Enable or disable non-blocking mode.
     *
     * When disabled, receive()/send() block normally (useful during
     * auth key generation where Fibers aren't running yet).
     */
    public function setNonBlocking(bool $enabled): void
    {
        $this->nonBlocking = $enabled;

        if ($this->stream !== null) {
            stream_set_blocking($this->stream, !$enabled);
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
