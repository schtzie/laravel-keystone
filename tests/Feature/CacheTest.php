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

dataset('cache_stores', [
    'array', 'database', 'file', 'memcached', 'redis', 'dynamodb', 'octane', 'null',
]);

function setupCacheStore(string $store): void
{
    if ($store === 'database' && ! Illuminate\Support\Facades\Schema::hasTable('cache')) {
        Illuminate\Support\Facades\Schema::create('cache', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    try {
        if ($store === 'redis') {
            config(['keystone.cache.store' => 'redis']);
            app()->forgetInstance('redis');
            Illuminate\Support\Facades\Redis::clearResolvedInstances();
            Cache::forgetDriver('redis');
        } else {
            config(['keystone.cache.store' => $store]);
        }

        if ($store !== 'null') {
            Cache::store($store)->put('ping', 'pong', 10);
            if (Cache::store($store)->get('ping') !== 'pong') {
                test()->markTestSkipped("Store [$store] is not responding.");
            }
        }
    } catch (Throwable $e) {
        test()->markTestSkipped("Store [$store] is not available: {$e->getMessage()}");
    }
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(Schtzie\Keystone\Services\KeystoneService::class);
}

beforeEach(function (): void {
    config(['keystone.cache.enabled' => true]);
});

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

it('handles cache enabled option', function (bool $enabled): void {
    config(['keystone.cache.enabled' => $enabled]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();

    Route::middleware('api.key')->get('/cache-enabled-test', fn () => response()->json(['ok' => true]));

    // First request - always hits DB. If enabled=true, it writes to cache.
    $this->getJson('/cache-enabled-test', signedHeaders($result))->assertOk();

    $queries = 0;
    Illuminate\Support\Facades\DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    if (! $enabled) {
        app(Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();
    }

    // Second request
    $this->getJson('/cache-enabled-test', signedHeaders($result))->assertOk();

    if ($enabled) {
        // Cache is enabled, second request should hit cache (0 DB queries)
        expect($queries)->toBe(0);
        expect(cacheRepo()->get($result['client']))->not->toBeNull();
    } else {
        // Cache is disabled, second request must hit DB again
        expect($queries)->toBeGreaterThan(0);
        expect(cacheRepo()->get($result['client']))->toBeNull();
    }
})->with([true, false])->after(function (): void {
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
    setupCacheStore($store);

    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $expectedKey = 'keystone:key:'.$result['client'];

    if ($store === 'null') {
        expect(cacheRepo()->get($result['client']))->toBeNull();

        return;
    }

    expect(cacheRepo()->get($result['client']))->not->toBeNull()
        ->and(Cache::store($store)->has($expectedKey))->toBeTrue();
})->with('cache_stores');

it('supports both phpredis and predis redis clients', function (string $redisClient): void {
    config([
        'database.redis.client' => $redisClient,
        'keystone.cache.store' => 'redis',
    ]);

    app()->forgetInstance('redis');
    Illuminate\Support\Facades\Redis::clearResolvedInstances();
    Cache::forgetDriver('redis');

    app()->forgetInstance(KeystoneKeyCacheRepository::class);
    app()->forgetInstance(Schtzie\Keystone\Services\KeystoneService::class);

    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $expectedKey = 'keystone:key:'.$result['client'];

    expect(Cache::store('redis')->has($expectedKey))->toBeTrue()
        ->and(cacheRepo()->get($result['client']))->not->toBeNull();
})->with(['phpredis', 'predis']);

it('respects the ttl configuration', function (string $store): void {
    if ($store === 'null') {
        test()->markTestSkipped('Not applicable for null store');
    }

    setupCacheStore($store);
    config(['keystone.cache.ttl' => 60]);

    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    $this->travel(61)->seconds();

    expect(cacheRepo()->get($result['client']))->toBeNull();
    $this->travelBack();
})->with(['array', 'database', 'file']);

it('keeps cache indefinitely if ttl is null', function (string $store): void {
    if (in_array($store, ['null', 'database'])) {
        test()->markTestSkipped("Not applicable for $store store");
    }

    setupCacheStore($store);
    config(['keystone.cache.ttl' => null]);

    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    $this->travel(10)->years();

    expect(cacheRepo()->get($result['client']))->not->toBeNull();
    $this->travelBack();
})->with(['array', 'file']);

it('does not write-through to cache on miss if warm_on_miss is false', function (string $store): void {
    if ($store === 'null') {
        test()->markTestSkipped('Not applicable for null store');
    }

    setupCacheStore($store);
    config([
        'keystone.cache.warm_on_miss' => false,
        'keystone.cache.refresh_on_use' => false,
    ]);

    [$user, $result] = makeUserWithKey();

    Route::middleware('api.key')->get('/cache-warm-miss', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-warm-miss', signedHeaders($result))->assertOk();

    // Cache should still be empty
    expect(cacheRepo()->get($result['client']))->toBeNull();
})->with('cache_stores');

it('does not refresh cache ttl on use if refresh_on_use is false', function (string $store): void {
    if ($store === 'null') {
        test()->markTestSkipped('Not applicable for null store');
    }

    setupCacheStore($store);
    config(['keystone.cache.refresh_on_use' => false]);
    config(['keystone.cache.ttl' => 60]);

    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $this->travel(30)->seconds();

    Route::middleware('api.key')->get('/cache-refresh-use', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-refresh-use', signedHeaders($result))->assertOk();

    $this->travel(31)->seconds();

    // Total 61 seconds elapsed, should be expired since it wasn't refreshed
    expect(cacheRepo()->get($result['client']))->toBeNull();
    $this->travelBack();
})->with(['array', 'database', 'file']);

it('refreshes cache ttl on use if refresh_on_use is true', function (string $store): void {
    if ($store === 'null') {
        test()->markTestSkipped('Not applicable for null store');
    }

    setupCacheStore($store);
    config(['keystone.cache.refresh_on_use' => true]);
    config(['keystone.cache.ttl' => 60]);

    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $this->travel(30)->seconds();

    Route::middleware('api.key')->get('/cache-refresh-use-true', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-refresh-use-true', signedHeaders($result))->assertOk();

    $this->travel(31)->seconds();

    // Total 61 seconds elapsed, but refreshed at 30 seconds, so it should still be alive
    expect(cacheRepo()->get($result['client']))->not->toBeNull();
    $this->travelBack();
})->with(['array', 'database', 'file']);

// ── Tenancy Cache Scenarios ──────────────────────────────────────────────

it('prefixes cache keys based on tenancy mode', function (string $mode, string $expectedPrefix): void {
    if ($mode !== 'none' && ! class_exists(\Stancl\Tenancy\Tenancy::class)) {
        test()->markTestSkipped('Tenancy not installed');
    }

    config(['keystone.tenancy.mode' => $mode]);
    app()->forgetInstance(KeystoneKeyCacheRepository::class);

    if ($mode === 'single_db' && Illuminate\Support\Facades\Schema::hasTable('keystoneables') && ! Illuminate\Support\Facades\Schema::hasColumn('keystoneables', 'tenant_id')) {
        Illuminate\Support\Facades\Schema::table('keystoneables', function ($table): void {
            $table->string('tenant_id')->nullable()->index('keystoneables_tenant_id_index')->after('id');
        });
    }

    if ($mode !== 'none') {
        if ($mode === 'multi_db') {
            $tenant = Schtzie\Keystone\Tests\Fixtures\Tenant::firstOrCreate(['id' => 'test-tenant']);
            tenancy()->initialize($tenant);

            $schema = app('db')->connection('tenant')->getSchemaBuilder();
            if (! $schema->hasTable('keystoneables')) {
                $schema->create('keystoneables', function ($table) {
                    $table->id();
                    $table->morphs('keystoneable');
                    $table->string('name', 255);
                    $table->string('client', 255)->unique();
                    $table->string('secret', 255);
                    $table->text('scopes')->nullable();
                    $table->json('ip_allowlist')->nullable();
                    $table->json('ip_blocklist')->nullable();
                    $table->json('metadata')->nullable();
                    $table->unsignedInteger('rate_limit')->nullable();
                    $table->timestamp('expires_at')->nullable();
                    $table->timestamp('grace_expires_at')->nullable();
                    $table->timestamp('revoked_at')->nullable();
                    $table->timestamps();
                });
            }
        } else {
            $tenant = Schtzie\Keystone\Tests\Fixtures\Tenant::firstOrCreate(['id' => 'test-tenant']);
            tenancy()->initialize($tenant);
        }
    }

    // In multi_db mode we need the users table as well if we are creating a user
    if ($mode === 'multi_db') {
        $schema = app('db')->connection('tenant')->getSchemaBuilder();
        if (! $schema->hasTable('users')) {
            $schema->create('users', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->timestamps();
            });
        }
    }

    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $expectedKey = $expectedPrefix.'key:'.$result['client'];

    expect(Cache::store('array')->has($expectedKey))->toBeTrue();
    expect(cacheRepo()->get($result['client']))->not->toBeNull();

    if ($mode !== 'none') {
        tenancy()->end();
        config(['keystone.tenancy.mode' => 'none']);
    }

    if ($mode === 'single_db' && Illuminate\Support\Facades\Schema::hasColumn('keystoneables', 'tenant_id')) {
        Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS keystoneables_tenant_id_index');
        Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS keystoneables_tenant_key_index');
        Illuminate\Support\Facades\Schema::table('keystoneables', function ($table): void {
            $table->dropColumn('tenant_id');
        });
    }
})->with([
    ['none', 'keystone:'],
    ['single_db', 'keystone:test-tenant:'],
    ['multi_db', 'keystone:test-tenant:'],
]);
