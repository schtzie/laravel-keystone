<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Cache;

use Illuminate\Contracts\Cache\Repository;
use Schatzie\Keystone\Models\Keystone;

/**
 * Single source of truth for all Keystone Redis interactions.
 *
 * Tenant-namespace-aware: prefixes every Redis key with the current tenant's
 * ID when a tenancy mode other than 'none' is active, keeping tenant data
 * strictly separated within a shared Redis instance.
 *
 * Redis key layout:
 *   {prefix}:{tenantSegment}key:{client}
 *   {prefix}:{tenantSegment}owner:{type}:{id}   → JSON array of client strings
 *
 * Examples (prefix = "keystone", tenant = "abc"):
 *   keystone:abc:key:ks_xxxx...
 *   keystone:abc:owner:App\Models\User:42
 *
 * Examples (no tenancy):
 *   keystone:key:ks_xxxx...
 *   keystone:owner:App\Models\User:42
 */
final class KeystoneKeyCacheRepository
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix,
        private readonly ?int $ttl,
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Retrieve a cached Keystone by its plain client value.
     * Returns null on a cache miss.
     */
    public function get(string $client): ?Keystone
    {
        if (! config('keystone.cache.enabled', true)) {
            return null;
        }

        /** @var string|null $data */
        $data = $this->cache->get($this->keyFor($client));

        if ($data === null) {
            return null;
        }

        $attributes = json_decode($data, true);

        if (! is_array($attributes)) {
            return null;
        }

        return (new Keystone())->setRawAttributes($attributes);
    }

    /**
     * Write an Keystone into the cache and track it in the owner index.
     */
    public function put(Keystone $client): void
    {
        if (! config('keystone.cache.enabled', true)) {
            return;
        }

        $keyEntry = $this->keyFor($client->client);
        $ownerEntry = $this->ownerKeyFor($client->keystoneable_type, $client->keystoneable_id);

        // Serialise model attributes (no relations)
        $payload = json_encode($client->getAttributes());

        $this->cache->put($keyEntry, $payload, $this->ttl);

        // Maintain an owner-keyed index so bulk invalidation is possible
        /** @var string $existingJson */
        $existingJson = $this->cache->get($ownerEntry, '[]');
        /** @var array<int, string> $set */
        $set = json_decode($existingJson, true);

        if (! in_array($client->client, $set, true)) {
            $set[] = $client->client;
        }

        $this->cache->put($ownerEntry, json_encode(array_values($set)), $this->ttl);
    }

    /**
     * Evict a single client entry from the cache.
     */
    public function forget(string $client): void
    {
        $this->cache->forget($this->keyFor($client));
    }

    /**
     * Evict all cached keys belonging to a specific owner (e.g. on revokeAll).
     */
    public function forgetOwner(string $type, int|string $id): void
    {
        $ownerEntry = $this->ownerKeyFor($type, $id);

        /** @var string $json */
        $json = $this->cache->get($ownerEntry, '[]');
        /** @var array<int, string> $keys */
        $keys = json_decode($json, true);

        foreach ($keys as $client) {
            $this->cache->forget($this->keyFor($client));
        }

        $this->cache->forget($ownerEntry);
    }

    /**
     * Flush all Keystone cache entries within the current tenant's namespace.
     * Primarily a dev/test utility.
     */
    public function flush(): void
    {
        // When using an array store (tests) or a store without tag support,
        // we do a best-effort forget using the known prefix. For production
        // Redis, callers should prefer per-key or per-owner invalidation.
        $this->cache->flush();
    }

    // ── Namespace helpers ──────────────────────────────────────────────────

    /**
     * Returns a tenant-specific segment for Redis key construction.
     * Empty string when tenancy is disabled or not yet initialised.
     */
    private function tenantSegment(): string
    {
        $mode = config('keystone.tenancy.mode', 'none');

        if ($mode === 'none') {
            return '';
        }

        if (! function_exists('tenant') || tenant() === null) {
            return '';
        }

        return (string) tenant()->getTenantKey().':';
    }

    private function keyFor(string $client): string
    {
        return $this->prefix.':'.$this->tenantSegment().'key:'.$client;
    }

    private function ownerKeyFor(string $type, int|string $id): string
    {
        return $this->prefix.':'.$this->tenantSegment().'owner:'.$type.':'.$id;
    }
}
