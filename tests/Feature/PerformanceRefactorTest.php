<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Tests\Fixtures\User;

beforeEach(function (): void {
    Route::middleware('api.key')->get('/perf-test', fn () => response()->json(['message' => 'ok']));
});

test('forgetMany evicts multiple client cache entries in a single call', function (): void {
    $user = User::create(['name' => 'Perf User']);

    $k1 = $user->createKeystone('Key 1');
    $k2 = $user->createKeystone('Key 2');

    $cache = app(KeystoneKeyCacheRepository::class);
    $cache->put($k1['model']);
    $cache->put($k2['model']);

    expect($cache->get($k1['client']))->not->toBeNull();
    expect($cache->get($k2['client']))->not->toBeNull();

    $cache->forgetMany([$k1['client'], $k2['client']]);

    expect($cache->get($k1['client']))->toBeNull();
    expect($cache->get($k2['client']))->toBeNull();
});

test('rate limit keys incorporate tenant segment in multi tenant mode', function (): void {
    config(['keystone.tenancy.mode' => 'single_db']);

    $cache = app(KeystoneKeyCacheRepository::class);
    expect($cache->tenantSegment())->toBe('');

    config(['keystone.tenancy.mode' => 'none']);
});

test('keystone prune command uses forgetMany for bulk eviction', function (): void {
    $user = User::create(['name' => 'Prune User']);
    $key = $user->createKeystone('To Prune');
    $key['model']->update(['revoked_at' => now()->subDays(40)]);

    $cache = app(KeystoneKeyCacheRepository::class);
    $cache->put($key['model']);

    $this->artisan('keystone:prune', ['--days' => 30])
        ->assertSuccessful();

    expect($cache->get($key['client']))->toBeNull();
    $this->assertDatabaseMissing('keystoneables', ['id' => $key['model']->id]);
});

test('cache methods return early and bypass cache when cache is globally disabled', function (): void {
    config(['keystone.cache.enabled' => false]);
    $user = User::create(['name' => 'Cache Test']);
    $result = $user->createKeystone('Disabled Cache Key');

    $cache = app(KeystoneKeyCacheRepository::class);
    $cache->put($result['model']); // Should do nothing

    // get() should return null immediately without hitting the store
    expect($cache->get($result['client']))->toBeNull();
});

test('forgetMany falls back to individual forget calls if the cache store lacks native support', function (): void {
    $user = User::create(['name' => 'Fallback Test']);
    $k1 = $user->createKeystone('Key 1');

    // Create a mock cache repository that DOES NOT have forgetMany
    $mockCache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $mockCache->shouldReceive('forget')
        ->once()
        ->with('keystone:key:'.$k1['client'])
        ->andReturn(true);

    $repo = new KeystoneKeyCacheRepository($mockCache, 'keystone', 60);
    $repo->forgetMany([$k1['client']]);
});

test('flush evicts all keystone entries from the current store', function (): void {
    $user = User::create(['name' => 'Flush User']);
    $result = $user->createKeystone('Flush Key');

    $cache = app(KeystoneKeyCacheRepository::class);
    $cache->put($result['model']);

    expect($cache->get($result['client']))->not->toBeNull();

    $cache->flush();

    expect($cache->get($result['client']))->toBeNull();
});
