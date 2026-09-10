<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Contracts\KeystoneServiceContract;
use Schtzie\Keystone\Models\Keystone;

/**
 * Core authentication service — the brain of the Keystone pipeline.
 *
 * Orchestrates the multi-layer resolution and validation sequence for every
 * incoming API request:
 *
 *   1. **Credential extraction** — reads the client ID and HMAC signature from
 *      the configured header name(s) or falls back to query-string parameters.
 *
 *   2. **Replay-attack protection** (opt-in) — validates the `X-Timestamp` header
 *      against a configurable time window to reject requests replayed after
 *      capture. Enable via `keystone.replay_protection.enabled = true`.
 *
 *   3. **Cache-first lookup** — resolves the Keystone record through an
 *      in-memory map → Redis → database waterfall, writing the record back to
 *      Redis on a DB hit (warm-on-miss) to prevent future round-trips.
 *
 *   4. **Validity check** — confirms the record is not revoked and not expired,
 *      honoring any active grace period set during key rotation.
 *
 *   5. **HMAC-SHA256 verification** — verifies the provided signature against
 *      `hash_hmac('sha256', client, secret)` using `hash_equals()` to prevent
 *      timing attacks.
 *
 * Registered as a scoped singleton so the `$resolved` in-memory map persists
 * across multiple service calls within the same request but is reset between
 * requests. The map is also flushed by `KeystoneBootstrapper` on every tenant
 * switch in Octane and queue-worker contexts to prevent cross-tenant leakage.
 *
 * Implements {@see KeystoneServiceContract} so that tests can swap in
 * {@see \Schtzie\Keystone\Testing\KeystoneFake} via the container without
 * modifying the middleware or facade.
 */
final class KeystoneService implements KeystoneServiceContract
{
    /**
     * Per-request in-memory map: plain client → resolved Keystone|null.
     *
     * Prevents redundant Redis/DB round-trips when the same key is used
     * more than once within a single request (e.g. nested API calls).
     *
     * @var array<string, Keystone|null>
     */
    private array $resolved = [];

    public function __construct(private readonly KeystoneKeyCacheRepository $cache) {}

    /**
     * Attempt to authenticate the incoming HTTP request.
     *
     * Reads the client ID from the configured header or query parameter, then
     * the HMAC signature from the signature header or query parameter, and
     * runs the full validation pipeline. Returns null on any failure — the
     * caller (middleware) is responsible for issuing the 401 response.
     *
     * Optional replay-protection check:
     *   When enabled, the client must include an `X-Timestamp` header containing
     *   the current Unix timestamp (seconds). Requests where |now - ts| exceeds
     *   `replay_protection.window_seconds` are rejected as potential replays.
     */
    public function resolve(Request $request): ?Keystone
    {
        // ── 1. Extract client ID ──────────────────────────────────────────────
        $headerNameConfig = config('keystone.header', 'X-Client-Id');
        $headerName = is_string($headerNameConfig) ? $headerNameConfig : 'X-Client-Id';
        $rawKey = $request->header($headerName);

        if (! is_string($rawKey) || $rawKey === '') {
            $queryNameConfig = config('keystone.query_param', 'client');
            $queryName = is_string($queryNameConfig) ? $queryNameConfig : 'client';
            $rawKey = $request->query($queryName);
        }

        if (! is_string($rawKey) || $rawKey === '') {
            return null;
        }

        // ── 2. Extract HMAC signature ─────────────────────────────────────────
        $sigHeaderConfig = config('keystone.signature_header', 'X-API-Signature');
        $sigHeader = is_string($sigHeaderConfig) ? $sigHeaderConfig : 'X-API-Signature';
        $signature = $request->header($sigHeader);

        if (! is_string($signature) || $signature === '') {
            $sigQueryConfig = config('keystone.signature_query_param', 'signature');
            $sigQuery = is_string($sigQueryConfig) ? $sigQueryConfig : 'signature';
            $signature = $request->query($sigQuery);
        }

        if (! is_string($signature) || $signature === '') {
            return null;
        }

        // ── 3. Optional replay-attack prevention ──────────────────────────────
        if (config('keystone.replay_protection.enabled', false)) {
            $tsHeaderConfig = config('keystone.replay_protection.timestamp_header', 'X-Timestamp');
            $tsHeader = is_string($tsHeaderConfig) ? $tsHeaderConfig : 'X-Timestamp';
            $timestamp = $request->header($tsHeader);

            if (! is_string($timestamp) || ! is_numeric($timestamp)) {
                return null; // Timestamp missing or non-numeric — reject
            }

            $window = config('keystone.replay_protection.window_seconds', 30);
            $windowSeconds = is_numeric($window) ? (int) $window : 30;

            if (abs(time() - (int) $timestamp) > $windowSeconds) {
                return null; // Outside replay window — potential replay attack
            }
        }

        // ── 4. Resolve key through cache → DB waterfall ───────────────────────
        $client = $this->findByKeystone($rawKey);

        if ($client === null || ! $client->isValid()) {
            return null;
        }

        // ── 5. HMAC-SHA256 signature verification ─────────────────────────────
        if (! $client->verifySignature($signature)) {
            return null;
        }

        return $client;
    }

