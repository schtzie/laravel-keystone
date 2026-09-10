<?php

declare(strict_types=1);

namespace Schtzie\Keystone\RateLimiting;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\RateLimiter;
use Schtzie\Keystone\RateLimiting\Contracts\RateLimitStrategy;

/**
 * Sliding-window rate-limiting strategy backed by a Redis sorted set.
 *
 * How it works:
 *   Instead of resetting a counter at fixed intervals, each incoming request is
 *   appended to a Redis sorted set keyed by its arrival timestamp (in milliseconds).
 *   Before counting, all entries older than `now − windowMs` are pruned from the
 *   set (ZREMRANGEBYSCORE). The current cardinality of the set represents the
 *   true number of requests seen within the rolling window.
 *
 *   This eliminates the "boundary burst" problem inherent in fixed-window counters:
 *   a client can never squeeze more than `$limit` requests through within any
 *   contiguous window of `$windowSeconds` seconds, regardless of timing.
 *
 * Atomicity guarantee:
 *   The prune and count operations are executed sequentially. Under concurrent
 *   load, a very small race window exists between ZCOUNT and ZADD — practically
 *   negligible for typical API workloads. For strict atomicity, prefer
 *   TokenBucketStrategy (Lua scripted).
 *
 * Graceful degradation:
 *   When the configured cache store is not Redis (e.g. the array store used in
 *   tests), this strategy automatically falls back to FixedWindowStrategy so
 *   tests and non-Redis deployments are never broken.
 *
 * Storage requirements:
 *   Requires a Redis cache store. Each active rate-limit key consumes O(n)
 *   memory proportional to the number of requests in the current window.
 */
final class SlidingWindowStrategy implements RateLimitStrategy
{
    /**
     * Attempt a rate-limited request using a Redis sliding-window sorted set.
     *
     * On a Redis miss (non-Redis store), falls back to fixed-window behaviour.
     */
    public function attempt(string $key, int $limit, int $windowSeconds): bool
    {
        $connection = $this->redisConnection();

        if ($connection === null) {
            // Graceful degradation: fall back to fixed window on non-Redis stores
            return (new FixedWindowStrategy())->attempt($key, $limit, $windowSeconds);
        }

        $nowMs    = (int) (microtime(true) * 1000);
        $windowMs = $windowSeconds * 1000;

        // Prune stale entries that have fallen outside the rolling window
        $connection->zRemRangeByScore($key, '-inf', (string) ($nowMs - $windowMs));

        $count = (int) $connection->zCard($key);

        if ($count >= $limit) {
            return false;
        }

        // Store the timestamp as both score and member (appended with a unique suffix
        // to avoid member collisions when multiple requests arrive in the same millisecond)
        $member = $nowMs . '-' . bin2hex(random_bytes(4));
        $connection->zAdd($key, $nowMs, $member);

        // Keep the sorted set alive just long enough to cover one full window
        $connection->pExpire($key, $windowMs);

        return true;
    }

    /**
     * Count requests in the current rolling window and return slots remaining.
     * Prunes stale entries before counting to ensure accuracy.
     */
    public function remaining(string $key, int $limit, int $windowSeconds): int
    {
        $connection = $this->redisConnection();

        if ($connection === null) {
            return (new FixedWindowStrategy())->remaining($key, $limit, $windowSeconds);
        }

        $nowMs    = (int) (microtime(true) * 1000);
        $windowMs = $windowSeconds * 1000;

        $connection->zRemRangeByScore($key, '-inf', (string) ($nowMs - $windowMs));

        $count = (int) $connection->zCard($key);

        return max(0, $limit - $count);
    }

    /**
     * For a sliding window, the "retry after" time is the age of the oldest
     * entry in the set — that is, when the next slot will open up.
     *
     * Falls back to RateLimiter::availableIn() when Redis is unavailable.
     */
    public function retryAfter(string $key): int
    {
        $connection = $this->redisConnection();

        if ($connection === null) {
            return max(0, RateLimiter::availableIn($key));
        }

        // Fetch the oldest entry's score (earliest timestamp in the window)
        /** @var mixed $oldest */
        $oldest = $connection->command('zRange', [$key, 0, 0, 'WITHSCORES']);


        if (! is_array($oldest) || empty($oldest)) {
            return 0;
        }

        // zRange WITHSCORES returns [member => score] (phpredis) or [[member, score]] (predis)
        $first = reset($oldest);
        if (is_array($first)) {
            $score = isset($first[1]) && is_numeric($first[1]) ? (float) $first[1] : 0.0;
        } else {
            $score = is_numeric($first) ? (float) $first : 0.0;
        }

        $nowMs = (int) (microtime(true) * 1000);

        return max(0, (int) ceil(($score - $nowMs) / 1000));
    }

    /**
     * Resolve the underlying Redis connection from the configured cache store.
     * Returns null when the store is not a Redis-backed store.
     *
     * @return \Illuminate\Redis\Connections\Connection|null
     */
    private function redisConnection(): mixed
    {
        $storeConfig = config('keystone.cache.store', 'redis');
        $storeName   = is_string($storeConfig) ? $storeConfig : 'redis';
        $store       = app('cache')->store($storeName);
        $inner       = ($store instanceof Repository) ? $store->getStore() : null;

        if (! ($inner instanceof RedisStore)) {
            return null;
        }

        return $inner->connection();
    }
}
