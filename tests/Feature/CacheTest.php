<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Tests\Fixtures\User;

// ── Helpers ────────────────────────────────────────────────────────────────

function cacheRepo(): ApiKeyCacheRepository
{
    return app(ApiKeyCacheRepository::class);
}

function makeUserWithKey(array $scopes = []): array
{
    $user = User::create(['name' => 'Cache Test User']);
    $result = $user->createApiKey('Cache Test', $scopes);

    return [$user, $result];
}

function signedHeaders(array $result): array
{
    return [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => hash_hmac('sha256', $result['api_key'], $result['secret_key']),
    ];
}

// ── Write-Through on Cache Miss ────────────────────────────────────────────

it('populates Redis after first DB lookup (cache miss)', function (): void {
    [$user, $result] = makeUserWithKey();

    // Nothing in cache yet
    expect(cacheRepo()->get($result['api_key']))->toBeNull();

    Route::middleware('api.key')->get('/cache-test', fn () => response()->json(['ok' => true]));

    $this->getJson('/cache-test', signedHeaders($result))->assertOk();

    // Cache should now be warm
    expect(cacheRepo()->get($result['api_key']))->not->toBeNull();
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
    expect(cacheRepo()->get($result['api_key']))->not->toBeNull();

    $result['model']->revoke();

    // ApiKey::updated event fires → cache invalidation
    expect(cacheRepo()->get($result['api_key']))->toBeNull();
});

it('evicts all owner cache entries when revokeAllApiKeys is called', function (): void {
    $user = User::create(['name' => 'Bulk Revoke User']);
    $result1 = $user->createApiKey('Key 1');
    $result2 = $user->createApiKey('Key 2');

    cacheRepo()->put($result1['model']);
    cacheRepo()->put($result2['model']);

    $user->revokeAllApiKeys();

    expect(cacheRepo()->get($result1['api_key']))->toBeNull();
    expect(cacheRepo()->get($result2['api_key']))->toBeNull();
});

// ── Rotate Invalidation ────────────────────────────────────────────────────

it('evicts the old key from cache when rotated', function (): void {
    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $rotated = $user->rotateApiKey($result['model']);

    // Old key should be gone from cache
    expect(cacheRepo()->get($result['api_key']))->toBeNull();

    // New key is NOT pre-cached until first auth request
    expect(cacheRepo()->get($rotated['api_key']))->toBeNull();
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
    expect(cacheRepo()->get($result['api_key']))->toBeNull();
})->after(function (): void {
    config(['keystone.cache.enabled' => true]);
});

// ── Cache Namespace ────────────────────────────────────────────────────────

it('stores the cache entry under the expected Redis key format', function (): void {
    [$user, $result] = makeUserWithKey();

    cacheRepo()->put($result['model']);

    $expectedKey = 'keystone:key:'.$result['api_key'];
    expect(Cache::store('array')->has($expectedKey))->toBeTrue();
});
