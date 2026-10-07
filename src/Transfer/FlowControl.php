<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transfer;

use LaraGram\MTProto\Runtime\Contracts\Runtime;

/**
 * Paces one file transfer to the rate Telegram accepts.
 *
 * Telegram throttles transfer bandwidth per account and answers excess with
 * short FLOOD_WAIT_X errors. Retrying each flooded part on its own leaves its
 * window slot idle and keeps the other parts colliding with the limit. This
 * controller starts unpaced, and on the first flood switches to pacing just
 * under the throughput measured so far, then probes upward slowly (an
 * additive-increase / multiplicative-decrease loop, as in TCP congestion
 * control) so the transfer settles right below the server's ceiling.
 */
final class FlowControl
{
    /** Throughput window used to measure the delivered rate. */
    private const MEASURE_SECONDS = 1.0;

    /** Fraction of the measured rate to fall back to after a flood. */
    private const BACKOFF = 0.9;

    /**
     * Per-part probe, as a fraction of the part size. Parts arrive `rate / size`
     * times a second, so the pacing rate grows by this fraction per second:
     * SLOW_START doubles it until the ceiling is found, PROBE then creeps up.
     */
    private const SLOW_START = 1.0;

    private const PROBE = 0.08;

    /** Floods within this many seconds of a cut belong to the same congestion event. */
    private const CUT_INTERVAL = 1.0;

    /** Pacing rate in bytes/s; 0 = unpaced (no flood seen yet). */
    private float $rate = 0.0;

    /** Next moment a paced request may start. */
    private float $nextSlot = 0.0;

    /** @var list<array{0: float, 1: int}> recent [time, bytes] deliveries */
    private array $delivered = [];

    private int $floods = 0;

    private float $lastCut = 0.0;

    /** Number of rate cuts so far; the first one only brackets the ceiling. */
    private int $cuts = 0;

    private float $startedAt;

    private int $totalDelivered = 0;

    private int $largestPart = 0;

    public function __construct(private readonly Runtime $runtime)
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Wait for this request's turn under the current pacing rate. Slots are
     * claimed only when due (never booked ahead), so a rate change applies to
     * every waiting request at once.
     */
    public function acquire(int $bytes): void
    {
        $this->largestPart = max($this->largestPart, $bytes);

        while (true) {
            if ($this->rate <= 0.0) {
                return;
            }

            $now = microtime(true);
            if ($now + 0.001 >= $this->nextSlot) {
                $this->nextSlot = max($now, $this->nextSlot) + $bytes / $this->rate;
                return;
            }

            $this->runtime->sleep(min(0.1, $this->nextSlot - $now));
        }
    }

    /**
     * A part was delivered: record it and, when paced, probe a little faster.
     */
    public function delivered(int $bytes): void
    {
        $now = microtime(true);
        $this->delivered[] = [$now, $bytes];
        $this->totalDelivered += $bytes;

        if ($this->rate > 0.0) {
            $this->rate += $bytes * ($this->cuts < 2 ? self::SLOW_START : self::PROBE);
        }
    }

    /**
     * The server flooded a part: pace just under the rate it actually accepted.
     */
    public function flooded(int $seconds): void
    {
        $this->floods++;

        // Every part in flight floods at once when the limit is hit - that is
        // one congestion event, so cut the rate once per interval, not per part.
        $now = microtime(true);
        if ($now - $this->lastCut < self::CUT_INTERVAL) {
            return;
        }

        $measured = $this->measuredRate();
        if ($measured <= 0.0) {
            // Nothing delivered recently: no basis for a rate yet. The flooded
            // parts wait the server's seconds; decide once data flows again.
            return;
        }

        $this->lastCut = $now;
        $this->cuts++;
        $this->rate = ($this->rate > 0.0 ? min($this->rate, $measured) : $measured) * self::BACKOFF;
    }

    /**
     * Bytes/s delivered over the last few seconds.
     */
    public function measuredRate(): float
    {
        $now = microtime(true);
        $cutoff = $now - self::MEASURE_SECONDS;

        while ($this->delivered !== [] && $this->delivered[0][0] < $cutoff) {
            array_shift($this->delivered);
        }

        if ($this->delivered === []) {
            return 0.0;
        }

        $bytes = array_sum(array_column($this->delivered, 1));
        $span = max(0.25, $now - $this->delivered[0][0]);

        return $bytes / $span;
    }

    public function floods(): int
    {
        return $this->floods;
    }

    public function rate(): float
    {
        return $this->rate;
    }
}
