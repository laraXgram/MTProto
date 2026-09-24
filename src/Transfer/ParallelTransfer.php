<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transfer;

use LaraGram\Log\LoggerInterface;
use LaraGram\MTProto\Core\Client;
use LaraGram\MTProto\Exceptions\MTProtoException;
use LaraGram\MTProto\Runtime\Contracts\Runtime;

/**
 * Runs the parts of one file transfer over a set of pump-driven sockets.
 *
 * `$window` worker coroutines pull part indexes from a shared counter. Each part
 * goes to the least-busy socket, paced by {@see FlowControl}. Failures are
 * handled per kind ({@see TransferError}):
 *  - flood:     this part waits the server's seconds, the rest keep flowing
 *               while the pacing rate drops just under the accepted rate;
 *  - transient: retried at once on the least-busy other socket;
 *  - fatal:     recorded, the transfer stops and the first error is thrown.
 */
final class ParallelTransfer
{
    /** Attempts per part before a transient failure becomes fatal. */
    public const MAX_ATTEMPTS = 8;

    private FlowControl $flow;

    /** @var array<int, int> in-flight parts per socket index */
    private array $busy;

    private int $next = 0;

    private ?\Throwable $failure = null;

    private int $failedPart = -1;

    /** @var array{parts: int, floods: int, retries: int} */
    private array $stats = ['parts' => 0, 'floods' => 0, 'retries' => 0];

    /**
     * @param list<Client> $sockets pump-driven connections to the file's DC
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly array $sockets,
        private readonly int $window,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($sockets === []) {
            throw new MTProtoException('A parallel transfer needs at least one socket.');
        }

        $this->flow = new FlowControl($runtime);
        $this->busy = array_fill(0, count($sockets), 0);
    }

    /**
     * Transfer parts 0..$total-1. `$call` performs one part on a socket and
     * returns its result; `$done` receives each result as it completes (not in
     * order). Must run inside a coroutine container. Throws the first fatal error.
     *
     * @param callable(int $part, Client $socket): mixed $call
     * @param callable(int $part, mixed $result): void $done
     * @param callable(int $part): int $cost bytes a part moves (for pacing)
     */
    public function run(int $total, callable $call, callable $done, callable $cost): void
    {
        $workers = max(1, min($this->window, $total));
        $finished = $this->runtime->channel($workers);

        for ($w = 0; $w < $workers; $w++) {
            $this->runtime->spawn(function () use ($total, $call, $done, $cost, $finished): void {
                try {
                    $this->work($total, $call, $done, $cost);
                } finally {
                    $finished->push(true);
                }
            });
        }

        for ($w = 0; $w < $workers; $w++) {
            $finished->pop();
        }
        $finished->close();

        $this->stats['floods'] = $this->flow->floods();

        if ($this->failure !== null) {
            throw new MTProtoException(
                "Transfer failed on part {$this->failedPart}: {$this->failure->getMessage()}",
                0,
                $this->failure,
            );
        }
    }

    /**
     * @return array{parts: int, floods: int, retries: int}
     */
    public function stats(): array
    {
        return $this->stats;
    }

    private function work(int $total, callable $call, callable $done, callable $cost): void
    {
        while ($this->failure === null && ($part = $this->take($total)) !== null) {
            $bytes = $cost($part);

            $failedOn = null;
            for ($attempt = 1; ; $attempt++) {
                $this->flow->acquire($bytes);
                $socket = $this->pick($failedOn);
                $this->busy[$socket]++;

                try {
                    $result = $call($part, $this->sockets[$socket]);
                } catch (\Throwable $e) {
                    $this->busy[$socket]--;

                    if ($this->failure !== null) {
                        return;
                    }

                    [$kind, $seconds] = TransferError::classify($e);

                    if ($kind === TransferError::Fatal || $attempt >= self::MAX_ATTEMPTS) {
                        $this->fail($part, $e);
                        return;
                    }

                    $this->stats['retries']++;
                    $failedOn = $kind === TransferError::Transient ? $socket : null;

                    if ($kind === TransferError::Flood) {
                        $this->flow->flooded($seconds);
                        $this->logger?->debug("Transfer part {$part}: flood wait {$seconds}s (pacing " . round($this->flow->rate() / 1048576, 2) . ' MB/s)');
                        $this->runtime->sleep($seconds);
                    } else {
                        $this->logger?->debug("Transfer part {$part}: retry {$attempt} after {$e->getMessage()}");
                        // Back off only when every attempt so far failed fast.
                        if ($attempt > 2) {
                            $this->runtime->sleep(0.05 * $attempt);
                        }
                    }

                    continue;
                }

                $this->busy[$socket]--;
                $this->flow->delivered($bytes);
                $this->stats['parts']++;
                $done($part, $result);
                break;
            }
        }
    }

    private function take(int $total): ?int
    {
        return $this->next < $total ? $this->next++ : null;
    }

    /**
     * The socket with the fewest parts in flight, avoiding $avoid when there
     * is any other choice.
     */
    private function pick(?int $avoid = null): int
    {
        $best = null;
        foreach ($this->busy as $index => $inFlight) {
            if ($index === $avoid && count($this->busy) > 1) {
                continue;
            }
            if ($best === null || $inFlight < $this->busy[$best]) {
                $best = $index;
            }
        }

        return $best;
    }

    private function fail(int $part, \Throwable $e): void
    {
        if ($this->failure === null) {
            $this->failure = $e;
            $this->failedPart = $part;
        }
    }
}
