<?php

declare(strict_types=1);

namespace Schtzie\Keystone\RateLimiting;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\RateLimiter;
use Schtzie\Keystone\RateLimiting\Contracts\RateLimitStrategy;

/**
 * Token-bucket rate-limiting strategy backed by an atomic Redis Lua script.
 *
 * How it works:
 *   A conceptual "bucket" holds up to `$limit` tokens. Tokens refill at a
 *   constant rate of `$limit / $windowSeconds` tokens per second. Each request
 *   consumes one token. If the bucket is empty the request is throttled.
 *
 *   Unlike fixed or sliding windows, token bucket allows short, bursty traffic
 *   (up to the full bucket capacity) while guaranteeing that average throughput
 *   never exceeds the configured rate. This makes it ideal for APIs consumed
 *   by client applications that may batch-send requests in quick succession.
 *
 * Atomicity guarantee:
 *   The refill calculation, availability check, and token deduction are
 *   performed inside a single Lua script executed atomically by Redis
 *   (EVAL command). This eliminates race conditions entirely, even under
 *   heavy concurrent load.
 *
 * Graceful degradation:
 *   When the cache store is not Redis (e.g. array store in tests), this
 *   strategy falls back to FixedWindowStrategy automatically so non-Redis
 *   deployments are never broken.
 *
 * Storage requirements:
 *   Requires a Redis cache store. Each active rate-limit key uses two Redis
 *   string keys (token count + last-refill timestamp) — O(1) memory per key.
 */
final class TokenBucketStrategy implements RateLimitStrategy
{
    /**
     * Lua script that atomically:
     *   1. Reads the current token count and last-refill timestamp.
     *   2. Calculates tokens to refill based on elapsed seconds × refill rate.
     *   3. Caps the bucket at its maximum capacity.
     *   4. Consumes one token if available; rejects the request otherwise.
     *   5. Persists updated state with a TTL long enough to cover one full refill cycle.
     *
     * KEYS[1] = token count key
     * KEYS[2] = last refill timestamp key
     * ARGV[1] = rate (tokens per second, float string)
     * ARGV[2] = capacity (= $limit)
     * ARGV[3] = current Unix timestamp (float string, microsecond precision)
     *
     * Returns: {1, remaining_tokens} if allowed, {0, 0} if throttled.
     *
     * @var string
     */
    private const LUA_SCRIPT = <<<'LUA'
local tokens_key   = KEYS[1]
local ts_key       = KEYS[2]
local rate         = tonumber(ARGV[1])
local capacity     = tonumber(ARGV[2])
local now          = tonumber(ARGV[3])

local last_tokens  = tonumber(redis.call("GET", tokens_key))
local last_ts      = tonumber(redis.call("GET", ts_key))

if last_tokens == nil then last_tokens = capacity end
if last_ts     == nil then last_ts     = now       end

local delta  = math.max(0, now - last_ts)
local filled = math.min(capacity, last_tokens + delta * rate)

local allowed = filled >= 1
local new_tokens = allowed and (filled - 1) or filled

local ttl = math.ceil(capacity / rate) + 1
redis.call("SET", tokens_key, new_tokens, "EX", ttl)
redis.call("SET", ts_key,     now,        "EX", ttl)

return { allowed and 1 or 0, math.floor(new_tokens) }
LUA;

    /**
     * Attempt a rate-limited request using the token-bucket algorithm.
     * The bucket refills continuously at `limit / windowSeconds` tokens/second.
     */
    public function attempt(string $key, int $limit, int $windowSeconds): bool
    {
        $connection = $this->redisConnection();

        if ($connection === null) {
            return (new FixedWindowStrategy())->attempt($key, $limit, $windowSeconds);
        }

        $rate = $limit / max(1, $windowSeconds); // tokens per second
        $now = microtime(true);

        /** @var array<int, int>|mixed $result */
        $result = $connection->command('eval', [
            self::LUA_SCRIPT,
            2,
            $key.':tokens',
            $key.':ts',
            (string) $rate,
            (string) $limit,
            (string) $now,
        ]);

        return is_array($result) && isset($result[0]) && $result[0] === 1;

    }

    /**
     * Return the estimated number of tokens remaining in the bucket.
     * Reads current state without modifying it (no token consumed).
     */
    public function remaining(string $key, int $limit, int $windowSeconds): int
    {
        $connection = $this->redisConnection();

        if ($connection === null) {
            return (new FixedWindowStrategy())->remaining($key, $limit, $windowSeconds);
        }

        $rate = $limit / max(1, $windowSeconds);
        $now = microtime(true);
        $rawTokens = $connection->get($key.':tokens');
        $rawTs = $connection->get($key.':ts');

        if ($rawTokens === null) {
            return $limit; // Fresh bucket — fully loaded
        }

        $lastTs = is_numeric($rawTs) ? (float) $rawTs : $now;
        $delta = max(0.0, $now - $lastTs);
        $rawTksFlt = is_numeric($rawTokens) ? (float) $rawTokens : (float) $limit;
        $filled = min((float) $limit, $rawTksFlt + $delta * $rate);

        return max(0, (int) floor($filled));
    }

    /**
     * Return the number of seconds until one token is available.
     *
     * When the bucket still has tokens, returns 0 (retry immediately).
     * Otherwise, calculates the wait based on the refill rate.
     */
    public function retryAfter(string $key): int
    {
        $connection = $this->redisConnection();

        if ($connection === null) {
            return max(0, RateLimiter::availableIn($key));
        }

        $rawTokens = $connection->get($key.':tokens');
        $rawTs = $connection->get($key.':ts');

        if ($rawTokens === null || (is_numeric($rawTokens) && (float) $rawTokens >= 1)) {
            return 0;
        }

        // We need (1 - current_tokens) / rate seconds for one token to refill
        $windowConfig = config('keystone.rate_limit_window_seconds', 60);
        $windowSeconds = is_numeric($windowConfig) ? (int) $windowConfig : 60;
        $limitConfig = config('keystone.rate_limit', 60);
        $limit = is_numeric($limitConfig) ? (int) $limitConfig : 60;
        $rate = $limit / max(1, $windowSeconds);

        $rawTksFlt = is_numeric($rawTokens) ? (float) $rawTokens : 0.0;
        $deficit = max(0.0, 1.0 - $rawTksFlt);

        return max(1, (int) ceil($deficit / $rate));
    }

    /**
     * Resolve the Redis connection from the configured Keystone cache store.
     * Returns null if the store is not a Redis-backed implementation.
     *
     * @return \Illuminate\Redis\Connections\Connection|null
     */
    private function redisConnection(): mixed
    {
        $storeConfig = config('keystone.cache.store', 'redis');
        $storeName = is_string($storeConfig) ? $storeConfig : 'redis';
        $store = app('cache')->store($storeName);
        $inner = ($store instanceof Repository) ? $store->getStore() : null;

        if (! ($inner instanceof RedisStore)) {
            return null;
        }

        return $inner->connection();
    }
}
