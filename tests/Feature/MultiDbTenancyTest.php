<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Services\ApiKeyService;
use Schatzie\Keystone\Tenancy\KeystoneBootstrapper;
use Schatzie\Keystone\Tests\Fixtures\User;
use Schatzie\Keystone\Tests\Support\FakeTenant;

// ── Setup ──────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    // Multi-DB mode: no tenant_id column needed (each tenant has their own DB).
    // In tests we simulate this by switching the FakeTenant context.
    config(['keystone.tenancy.mode' => 'multi_db']);
});

afterEach(function (): void {
    config(['keystone.tenancy.mode' => 'none']);
    FakeTenant::clear();
});

// ── KeystoneBootstrapper ───────────────────────────────────────────────────

it('KeystoneBootstrapper::bootstrap flushes the resolved in-memory state', function (): void {
    $service = app(ApiKeyService::class);

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Multi A']);
    $result = $user->createApiKey('Key A');

    // Warm the in-memory state
    $service->findByApiKey($result['api_key']);

    // Simulate tenant switch via bootstrapper
    $bootstrapper = new KeystoneBootstrapper($service);
    $bootstrapper->bootstrap(FakeTenant::current());

    // Verify resolved map is empty (service should re-query on next call)
    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});

it('KeystoneBootstrapper::revert flushes the resolved in-memory state', function (): void {
    $service = app(ApiKeyService::class);
    $bootstrapper = new KeystoneBootstrapper($service);

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Revert Test']);
    $result = $user->createApiKey('Key A');
    $service->findByApiKey($result['api_key']);

    $bootstrapper->revert();

    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});

// ── Tenant-Scoped Redis Namespace ──────────────────────────────────────────

it('Redis keys are namespaced by tenant ID in multi_db mode', function (): void {
    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Multi DB User']);
    $result = $user->createApiKey('Key A');

    $repo = app(ApiKeyCacheRepository::class);
    $repo->put($result['model']);

    $cache = app('cache')->store('array');
    $tenantKey = 'keystone:tenant-a:key:'.$result['api_key'];

    expect($cache->has($tenantKey))->toBeTrue();
});

it('does not serve a cached key from the wrong tenant namespace', function (): void {
    // Warm cache under tenant-a
    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Multi A']);
    $result = $user->createApiKey('Key A');

    $repo = app(ApiKeyCacheRepository::class);
    $repo->put($result['model']);
    FakeTenant::clear();

    // Switch to tenant-b — should get a cache miss for the same api_key
    FakeTenant::set('tenant-b');
    expect($repo->get($result['api_key']))->toBeNull();
    FakeTenant::clear();
});

// ── Middleware ─────────────────────────────────────────────────────────────

it('middleware authenticates successfully under multi_db mode', function (): void {
    Route::middleware('api.key')->get('/multi-db-test', fn () => response()->json(['ok' => true]));

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Auth User']);
    $result = $user->createApiKey('Key A');

    $sig = hash_hmac('sha256', $result['api_key'], $result['secret_key']);

    $this->getJson('/multi-db-test', [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => $sig,
    ])->assertOk();

    FakeTenant::clear();
});

it('flushResolved is called and in-memory state is empty after tenant switch', function (): void {
    $service = app(ApiKeyService::class);

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'State Test']);
    $result = $user->createApiKey('Key A');
    $service->findByApiKey($result['api_key']); // populate resolved map
    FakeTenant::clear();

    // Simulate bootstrapper call (as tenancy v4 would)
    $service->flushResolved();

    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});
