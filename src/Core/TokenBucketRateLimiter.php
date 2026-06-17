<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Contracts\RateLimiterInterface;

/**
 * In-process token-bucket rate limiter (framework-agnostic, RULE 5).
 *
 * Each key owns a bucket that refills at `rate` tokens/sec up to `capacity`
 * (the max burst). {@see reserve()} draws one token; if the bucket is dry it
 * returns the seconds until a token is available and advances the bucket so
 * concurrent reservations serialise correctly.
 *
 * This is the default driver. A cross-worker driver (Surge table / Cache) can
 * implement the same {@see RateLimiterInterface} and be swapped in via config
 * without touching the Client (D5).
 */
final class TokenBucketRateLimiter implements RateLimiterInterface
{
    /** @var array<string, array{0: float, 1: float}> key => [tokens, lastRefillTs] */
    private array $buckets = [];

    public function __construct(
        private readonly float $defaultRate = 30.0,
        private readonly float $defaultCapacity = 30.0,
    ) {
    }

    public function reserve(string $key, ?float $rate = null, ?float $capacity = null): float
    {
        $rate     = $rate     ?? $this->defaultRate;
        $capacity = $capacity ?? $this->defaultCapacity;

        // Disabled / unbounded bucket — never throttle.
        if ($rate <= 0.0 || $capacity <= 0.0) {
            return 0.0;
        }

        $now = microtime(true);
        [$tokens, $last] = $this->buckets[$key] ?? [$capacity, $now];

        // Refill for the elapsed time, capped at capacity.
        $tokens = min($capacity, $tokens + max(0.0, $now - $last) * $rate);

        if ($tokens >= 1.0) {
            $this->buckets[$key] = [$tokens - 1.0, $now];
            return 0.0;
        }

        // Dry: caller waits until one token has accrued. Advance the bucket to
        // that instant with zero tokens so the next reservation queues after it.
        $wait = (1.0 - $tokens) / $rate;
        $this->buckets[$key] = [0.0, $now + $wait];

        return $wait;
    }
}
