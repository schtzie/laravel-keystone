<?php

declare(strict_types=1);

namespace Schtzie\Keystone\RateLimiting\Contracts;

/**
 * Defines the algorithm-agnostic contract that every rate-limit strategy
 * must fulfil so that the middleware can delegate enforcement without knowing
 * which windowing model is in use.
 *
 * Three built-in implementations ship with Keystone:
 *
 *   - **FixedWindowStrategy** — standard Laravel RateLimiter; simplest and
 *     lowest overhead. Resets the entire bucket at the end of each fixed interval.
 *
 *   - **SlidingWindowStrategy** — uses a Redis sorted set to track request
 *     timestamps; prevents the traffic burst that fixed windows allow at
 *     boundary edges. Falls back to fixed-window behaviour on non-Redis stores.
 *
 *   - **TokenBucketStrategy** — Lua-atomic token refill on each request; allows
 *     short bursts up to the bucket capacity while maintaining a smooth average
 *     throughput. Falls back to fixed-window on non-Redis stores.
 *
 * Custom strategies can be registered by binding this interface in the
 * container: `app()->bind(RateLimitStrategy::class, MyStrategy::class)`.
 */
interface RateLimitStrategy
{
    /**
     * Record a new request attempt for the given rate-limit key and return
     * whether the attempt is allowed.
     *
     * Implementations must be atomic — concurrent attempts from the same key
     * must not both succeed when only one slot remains.
     *
     * @param  string  $key  A namespaced cache key unique to this client + window.
     * @param  int  $limit  Maximum requests permitted in the window.
     * @param  int  $windowSeconds  Duration of the rate-limit window in seconds.
     * @return bool True if the request is within limits and should proceed; false to throttle.
     */
    public function attempt(string $key, int $limit, int $windowSeconds): bool;

    /**
     * Return the number of remaining allowed requests in the current window
     * without consuming a slot.
     *
     * Used to populate the `X-Keystone-RateLimit-Remaining` response header.
     *
     * @param  string  $key  The same namespaced key passed to `attempt()`.
     * @param  int  $limit  The same limit ceiling passed to `attempt()`.
     * @param  int  $windowSeconds  The same window duration passed to `attempt()`.
     */
    public function remaining(string $key, int $limit, int $windowSeconds): int;

    /**
     * Return the number of seconds until the rate-limit window resets.
     *
     * Used to populate `Retry-After` and `X-Keystone-RateLimit-Reset` headers
     * in 429 responses so clients know when to retry.
     *
     * @param  string  $key  The namespaced cache key for this client + window.
     */
    public function retryAfter(string $key): int;
}
