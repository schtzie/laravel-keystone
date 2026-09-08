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
