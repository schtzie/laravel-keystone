<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Schatzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schatzie\Keystone\Tests\Fixtures\User;

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

it('populates Redis after first DB lookup (cache miss)', function (): void {
    [$user, $result] = makeUserWithKey();

    // Nothing in cache yet
    expect(cacheRepo()->get($result['client']))->toBeNull();

    Route::middleware('api.key')->get('/cache-test', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-test', signedHeaders($result))->assertOk();

    // Cache should now be warm
    expect(cacheRepo()->get($result['client']))->not->toBeNull();
});

it('serves the key from Redis on subsequent requests without hitting the DB', function (): void {
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

it('stores the cache entry under the expected Redis key format', function (): void {
    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $expectedKey = 'keystone:key:'.$result['client'];
    expect(Cache::store('array')->has($expectedKey))->toBeTrue();
});
