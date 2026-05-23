<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Schatzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schatzie\Keystone\Models\Keystone;

/**
 * Orchestrates the Redis-first Client resolution pipeline:
 *   in-memory → Redis → database → HMAC verification
 *
 * Registered as a singleton so the $resolved map persists across
 * multiple service calls within the same request. The map is flushed
 * by KeystoneBootstrapper on every tenant switch (Octane safety).
 */
final class KeystoneService
{
    /**
     * Per-request in-memory map: plain client → resolved Keystone|null.
     * Prevents redundant Redis/DB round-trips within a single request.
     *
     * @var array<string, Keystone|null>
     */
    private array $resolved = [];

    public function __construct(private readonly KeystoneKeyCacheRepository $cache) {}

    // ── Resolution ─────────────────────────────────────────────────────────

    /**
     * Resolve an Keystone from the incoming request.
     *
     * Reads the plain client from the configured header / query param,
     * looks it up (cache → DB), then verifies the HMAC-SHA256 signature.
     *
     * Returns null if the key is missing, invalid, revoked, expired,
     * or the signature does not match.
     */
    public function resolve(Request $request): ?Keystone
    {
        $rawKey = $request->header(config('keystone.header', 'X-Client-Id'))
               ?? $request->query(config('keystone.query_param', 'client'));

        if (! is_string($rawKey) || $rawKey === '') {
            return null;
        }

        $signature = $request->header(config('keystone.signature_header', 'X-API-Signature'));

        if (! is_string($signature) || $signature === '') {
            return null;
        }

        $client = $this->findByKeystone($rawKey);

        if ($client === null || ! $client->isValid()) {
            return null;
        }

        if (! $client->verifySignature($signature)) {
            return null;
        }

        return $client;
    }

    /**
     * Cache-aware key lookup:
     *   1. in-memory ($resolved map)
     *   2. Redis (KeystoneKeyCacheRepository)
     *   3. Database (with optional write-through to Redis)
     */
    public function findByKeystone(string $rawKey): ?Keystone
    {
        if (array_key_exists($rawKey, $this->resolved)) {
            return $this->resolved[$rawKey];
        }

        // Redis lookup
        $client = $this->cache->get($rawKey);

        // Database fallback
        if ($client === null) {
            $client = Keystone::where('client', $rawKey)->first();

            if ($client !== null && config('keystone.cache.warm_on_miss', true)) {
                $this->cache->put($client);
            }
        }

        return $this->resolved[$rawKey] = $client;
    }

    /**
     * Convenience wrapper — delegates to the owner model's createKeystone().
     *
     * @param  array{scopes?: array<int,string>, expires_at?: \Carbon\CarbonImmutable|null}  $options
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function generate(Model $owner, string $name, array $options = []): array
    {
        /** @phpstan-ignore method.notFound */
        return $owner->createKeystone(
            $name,
            $options['scopes'] ?? [],
            $options['expires_at'] ?? null,
        );
    }

    /**
     * Force-evict an client from both the in-memory map and Redis.
     */
    public function invalidate(string $client): void
    {
        unset($this->resolved[$client]);
        $this->cache->forget($client);
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
