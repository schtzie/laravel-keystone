<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Tests\Fixtures\User;

// ── Helpers ────────────────────────────────────────────────────────────────

function cacheRepo(): KeystoneKeyCacheRepository
{
    return app(KeystoneKeyCacheRepository::class);
}

function makeUserWithKey(array $scopes = []): array
{
    $user = User::create(['name' => 'Cache Test User']);
    $result = $user->createKeystone('Cache Test', $scopes);

    return [$user, $result];
}

function signedHeaders(array $result): array
{
    return [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => hash_hmac('sha256', $result['client'], $result['secret']),
    ];
}

// ── Write-Through on Cache Miss ────────────────────────────────────────────

it('populates cache after first DB lookup (cache miss)', function (): void {
    [$user, $result] = makeUserWithKey();

    // Nothing in cache yet
    expect(cacheRepo()->get($result['client']))->toBeNull();

    Route::middleware('api.key')->get('/cache-test', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-test', signedHeaders($result))->assertOk();

    // Cache should now be warm
    expect(cacheRepo()->get($result['client']))->not->toBeNull();
});

it('serves the key from cache on subsequent requests without hitting the DB', function (): void {
    $this->freezeTime();

    [$user, $result] = makeUserWithKey();

    Route::middleware('api.key')->get('/cache-hit', fn () => response()->json(['ok' => true]));

    // First request — DB hit, cache warm
    $this->getJson('/cache-hit', signedHeaders($result))->assertOk();

    // Manually track DB queries on the second request
    $queries = 0;
    Illuminate\Support\Facades\DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $this->getJson('/cache-hit', signedHeaders($result))->assertOk();

    expect($queries)->toBe(0);
});

// ── Cache Invalidation on Revoke ───────────────────────────────────────────

it('evicts the cache entry when a key is revoked', function (): void {
    [$user, $result] = makeUserWithKey();

    // Warm the cache manually
    cacheRepo()->put($result['model']);
    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    $result['model']->revoke();

    // Keystone::updated event fires → cache invalidation
    expect(cacheRepo()->get($result['client']))->toBeNull();
});

it('evicts all owner cache entries when revokeAllKeystones is called', function (): void {
    $user = User::create(['name' => 'Bulk Revoke User']);
    $result1 = $user->createKeystone('Key 1');
    $result2 = $user->createKeystone('Key 2');

    cacheRepo()->put($result1['model']);
    cacheRepo()->put($result2['model']);

    $user->revokeAllKeystones();

    expect(cacheRepo()->get($result1['client']))->toBeNull();
    expect(cacheRepo()->get($result2['client']))->toBeNull();
});

// ── Rotate Invalidation ────────────────────────────────────────────────────

it('evicts the old key from cache when rotated', function (): void {
    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $rotated = $user->rotateKeystone($result['model']);

    // Old key should be gone from cache
    expect(cacheRepo()->get($result['client']))->toBeNull();

    // New key is NOT pre-cached until first auth request
    expect(cacheRepo()->get($rotated['client']))->toBeNull();
});

// ── Cache Disabled ─────────────────────────────────────────────────────────

it('always hits the database when cache is disabled', function (): void {
    config(['keystone.cache.enabled' => false]);

    [$user, $result] = makeUserWithKey();

    Route::middleware('api.key')->get('/cache-disabled', fn () => response()->json(['ok' => true]));

    $queries = 0;
    Illuminate\Support\Facades\DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    $this->getJson('/cache-disabled', signedHeaders($result))->assertOk();

    expect($queries)->toBeGreaterThan(0);

    // Cache should remain empty
    expect(cacheRepo()->get($result['client']))->toBeNull();
})->after(function (): void {
    config(['keystone.cache.enabled' => true]);
});

// ── Cache Namespace ────────────────────────────────────────────────────────

it('stores the cache entry under the expected key format', function (): void {
    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $expectedKey = 'keystone:key:'.$result['client'];
    expect(Cache::store('array')->has($expectedKey))->toBeTrue();
});

it('flushes the cache repository cleanly', function (): void {
    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);
    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    cacheRepo()->flush();

    expect(cacheRepo()->get($result['client']))->toBeNull();
});

// ── Cache Configuration Scenarios ──────────────────────────────────────────

