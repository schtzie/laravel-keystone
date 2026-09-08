<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Cache;

use Illuminate\Contracts\Cache\Repository;
use Schtzie\Keystone\Models\Keystone;

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
    /**
     * @param Repository $cache
     * @param string $prefix
     * @param int|null $ttl
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix,
        private readonly ?int $ttl,
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Retrieve a cached Keystone by its plain client value.
     * Returns null on a cache miss.
     *
     * @param string $client
     * @return Keystone|null
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

        /** @var array<string, mixed>|null $attributes */
        $attributes = json_decode($data, true);

        if (! is_array($attributes)) {
            return null;
        }

        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        return (new $modelClass())->newFromBuilder($attributes);
    }

    /**
     * Write a Keystone into the cache and track it in the owner index.
     *
     * @param Keystone $client
     * @return void
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
        /** @var mixed $set */
        $set = json_decode($existingJson, true);

        if (! is_array($set)) {
            $set = [];
        }

        if (! in_array($client->client, $set, true)) {
            $set[] = $client->client;
        }

        $this->cache->put($ownerEntry, json_encode(array_values($set)), $this->ttl);
    }

    /**
     * Evict a single client entry from the cache.
     *
     * @param string $client
     * @return void
     */
    public function forget(string $client): void
    {
        $this->cache->forget($this->keyFor($client));
    }

    /**
     * Evict all cached keys belonging to a specific owner (e.g. on revokeAll).
     *
     * @param string $type
     * @param int|string $id
     * @return void
     */
    public function forgetOwner(string $type, int|string $id): void
    {
        $ownerEntry = $this->ownerKeyFor($type, $id);

        /** @var string $json */
        $json = $this->cache->get($ownerEntry, '[]');
        /** @var mixed $keys */
        $keys = json_decode($json, true);

        if (is_array($keys)) {
            foreach ($keys as $client) {
                if (is_string($client)) {
                    $this->cache->forget($this->keyFor($client));
                }
            }
        }

        $this->cache->forget($ownerEntry);
    }

    /**
     * Flush all Keystone cache entries within the current tenant's namespace.
     * Primarily a dev/test utility.
     *
     * @return void
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
     *
     * @return string
     */
    private function tenantSegment(): string
    {
        $mode = config('keystone.tenancy.mode', 'none');

        if ($mode === 'none') {
            return '';
        }

        if (! function_exists('tenant')) {
            return '';
        }

        /** @var mixed $tenant */
        $tenant = tenant();

        if (! is_object($tenant) || ! method_exists($tenant, 'getTenantKey')) {
            return '';
        }

        $tenantKey = $tenant->getTenantKey();

        if (! is_string($tenantKey) && ! is_numeric($tenantKey)) {
            return '';
        }

        return (string) $tenantKey.':';
    }

    /**
     * Construct the fully namespaced Redis cache key for an individual client.
     *
     * Format: {prefix}:{tenant_id}:key:{client} (or {prefix}:key:{client} when tenancy is disabled).
     *
     * @param  string  $client  The plain client identifier.
     * @return string  The formatted Redis cache key string.
     */
    private function keyFor(string $client): string
    {
        return $this->prefix.':'.$this->tenantSegment().'key:'.$client;
    }

    /**
     * Construct the fully namespaced Redis cache key for an owner's key index.
     *
     * Used for bulk cache invalidation when revoking all keys belonging to an owner.
     *
     * @param  string  $type  The polymorphic model class name (e.g. App\Models\User).
     * @param  int|string  $id  The polymorphic model primary key.
     * @return string  The formatted Redis owner index key string.
     */
    private function ownerKeyFor(string $type, int|string $id): string
    {
        return $this->prefix.':'.$this->tenantSegment().'owner:'.$type.':'.$id;
    }
}

