<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Cache;

use Illuminate\Contracts\Cache\Repository;
use Schtzie\Keystone\Models\Keystone;

/**
 * Single source of truth for all Keystone Cache interactions.
 *
 * Tenant-namespace-aware: prefixes every cache key with the current tenant's
 * ID when a tenancy mode other than 'none' is active, keeping tenant data
 * strictly separated within a shared cache instance.
 *
 * Cache key layout:
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

        $this->addClientToOwnerIndex($ownerEntry, $client->client);
    }

    /**
     * Evict a single client entry from the cache.
     */
    public function forget(string $client): void
    {
        $this->cache->forget($this->keyFor($client));
    }

    /**
     * Evict multiple client entries from the cache in a single operation.
     *
     * @param  array<int, string>  $clients
     */
    public function forgetMany(array $clients): void
    {
        if ($clients === []) {
            return;
        }

        if (method_exists($this->cache, 'forgetMany')) {
            $keys = array_map(fn (string $client): string => $this->keyFor($client), $clients);
            $this->cache->forgetMany($keys);

            return;
        }

        foreach ($clients as $client) {
            $this->forget($client);
        }
    }

    /**
     * Evict all cached keys belonging to a specific owner (e.g. on revokeAll).
     */
    public function forgetOwner(string $type, int|string $id): void
    {
        $ownerEntry = $this->ownerKeyFor($type, $id);
        $store = $this->cache instanceof \Illuminate\Cache\Repository ? $this->cache->getStore() : null;

        $keys = [];

        if ($store instanceof \Illuminate\Cache\RedisStore) {
            /** @var \Illuminate\Redis\Connections\Connection $connection */
            $connection = $store->connection();
            /** @var array<int, mixed> $members */
            $members = (array) $connection->sMembers($ownerEntry);
            $keys = array_values(array_filter($members, 'is_string'));
            $connection->del($ownerEntry);
        } else {
            /** @var string $json */
            $json = $this->cache->get($ownerEntry, '[]');
            /** @var mixed $decoded */
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                $keys = array_values(array_filter($decoded, 'is_string'));
            }

            $this->cache->forget($ownerEntry);
        }

        if ($keys !== []) {
            $this->forgetMany($keys);
        }
    }

    /**
     * Flush all Keystone cache entries within the current tenant's namespace.
     * Primarily a dev/test utility.
     */
    public function flush(): void
    {
        // When using an array store (tests) or a store without tag support,
        // we do a best-effort forget using the known prefix. For production
        // usage, callers should prefer per-key or per-owner invalidation.
        if ($this->cache instanceof \Illuminate\Cache\Repository) {
            $this->cache->getStore()->flush();
        } elseif (method_exists($this->cache, 'flush')) {
            /** @var callable $flusher */
            $flusher = [$this->cache, 'flush'];
            $flusher();
        }
    }

    /**
     * Returns a tenant-specific segment for Cache key construction.
     * Empty string when tenancy is disabled or not yet initialised.
     */
    public function tenantSegment(): string
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
     * Adds a client identifier to the owner index set.
     */
    private function addClientToOwnerIndex(string $ownerEntry, string $client): void
    {
        $store = $this->cache instanceof \Illuminate\Cache\Repository ? $this->cache->getStore() : null;

        if ($store instanceof \Illuminate\Cache\RedisStore) {
            /** @var \Illuminate\Redis\Connections\Connection $connection */
            $connection = $store->connection();
            $connection->sAdd($ownerEntry, $client);

            if ($this->ttl !== null) {
                $connection->expire($ownerEntry, $this->ttl);
            }

            return;
        }

        // Fallback for non-Redis cache stores
        /** @var string $existingJson */
        $existingJson = $this->cache->get($ownerEntry, '[]');
        /** @var mixed $set */
        $set = json_decode($existingJson, true);

        if (! is_array($set)) {
            $set = [];
        }

        if (! in_array($client, $set, true)) {
            $set[] = $client;
            $this->cache->put($ownerEntry, json_encode(array_values($set)), $this->ttl);
        }
    }

    /**
     * Construct the fully namespaced Cache key for an individual client.
     *
     * Format: {prefix}:{tenant_id}:key:{client} (or {prefix}:key:{client} when tenancy is disabled).
     *
     * @param  string  $client  The plain client identifier.
     * @return string The formatted cache key string.
     */
    private function keyFor(string $client): string
    {
        return "{$this->prefix}:{$this->tenantSegment()}key:{$client}";
    }

    /**
     * Construct the fully namespaced Cache key for an owner's key index.
     *
     * Used for bulk cache invalidation when revoking all keys belonging to an owner.
     *
     * @param  string  $type  The polymorphic model class name (e.g. App\Models\User).
     * @param  int|string  $id  The polymorphic model primary key.
     * @return string The formatted owner index key string.
     */
    private function ownerKeyFor(string $type, int|string $id): string
    {
        return "{$this->prefix}:{$this->tenantSegment()}owner:{$type}:{$id}";
    }
}