it('supports different cache stores', function (string $store): void {
    // We only test stores that are likely available in the default testing environment
    config(['keystone.cache.store' => $store]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(\Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();
    
    cacheRepo()->put($result['model']);
    
    $expectedKey = 'keystone:key:'.$result['client'];
    
    expect(cacheRepo()->get($result['client']))->not->toBeNull()
        ->and(Cache::store($store)->has($expectedKey))->toBeTrue();
})->with(['array', 'file']);

it('supports both phpredis and predis redis clients', function (string $redisClient): void {
    config([
        'database.redis.client' => $redisClient,
        'keystone.cache.store' => 'redis',
    ]);
    
    app()->forgetInstance('redis');
    \Illuminate\Support\Facades\Redis::clearResolvedInstances();
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(\Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();
    
    cacheRepo()->put($result['model']);
    
    $expectedKey = 'keystone:key:'.$result['client'];
    
    expect(Cache::store('redis')->has($expectedKey))->toBeTrue()
        ->and(cacheRepo()->get($result['client']))->not->toBeNull();
})->with(['phpredis', 'predis']);

it('respects the ttl configuration', function (): void {
    config(['keystone.cache.ttl' => 60]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(\Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();
    
    cacheRepo()->put($result['model']);
    
    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    $this->travel(61)->seconds();

    expect(cacheRepo()->get($result['client']))->toBeNull();
});

it('keeps cache indefinitely if ttl is null', function (): void {
    config(['keystone.cache.ttl' => null]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(\Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();
    
    cacheRepo()->put($result['model']);
    
    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    $this->travel(10)->years();

    expect(cacheRepo()->get($result['client']))->not->toBeNull();
});

it('does not write-through to cache on miss if warm_on_miss is false', function (): void {
    config([
        'keystone.cache.warm_on_miss' => false,
        'keystone.cache.refresh_on_use' => false,
    ]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(\Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();

    Route::middleware('api.key')->get('/cache-warm-miss', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-warm-miss', signedHeaders($result))->assertOk();

    // Cache should still be empty
    expect(cacheRepo()->get($result['client']))->toBeNull();
});

it('does not refresh cache ttl on use if refresh_on_use is false', function (): void {
    config(['keystone.cache.refresh_on_use' => false]);
    config(['keystone.cache.ttl' => 60]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(\Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();
    
    cacheRepo()->put($result['model']);

    $this->travel(30)->seconds();

    Route::middleware('api.key')->get('/cache-refresh-use', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-refresh-use', signedHeaders($result))->assertOk();

    $this->travel(31)->seconds();

    // Total 61 seconds elapsed, should be expired since it wasn't refreshed
    expect(cacheRepo()->get($result['client']))->toBeNull();
});

it('refreshes cache ttl on use if refresh_on_use is true', function (): void {
    config(['keystone.cache.refresh_on_use' => true]);
    config(['keystone.cache.ttl' => 60]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(\Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();
    
    cacheRepo()->put($result['model']);

    $this->travel(30)->seconds();

    Route::middleware('api.key')->get('/cache-refresh-use-true', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-refresh-use-true', signedHeaders($result))->assertOk();

    $this->travel(31)->seconds();

    // Total 61 seconds elapsed, but refreshed at 30 seconds, so it should still be alive
    expect(cacheRepo()->get($result['client']))->not->toBeNull();
});

// ── Tenancy Cache Scenarios ──────────────────────────────────────────────

it('prefixes cache keys based on tenancy mode', function (string $mode, string $expectedPrefix): void {
    config(['keystone.tenancy.mode' => $mode]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);

    if ($mode !== 'none') {
        // Mock a tenant context using FakeTenant
        \Schtzie\Keystone\Tests\Support\FakeTenant::set('test-tenant');
    }

    if ($mode === 'single_db' && \Illuminate\Support\Facades\Schema::hasTable('keystoneables') && ! \Illuminate\Support\Facades\Schema::hasColumn('keystoneables', 'tenant_id')) {
        \Illuminate\Support\Facades\Schema::table('keystoneables', function ($table): void {
            $table->string('tenant_id')->nullable()->index('keystoneables_tenant_id_index')->after('id');
        });
    }

    [$user, $result] = makeUserWithKey();
    
    cacheRepo()->put($result['model']);

    $expectedKey = $expectedPrefix . 'key:' . $result['client'];
    
    expect(Cache::store('array')->has($expectedKey))->toBeTrue();
    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    if ($mode !== 'none') {
        \Schtzie\Keystone\Tests\Support\FakeTenant::clear();
        config(['keystone.tenancy.mode' => 'none']);
    }

    if ($mode === 'single_db' && \Illuminate\Support\Facades\Schema::hasColumn('keystoneables', 'tenant_id')) {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS keystoneables_tenant_id_index');
        \Illuminate\Support\Facades\Schema::table('keystoneables', function ($table): void {
            $table->dropColumn('tenant_id');
        });
    }
})->with([
    ['none', 'keystone:'],
    ['single_db', 'keystone:test-tenant:'],
    ['multi_db', 'keystone:test-tenant:'],
]);

