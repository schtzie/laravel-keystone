<?php

declare(strict_types=1);

namespace Schtzie\Keystone\RateLimiting;

use Illuminate\Support\Facades\RateLimiter;
use Schtzie\Keystone\RateLimiting\Contracts\RateLimitStrategy;

/**
 * Fixed-window rate-limiting strategy backed by Laravel's built-in RateLimiter.
 *
 * How it works:
 *   The window is divided into discrete, non-overlapping buckets of `$windowSeconds`
 *   length. The counter resets to zero at the start of each new bucket. This means
 *   a client can exhaust the entire limit at the end of one bucket and immediately
 *   consume the full limit again at the start of the next — effectively doubling
 *   throughput at bucket boundaries. This is generally acceptable for most APIs
 *   and is the default strategy because it is the simplest and lowest overhead.
 *
 * When to prefer alternatives:
 *   - Use SlidingWindowStrategy if boundary bursts cause downstream issues.
 *   - Use TokenBucketStrategy if you want to allow short bursts while smoothing
 *     average throughput over time.
 *
 * Storage requirements:
 *   Compatible with all Laravel cache drivers (array, file, database, Redis, etc.)
 *   because it delegates entirely to Laravel's RateLimiter facade.
 */
final class FixedWindowStrategy implements RateLimitStrategy
{
    /**
     * Attempt a rate-limited request using a fixed-window counter.
     *
     * Returns false (and does not increment the counter) if the limit has
     * already been reached for the current window.
     */
    public function attempt(string $key, int $limit, int $windowSeconds): bool
    {
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }

        RateLimiter::hit($key, $windowSeconds);

        return true;
    }

    /**
     * Return the number of slots remaining in the current window without
     * consuming a slot. Returns zero when the limit is already exhausted.
     */
    public function remaining(string $key, int $limit, int $windowSeconds): int
    {
        return max(0, RateLimiter::retriesLeft($key, $limit));
    }

    /**
     * Return the number of seconds until the current fixed window resets.
     * Delegates to RateLimiter::availableIn(), which reads the cache TTL.
     */
    public function retryAfter(string $key): int
    {
        return max(0, RateLimiter::availableIn($key));
    }
}