    /**
     * Perform a cache-aware lookup for a Keystone record by its plain client identifier.
     *
     * Resolution order:
     *   1. In-memory `$resolved` map — zero-latency, avoids redundant calls within a request.
     *   2. Redis (via KeystoneKeyCacheRepository) — fast, avoids DB queries for warm keys.
     *   3. Database — authoritative source; the record is written back to Redis on a hit
     *      when `keystone.cache.warm_on_miss = true` (default: true).
     *
     * The result (including null) is always stored in the in-memory map so that
     * repeated lookups of the same key within a single request hit the map directly.
     */
    public function findByKeystone(string $rawKey): ?Keystone
    {
        if (array_key_exists($rawKey, $this->resolved)) {
            return $this->resolved[$rawKey];
        }

        // Redis lookup
        $client = $this->cache->get($rawKey);

        // Database fallback with optional write-through to Redis
        if ($client === null) {
            /** @var class-string<Keystone> $modelClass */
            $modelClass = config('keystone.model', Keystone::class);
            $client = $modelClass::where('client', $rawKey)->first();

            if ($client !== null && config('keystone.cache.warm_on_miss', true)) {
                $this->cache->put($client);
            }
        }

        return $this->resolved[$rawKey] = $client;
    }

    /**
     * Convenience proxy to create a new Keystone for the given owner model.
     *
     * Delegates to the owner's `createKeystone()` method so that Facade consumers
     * can generate keys through the service layer without needing a direct model reference.
     *
     * @param  array{scopes?: array<int, string>, expires_at?: \Carbon\CarbonImmutable|null}  $options
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function generate(Model $owner, string $name, array $options = []): array
    {
        /** @var array{client: string, secret: string, model: Keystone} */
        return $owner->createKeystone( // @phpstan-ignore-line
            $name,
            $options['scopes'] ?? [],
            $options['expires_at'] ?? null,
        );
    }

    /**
     * Force-evict a client from both the in-memory map and Redis.
     *
     * Use this when a key has been updated or revoked outside the normal Eloquent
     * lifecycle (e.g. via a raw DB query) and you need to ensure stale cache data
     * is cleared immediately rather than waiting for the TTL to expire.
     */
    public function invalidate(string $client): void
    {
        unset($this->resolved[$client]);
        $this->cache->forget($client);
    }

    /**
     * Clear the in-memory resolved-key map for the current process context.
     *
     * Called by KeystoneBootstrapper on every tenant switch in Octane and
     * queue-worker processes to prevent resolved keys from one tenant leaking
     * into requests handled for a different tenant in the same PHP process.
     */
    public function flushResolved(): void
    {
        $this->resolved = [];
    }
}
