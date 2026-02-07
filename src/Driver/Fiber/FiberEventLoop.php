<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Driver\Fiber;

use Fiber;
use LaraGram\MTProto\Contracts\ConnectionInterface;
use LaraGram\MTProto\Contracts\EventLoopInterface;
use LaraGram\MTProto\Driver\Sync\SyncConnection;

/**
 * Non-blocking PHP Fiber event loop driver — inspired by MadelineProto.
 *
 * MadelineProto achieves 100+ concurrent handlers on a single thread
 * because ALL I/O operations (socket reads, writes, sleeps) SUSPEND
 * the current Fiber instead of blocking. The Revolt event loop resumes
 * the Fiber when data arrives.
 *
 * This driver replicates that architecture without external dependencies:
 *
 * How it works:
 *   1. Each handler dispatched via queueCallback() runs in its own Fiber
 *   2. When a handler calls $client->invoke() (which reads from the socket),
 *      the NonBlockingConnection yields the Fiber with Fiber::suspend(['io_read', $stream])
 *   3. The event loop collects all I/O-waiting Fibers and uses stream_select()
 *      to check which streams have data available
 *   4. Only ready Fibers are resumed — others keep waiting
 *   5. Meanwhile, the main loop continues reading updates from the socket
 *
 * This means 100 concurrent messages → 100 Fibers, all running on one
 * thread but never blocking each other. Each Fiber yields on I/O wait
 * and resumes when data arrives.
 *
 * Key difference from the old FiberEventLoop:
 *   OLD: socket_read() inside receive() BLOCKS the entire PHP process.
 *        Even with Fibers, a blocking C-level call never yields.
 *   NEW: NonBlockingConnection uses stream_socket_client() + fread() in
 *        non-blocking mode. When no data is available, fread() returns ''
 *        and the Fiber suspends. The event loop checks stream_select()
 *        and resumes the Fiber when data arrives.
 *
 * IMPORTANT — CPU-bound work:
 *   PHP is single-threaded. Fibers are NOT threads — they only help with
 *   I/O (network reads/writes, sleeps). A CPU-bound loop like
 *   for($i=0; $i<1000000000; $i++){} will block everything, just like
 *   it does in MadelineProto. There is NO framework in PHP that can make
 *   pure CPU work non-blocking on a single thread.
 *
 *   Solutions for CPU-bound work:
 *     - Use the Fork driver (separate process per handler)
 *     - Insert FiberEventLoop::yield() periodically in long loops
 *     - Offload to a worker process/queue
 *
 * Requirements: PHP >= 8.1 (Fibers)
 *
 * Usage:
 *   $loop = new UpdateLoop($client, new FiberEventLoop());
 *   $loop->onMessage(function (Message $msg, Client $client) {
 *       // This invoke() call yields the Fiber during I/O — other handlers run!
 *       $client->sendMessage(peer: '@user', message: 'Hello');
 *       FiberEventLoop::sleep(5); // Yields, NOT blocking
 *   });
 *   $loop->run();
 */
class FiberEventLoop implements EventLoopInterface
{
    /** @var array{connection: ConnectionInterface, callback: callable}|null */
    private ?array $readWatcher = null;

    /** @var array<string, array{interval: float, callback: callable, nextRun: float}> */
    private array $timers = [];

    /** @var array<string, array{at: float, callback: callable}> */
    private array $delayedCallbacks = [];

    private bool $running = false;

    /** Poll timeout in seconds */
    private float $pollTimeout;

    private int $timerCounter = 0;

    /**
     * Active Fibers being scheduled (round-robin).
     * These are fibers that yielded without a specific reason (plain Fiber::suspend())
     * and will be resumed on the next tick.
     * @var Fiber[]
     */
    private array $fibers = [];

    /**
     * Fibers waiting for I/O (stream_select based).
     *
     * Each entry tracks the Fiber, the I/O type ('io_read'/'io_write'),
     * and the specific stream resource the Fiber is waiting on.
     *
     * The key insight: Fiber::suspend($value) returns $value as the return
     * value of $fiber->start() or $fiber->resume(). This is how we know
     * WHY a Fiber suspended and WHICH stream it's waiting on.
     *
     * @var array<int, array{fiber: Fiber, type: string, stream: resource}>
     */
    private array $ioWaitingFibers = [];

