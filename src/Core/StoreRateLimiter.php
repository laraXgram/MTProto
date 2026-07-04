<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Contracts\RateLimiterInterface;
use LaraGram\MTProto\Contracts\Store;

final class StoreRateLimiter implements RateLimiterInterface
{
    private const STALE_AFTER = 3600.0;

    /**
     * @var array<string, array{0: float, 1: float}>|null scope => [tokens, lastRefillTs]
     */
    private ?array $buckets = null;

    public function __construct(
        private readonly Store $store,
        private readonly float $defaultRate = 30.0,
        private readonly float $defaultCapacity = 30.0,
        private readonly string $storeKey = 'rl',
    ) {
    }

    public function reserve(string $key, ?float $rate = null, ?float $capacity = null): float
    {
        $rate     = $rate     ?? $this->defaultRate;
        $capacity = $capacity ?? $this->defaultCapacity;

        if ($rate <= 0.0 || $capacity <= 0.0) {
            return 0.0;
        }

        $buckets = $this->load();
        $now = microtime(true);
        [$tokens, $last] = $buckets[$key] ?? [$capacity, $now];

        // Refill for the elapsed time, capped at capacity.
        $tokens = min($capacity, $tokens + max(0.0, $now - $last) * $rate);

        if ($tokens >= 1.0) {
            $buckets[$key] = [$tokens - 1.0, $now];
            $this->save($buckets, $now);
            return 0.0;
        }

        $wait = (1.0 - $tokens) / $rate;
        $buckets[$key] = [0.0, $now + $wait];
        $this->save($buckets, $now);

        return $wait;
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    private function load(): array
    {
        if ($this->buckets !== null) {
            return $this->buckets;
        }

        $raw  = $this->store->get($this->storeKey);
        $data = $raw !== null ? json_decode($raw, true) : null;

        return $this->buckets = is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array{0: float, 1: float}> $buckets
     */
    private function save(array $buckets, float $now): void
    {
        foreach ($buckets as $scope => $bucket) {
            if ($now - $bucket[1] > self::STALE_AFTER) {
                unset($buckets[$scope]);
            }
        }

        $this->buckets = $buckets;
        $this->store->put($this->storeKey, json_encode($buckets));
    }
}
