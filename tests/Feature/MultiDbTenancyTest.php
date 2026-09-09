<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Services\KeystoneService;
use Schtzie\Keystone\Tenancy\KeystoneBootstrapper;
use Schtzie\Keystone\Tests\Fixtures\User;
use Schtzie\Keystone\Tests\Support\FakeTenant;

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
    $service = app(KeystoneService::class);

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Multi A']);
    $result = $user->createKeystone('Key A');

    // Warm the in-memory state
    $service->findByKeystone($result['client']);

    // Simulate tenant switch via bootstrapper
    $bootstrapper = new KeystoneBootstrapper($service);
    $bootstrapper->bootstrap(FakeTenant::current());

    // Verify resolved map is empty (service should re-query on next call)
    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});

it('KeystoneBootstrapper::revert flushes the resolved in-memory state', function (): void {
    $service = app(KeystoneService::class);
    $bootstrapper = new KeystoneBootstrapper($service);

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Revert Test']);
    $result = $user->createKeystone('Key A');
    $service->findByKeystone($result['client']);

    $bootstrapper->revert();

    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});

// ── Tenant-Scoped Cache Namespace ──────────────────────────────────────────

it('cache keys are namespaced by tenant ID in multi_db mode', function (): void {
    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Multi DB User']);
    $result = $user->createKeystone('Key A');

    $repo = app(KeystoneKeyCacheRepository::class);
    $repo->put($result['model']);

    $cache = app('cache')->store('array');
    $tenantKey = 'keystone:tenant-a:key:'.$result['client'];

    expect($cache->has($tenantKey))->toBeTrue();
});

it('does not serve a cached key from the wrong tenant namespace', function (): void {
    // Warm cache under tenant-a
    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Multi A']);
    $result = $user->createKeystone('Key A');

    $repo = app(KeystoneKeyCacheRepository::class);
    $repo->put($result['model']);
    FakeTenant::clear();

    // Switch to tenant-b — should get a cache miss for the same client
    FakeTenant::set('tenant-b');
    expect($repo->get($result['client']))->toBeNull();
    FakeTenant::clear();
});

// ── Middleware ─────────────────────────────────────────────────────────────

it('middleware authenticates successfully under multi_db mode', function (): void {
    Route::middleware('api.key')->get('/multi-db-test', fn () => response()->json(['ok' => true]));

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Auth User']);
    $result = $user->createKeystone('Key A');

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/multi-db-test', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();

    FakeTenant::clear();
});

it('flushResolved is called and in-memory state is empty after tenant switch', function (): void {
    $service = app(KeystoneService::class);

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'State Test']);
    $result = $user->createKeystone('Key A');
    $service->findByKeystone($result['client']); // populate resolved map
    FakeTenant::clear();

    // Simulate bootstrapper call (as tenancy v4 would)
    $service->flushResolved();

    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});

it('individual keystone rate limit value takes priority over the global rate limit setting in multi_db mode', function (): void {
    Route::middleware('api.key')->get('/multi-db-rate-limit', fn () => response()->json(['ok' => true]));

    // Global limit is 1
    config(['keystone.rate_limit' => 1]);

    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Rate Limit Tenant A']);
    
    // Individual limit is 5
    $result = $user->createKeystone('Key A', [], null, ['rate_limit' => 5]);
    
    // Explicitly warm the cache with the model
    $cache = app(\Schtzie\Keystone\Cache\KeystoneKeyCacheRepository::class);
    $cache->put($result['model']);
    
    // Flush the in-memory map to force the middleware to fetch from the cache
    app(\Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);
    $headers = [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ];

    // Requests 1 to 5 will hit the CACHE and should pass, bypassing global limit of 1
    for ($i = 0; $i < 5; $i++) {
        $this->getJson('/multi-db-rate-limit', $headers)->assertOk();
        // Flush memory after each request so the next request also hits the cache
        app(\Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();
    }
    
    // Request 6 (also from cache) should be rate limited
    $this->getJson('/multi-db-rate-limit', $headers)->assertStatus(429);
});