    /**
     * Fibers sleeping (will be resumed after a delay).
     * @var array<int, array{fiber: Fiber, resumeAt: float}>
     */
    private array $sleepingFibers = [];

    /**
     * Fibers queued to start on next tick.
     * @var callable[]
     */
    private array $pendingCallbacks = [];

    /**
     * Singleton instance for static yield()/sleep() helpers.
     */
    private static ?self $instance = null;

    /**
     * I/O waiting fiber counter for unique keys.
     */
    private int $ioWaitingCounter = 0;

    /**
     * @param float $pollTimeout  Seconds to block on each stream_select() cycle.
     *                            Lower = more responsive fibers, higher = less CPU.
     */
    public function __construct(float $pollTimeout = 0.05)
    {
        if (PHP_VERSION_ID < 80100) {
            throw new \RuntimeException(
                'PHP 8.1+ is required for the Fiber driver (Fibers support).'
            );
        }

        $this->pollTimeout = $pollTimeout;
        self::$instance = $this;
    }

    public function getName(): string
    {
        return 'fiber';
    }

    /**
     * Yield from the current handler Fiber.
     *
     * Call this inside your handler to give other handlers and the
     * event loop a chance to run. This is a convenience wrapper
     * around Fiber::suspend().
     *
     * For CPU-bound work, insert this periodically:
     *   for ($i = 0; $i < 1000000; $i++) {
     *       if ($i % 10000 === 0) {
     *           FiberEventLoop::yield(); // let others run
     *       }
     *   }
     */
    public static function yield(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            Fiber::suspend(); // null suspend value → goes to regular fibers queue
        }
    }

    /**
     * Async sleep that yields to the event loop.
     *
     * Unlike PHP's sleep(), this does NOT block the event loop.
     * The Fiber suspends and is automatically resumed after $seconds.
     *
     * MadelineProto equivalent: Amp\delay()
     *
     * @param float $seconds  Duration to sleep (supports fractional seconds).
     */
    public static function sleep(float $seconds): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null || self::$instance === null) {
            // Not in a fiber context — fall back to real sleep
            usleep((int) ($seconds * 1_000_000));
            return;
        }

        // Schedule resumption after delay
        $resumeAt = microtime(true) + $seconds;
        self::$instance->scheduleFiberResume($fiber, $resumeAt);
        Fiber::suspend('sleep'); // suspend value 'sleep' — handled by scheduleFiberResume
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable(ConnectionInterface $connection, callable $callback): void
    {
        $this->readWatcher = [
            'connection' => $connection,
            'callback'   => $callback,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer(float $intervalSeconds, callable $callback): string
    {
        $id = 'fiber_timer_' . (++$this->timerCounter);
        $this->timers[$id] = [
            'interval' => $intervalSeconds,
            'callback' => $callback,
            'nextRun'  => microtime(true) + $intervalSeconds,
        ];
        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function addDelayedCallback(float $delaySeconds, callable $callback): string
    {
        $id = 'fiber_delayed_' . (++$this->timerCounter);
        $this->delayedCallbacks[$id] = [
            'at'       => microtime(true) + $delaySeconds,
            'callback' => $callback,
        ];
        return $id;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(string $timerId): void
    {
        unset($this->timers[$timerId], $this->delayedCallbacks[$timerId]);
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            // 1. Combined I/O poll: check main socket + I/O-waiting Fibers
            $this->pollIO();

            // 2. Run expired timers
            $this->runTimers();

            // 3. Start any pending callbacks as new Fibers
            $this->startPendingFibers();

            // 4. Resume all suspended Fibers (round-robin)
            $this->scheduleFibers();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * {@inheritdoc}
     *
     * Fiber driver: schedules the callback to run in a new Fiber.
     * The Fiber will be started on the next tick and interleaved
     * with other Fibers and socket reads.
     *
     * When the handler calls $client->invoke() (which internally reads
     * from the socket), the NonBlockingConnection yields the Fiber
     * with suspend(['io_read', $stream]). This event loop then uses
     * stream_select() to check readability and resumes the Fiber when
     * data arrives.
     *
     * This is how MadelineProto handles 100+ concurrent handlers:
     * EventLoop::queue($closure, $update) — one Fiber per handler.
     */
    public function queueCallback(callable $callback): void
    {
        $this->pendingCallbacks[] = $callback;
    }

    // ────────────────────────────────────────────────────────────────────
    //  Internals
    // ────────────────────────────────────────────────────────────────────

    /**
     * Schedule a fiber to be resumed after a delay.
     */
    private function scheduleFiberResume(Fiber $fiber, float $resumeAt): void
    {
        $this->sleepingFibers[] = [
            'fiber'    => $fiber,
            'resumeAt' => $resumeAt,
        ];
    }

    /**
     * Classify a suspended Fiber based on its suspend value and route it
     * to the appropriate queue.
     *
     * PHP Fiber API: the value passed to Fiber::suspend($value) is returned
     * as the return value of $fiber->start() or $fiber->resume(). This is
     * how the scheduler knows WHY a Fiber suspended:
     *
     *   - ['io_read', $stream]  → Fiber is waiting for data on $stream
     *   - ['io_write', $stream] → Fiber is waiting to write to $stream
     *   - 'sleep'               → Fiber is sleeping (already in sleepingFibers)
     *   - null / anything else  → Regular yield, resume on next tick
     */
    private function classifySuspendedFiber(Fiber $fiber, mixed $suspendValue): void
    {
        // I/O wait: ['io_read', $stream] or ['io_write', $stream]
        if (is_array($suspendValue) && count($suspendValue) === 2) {
            [$type, $stream] = $suspendValue;
            if (($type === 'io_read' || $type === 'io_write') && is_resource($stream)) {
                $this->ioWaitingFibers[++$this->ioWaitingCounter] = [
                    'fiber'  => $fiber,
                    'type'   => $type,
                    'stream' => $stream,
                ];
                return;
            }
        }

        // Sleep: already handled by scheduleFiberResume() before suspend
        if ($suspendValue === 'sleep') {
            return; // Already in $this->sleepingFibers
        }

        // Regular yield (null or unknown) → resume on next tick
        $this->fibers[] = $fiber;
    }

    /**
     * Combined I/O polling — the heart of the non-blocking architecture.
     *
     * This replaces the old pollSocket() method. Instead of just checking
     * the main update socket, it also checks streams that I/O-waiting
     * Fibers are blocked on.
     *
     * How it works:
     *   1. Build a list of all streams to check (main socket + Fiber streams)
     *   2. Call stream_select() once (multiplexed I/O — like epoll/kqueue)
     *   3. If the main socket is ready, invoke the read callback
     *   4. If any Fiber's stream is ready, move that Fiber to the active queue
     *
     * This is the PHP equivalent of MadelineProto's Revolt EventLoop:
     * all I/O is multiplexed through a single select() call.
     */
    private function pollIO(): void
    {
        $readStreams = [];
        $writeStreams = [];
        $streamToFiberMap = [];

        // Add main update socket (if using NonBlockingConnection with stream)
        $mainStream = null;
        if ($this->readWatcher !== null) {
            $conn = $this->readWatcher['connection'];
            if ($conn instanceof NonBlockingConnection) {
                $stream = $conn->getStream();
                if ($stream !== null && $conn->isConnected()) {
                    $readStreams[] = $stream;
                    $mainStream = $stream;
                }
            } elseif ($conn instanceof SyncConnection) {
                // Legacy SyncConnection — use socket_select
                $this->pollSocketLegacy();
                // Still process I/O-waiting fibers via their own streams
                $this->pollIOWaitingFibers();
                return;
            }
        }

        // Add I/O-waiting Fiber streams
        foreach ($this->ioWaitingFibers as $key => $entry) {
            $stream = $entry['stream'];
            if (!is_resource($stream)) {
                // Stream gone — move fiber back to active
                $this->fibers[] = $entry['fiber'];
                unset($this->ioWaitingFibers[$key]);
                continue;
            }

            if ($entry['type'] === 'io_read') {
                $readStreams[] = $stream;
                $streamToFiberMap[(int) $stream][] = $key;
            } else {
                $writeStreams[] = $stream;
                $streamToFiberMap[(int) $stream][] = $key;
            }
        }

        if (empty($readStreams) && empty($writeStreams)) {
            // No streams to poll — only sleep if no fibers active
            if (empty($this->fibers) && empty($this->pendingCallbacks) && empty($this->sleepingFibers)) {
                usleep((int) ($this->pollTimeout * 1_000_000));
            }
            return;
        }

        // Calculate timeout: 0 if there are active fibers, else normal poll timeout
        $hasFibers = !empty($this->fibers) || !empty($this->pendingCallbacks) || !empty($this->sleepingFibers);
        $timeout = $hasFibers ? 0.0 : $this->pollTimeout;

        $sec = (int) $timeout;
        $usec = (int) (($timeout - $sec) * 1_000_000);

        $except = [];
        $readCopy = $readStreams;
        $writeCopy = $writeStreams;

        $result = @stream_select($readCopy, $writeCopy, $except, $sec, $usec);

        if ($result === false) {
            return; // stream_select error — skip this tick
        }

        if ($result > 0) {
            // Check which streams are ready
            foreach ($readCopy as $readyStream) {
                $streamId = (int) $readyStream;

                // Main update socket?
                if ($mainStream !== null && $readyStream === $mainStream) {
                    ($this->readWatcher['callback'])($this->readWatcher['connection']);
                }

                // I/O-waiting Fiber(s)?
                if (isset($streamToFiberMap[$streamId])) {
                    foreach ($streamToFiberMap[$streamId] as $key) {
                        if (isset($this->ioWaitingFibers[$key])) {
                            $fiber = $this->ioWaitingFibers[$key]['fiber'];
                            unset($this->ioWaitingFibers[$key]);
                            if ($fiber->isSuspended()) {
                                $this->fibers[] = $fiber;
                            }
                        }
                    }
                }
            }

            foreach ($writeCopy as $readyStream) {
                $streamId = (int) $readyStream;
                if (isset($streamToFiberMap[$streamId])) {
                    foreach ($streamToFiberMap[$streamId] as $key) {
                        if (isset($this->ioWaitingFibers[$key])) {
                            $fiber = $this->ioWaitingFibers[$key]['fiber'];
                            unset($this->ioWaitingFibers[$key]);
                            if ($fiber->isSuspended()) {
                                $this->fibers[] = $fiber;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Legacy poll for SyncConnection (socket_select based).
     */
    private function pollSocketLegacy(): void
    {
        if ($this->readWatcher === null) {
            return;
        }

        /** @var SyncConnection $conn */
        $conn = $this->readWatcher['connection'];

        if (!$conn->isConnected()) {
            return;
        }

        $socket = $conn->getSocket();
        if ($socket === null) {
            return;
        }

        $read   = [$socket];
        $write  = [];
        $except = [];

        $hasFibers = !empty($this->fibers) || !empty($this->pendingCallbacks) || !empty($this->sleepingFibers);
        $timeout = $hasFibers ? 0.0 : $this->pollTimeout;

        $sec  = (int) $timeout;
        $usec = (int) (($timeout - $sec) * 1_000_000);

        $result = @socket_select($read, $write, $except, $sec, $usec);

        if ($result > 0) {
            ($this->readWatcher['callback'])($conn);
        }
    }

    /**
     * Poll I/O-waiting fibers when using legacy SyncConnection.
     * Since handler Fibers might use NonBlockingConnection for their own RPCs,
     * we still need to check those streams.
     */
    private function pollIOWaitingFibers(): void
    {
        if (empty($this->ioWaitingFibers)) {
            return;
        }

        $readStreams = [];
        $writeStreams = [];
        $streamToKey = [];

        foreach ($this->ioWaitingFibers as $key => $entry) {
            $stream = $entry['stream'];
            if (!is_resource($stream)) {
                $this->fibers[] = $entry['fiber'];
                unset($this->ioWaitingFibers[$key]);
                continue;
            }

            if ($entry['type'] === 'io_read') {
                $readStreams[] = $stream;
            } else {
                $writeStreams[] = $stream;
            }
            $streamToKey[(int) $stream][] = $key;
        }

        if (empty($readStreams) && empty($writeStreams)) {
            return;
        }

        $except = [];
        $result = @stream_select($readStreams, $writeStreams, $except, 0, 0);

        if ($result > 0) {
            foreach (array_merge($readStreams, $writeStreams) as $readyStream) {
                $streamId = (int) $readyStream;
                if (isset($streamToKey[$streamId])) {
                    foreach ($streamToKey[$streamId] as $key) {
                        if (isset($this->ioWaitingFibers[$key])) {
                            $fiber = $this->ioWaitingFibers[$key]['fiber'];
                            unset($this->ioWaitingFibers[$key]);
                            if ($fiber->isSuspended()) {
                                $this->fibers[] = $fiber;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Run any expired periodic and delayed timers.
     */
    private function runTimers(): void
    {
        $now = microtime(true);

        foreach ($this->timers as $id => &$timer) {
            if ($now >= $timer['nextRun']) {
                try {
                    ($timer['callback'])();
                } catch (\Throwable $e) {
                    error_log("[FiberEventLoop] Timer {$id} error: {$e->getMessage()}");
                }
                $timer['nextRun'] = $now + $timer['interval'];
            }
        }
        unset($timer);

        foreach ($this->delayedCallbacks as $id => $cb) {
            if ($now >= $cb['at']) {
                try {
                    ($cb['callback'])();
                } catch (\Throwable $e) {
                    error_log("[FiberEventLoop] Delayed {$id} error: {$e->getMessage()}");
                }
                unset($this->delayedCallbacks[$id]);
            }
        }
    }

    /**
     * Create Fibers from pending callbacks and start them.
     *
     * When $fiber->start() is called, the Fiber runs until it either:
     *   - Returns (terminated) → discard
     *   - Calls Fiber::suspend($value) → classify based on $value
     *
     * The return value of $fiber->start() IS the value passed to
     * Fiber::suspend(). This is PHP's Fiber API — NOT getReturn().
     */
    private function startPendingFibers(): void
    {
        while (!empty($this->pendingCallbacks)) {
            $callback = array_shift($this->pendingCallbacks);
            $fiber = new Fiber(function () use ($callback): void {
                try {
                    $callback();
                } catch (\Throwable $e) {
                    error_log("[FiberEventLoop] Fiber handler error: {$e->getMessage()}");
                }
            });

            try {
                // $fiber->start() returns the value passed to Fiber::suspend()
                // This is how we know WHY the fiber suspended
                $suspendValue = $fiber->start();

                if ($fiber->isSuspended()) {
                    $this->classifySuspendedFiber($fiber, $suspendValue);
                }
                // If terminated immediately, that's fine — don't add it
            } catch (\Throwable $e) {
                error_log("[FiberEventLoop] Fiber start error: {$e->getMessage()}");
            }
        }
    }

    /**
     * Resume all suspended fibers (round-robin scheduling).
     *
     * This is the cooperative scheduler: each Fiber gets a chance to run
     * on every tick. When a Fiber calls fread() on a non-blocking stream
     * and gets '', it yields with ['io_read', $stream] and gets moved
     * to the I/O waiting queue. On the next tick, stream_select() checks
     * if data is available and only then moves it back to active.
     *
     * MadelineProto equivalent: Revolt EventLoop's microtask queue.
     */
    private function scheduleFibers(): void
    {
        // First: check sleeping fibers and move them back if time is up
        $now = microtime(true);
        foreach ($this->sleepingFibers as $key => $entry) {
            if ($now >= $entry['resumeAt']) {
                $fiber = $entry['fiber'];
                unset($this->sleepingFibers[$key]);
                if ($fiber->isSuspended()) {
                    $this->fibers[] = $fiber;
                }
            }
        }
        $this->sleepingFibers = array_values($this->sleepingFibers);

        // Swap: take current fibers out and clear the list.
        // classifySuspendedFiber() will add fibers back to $this->fibers
        // (or to ioWaitingFibers/sleepingFibers) as needed.
        $currentFibers = $this->fibers;
        $this->fibers = [];

        foreach ($currentFibers as $fiber) {
            if ($fiber->isTerminated()) {
                continue; // GC this fiber
            }

            if (!$fiber->isSuspended()) {
                continue; // Still running in another context? Skip
            }

            try {
                // $fiber->resume() returns the value passed to the NEXT
                // Fiber::suspend() call inside the fiber. We use this
                // to classify the fiber's new state.
                $suspendValue = $fiber->resume();

                if ($fiber->isTerminated()) {
                    continue; // Done — don't re-add
                }

                if ($fiber->isSuspended()) {
                    $this->classifySuspendedFiber($fiber, $suspendValue);
                }
            } catch (\Throwable $e) {
                error_log("[FiberEventLoop] Fiber resume error: {$e->getMessage()}");
                // Don't re-add crashed fibers
            }
        }
    }
}
