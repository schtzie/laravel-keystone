<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Models\ApiKey;

/**
 * Orchestrates the Redis-first API key resolution pipeline:
 *   in-memory → Redis → database → HMAC verification
 *
 * Registered as a singleton so the $resolved map persists across
 * multiple service calls within the same request. The map is flushed
 * by KeystoneBootstrapper on every tenant switch (Octane safety).
 */
final class ApiKeyService
{
    /**
     * Per-request in-memory map: plain api_key → resolved ApiKey|null.
     * Prevents redundant Redis/DB round-trips within a single request.
     *
     * @var array<string, ApiKey|null>
     */
    private array $resolved = [];

    public function __construct(private readonly ApiKeyCacheRepository $cache) {}

    // ── Resolution ─────────────────────────────────────────────────────────

    /**
     * Resolve an ApiKey from the incoming request.
     *
     * Reads the plain api_key from the configured header / query param,
     * looks it up (cache → DB), then verifies the HMAC-SHA256 signature.
     *
     * Returns null if the key is missing, invalid, revoked, expired,
     * or the signature does not match.
     */
    public function resolve(Request $request): ?ApiKey
    {
        $rawKey = $request->header(config('keystone.header', 'X-API-Key'))
               ?? $request->query(config('keystone.query_param', 'api_key'));

        if (! is_string($rawKey) || $rawKey === '') {
            return null;
        }

        $signature = $request->header(config('keystone.signature_header', 'X-API-Signature'));

        if (! is_string($signature) || $signature === '') {
            return null;
        }

        $apiKey = $this->findByApiKey($rawKey);

        if ($apiKey === null || ! $apiKey->isValid()) {
            return null;
        }

        if (! $apiKey->verifySignature($signature)) {
            return null;
        }

        return $apiKey;
    }

    /**
     * Cache-aware key lookup:
     *   1. in-memory ($resolved map)
     *   2. Redis (ApiKeyCacheRepository)
     *   3. Database (with optional write-through to Redis)
     */
    public function findByApiKey(string $rawKey): ?ApiKey
    {
        if (array_key_exists($rawKey, $this->resolved)) {
            return $this->resolved[$rawKey];
        }

        // Redis lookup
        $apiKey = $this->cache->get($rawKey);

        // Database fallback
        if ($apiKey === null) {
            $apiKey = ApiKey::where('api_key', $rawKey)->first();

            if ($apiKey !== null && config('keystone.cache.warm_on_miss', true)) {
                $this->cache->put($apiKey);
            }
        }

        return $this->resolved[$rawKey] = $apiKey;
    }

    /**
     * Convenience wrapper — delegates to the owner model's createApiKey().
     *
     * @param  array{scopes?: array<int,string>, expires_at?: \Carbon\CarbonImmutable|null}  $options
     * @return array{api_key: string, secret_key: string, model: ApiKey}
     */
    public function generate(Model $owner, string $name, array $options = []): array
    {
        return $owner->createApiKey(
            $name,
            $options['scopes'] ?? [],
            $options['expires_at'] ?? null,
        );
    }

    /**
     * Force-evict an api_key from both the in-memory map and Redis.
     */
    public function invalidate(string $apiKey): void
    {
        unset($this->resolved[$apiKey]);
        $this->cache->forget($apiKey);
    }

    /**
     * Clear the in-memory resolved map.
     * Called by KeystoneBootstrapper on every tenant switch.
     */
    public function flushResolved(): void
    {
        $this->resolved = [];
    }
}
