<?php

declare(strict_types=1);

if (! class_exists(\Stancl\Tenancy\Tenancy::class)) { return; }

use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Services\KeystoneService;
use Schtzie\Keystone\Tenancy\KeystoneBootstrapper;
use Schtzie\Keystone\Tests\Fixtures\Tenant;
use Schtzie\Keystone\Tests\Fixtures\User;

// ── Setup ──────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    config(['keystone.tenancy.mode' => 'multi_db']);
});

afterEach(function (): void {
    tenancy()->end();
    config(['keystone.tenancy.mode' => 'none']);

    // Clean up tenant SQLite files
    $files = glob(database_path('tenant*.sqlite'));
    foreach ($files as $file) {
        @unlink($file);
    }
});

function setupMultiDbTenant(string $id): Tenant
{
    $tenant = Tenant::firstOrCreate(['id' => $id]);
    tenancy()->initialize($tenant);

    // Create the schema in the tenant database
    $schema = app('db')->connection('tenant')->getSchemaBuilder();

    if (! $schema->hasTable('users')) {
        $schema->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

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

    return $tenant;
}

// ── KeystoneBootstrapper ───────────────────────────────────────────────────

it('KeystoneBootstrapper::bootstrap flushes the resolved in-memory state', function (): void {
    $service = app(KeystoneService::class);

    $tenant = setupMultiDbTenant('tenant-a');
    $user = User::create(['name' => 'Multi A']);
    $result = $user->createKeystone('Key A');

    // Warm the in-memory state
    $service->findByKeystone($result['client']);

    // Simulate tenant switch via bootstrapper
    $bootstrapper = new KeystoneBootstrapper($service);
    $bootstrapper->bootstrap($tenant);

    // Verify resolved map is empty (service should re-query on next call)
    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});

it('KeystoneBootstrapper::revert flushes the resolved in-memory state', function (): void {
    $service = app(KeystoneService::class);
    $bootstrapper = new KeystoneBootstrapper($service);

    setupMultiDbTenant('tenant-a');
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
    setupMultiDbTenant('tenant-a');
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
    setupMultiDbTenant('tenant-a');
    $user = User::create(['name' => 'Multi A']);
    $result = $user->createKeystone('Key A');

    $repo = app(KeystoneKeyCacheRepository::class);
    $repo->put($result['model']);
    tenancy()->end();

    // Switch to tenant-b — should get a cache miss for the same client
    setupMultiDbTenant('tenant-b');
    expect($repo->get($result['client']))->toBeNull();
    tenancy()->end();
});

// ── Middleware ─────────────────────────────────────────────────────────────

it('middleware authenticates successfully under multi_db mode', function (): void {
    Route::middleware('api.key')->get('/multi-db-test', fn () => response()->json(['ok' => true]));

    setupMultiDbTenant('tenant-a');
    $user = User::create(['name' => 'Auth User']);
    $result = $user->createKeystone('Key A');

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/multi-db-test', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();

    tenancy()->end();
});

it('middleware rejects a key that belongs to a different tenant database', function (): void {
    Route::middleware('api.key')->get('/multi-db-leak-test', fn () => response()->json(['ok' => true]));

    // 1. Create a key in Tenant B
    setupMultiDbTenant('tenant-b');
    $user = User::create(['name' => 'Tenant B User']);
    $result = $user->createKeystone('Key B');

    // Warm the cache under Tenant B
    app(KeystoneKeyCacheRepository::class)->put($result['model']);

    // Delete it to ensure it only exists in Cache, testing pure cache isolation
    $result['model']->delete();

    tenancy()->end();

    // 2. Switch to Tenant A
    setupMultiDbTenant('tenant-a');

    // 3. Attempt to use Tenant B's key against Tenant A's application
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/multi-db-leak-test', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();

    tenancy()->end();
});

it('middleware degrades gracefully when multi_db mode is enabled but no tenant is initialized', function (): void {
    Route::middleware('api.key')->get('/multi-db-null-tenant', fn () => response()->json(['ok' => true]));

    // Do NOT initialize tenancy
    $user = User::create(['name' => 'Central User']);
    $result = $user->createKeystone('Central Key');

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/multi-db-null-tenant', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();
});

it('flushResolved is called and in-memory state is empty after tenant switch', function (): void {
    $service = app(KeystoneService::class);

    setupMultiDbTenant('tenant-a');
    $user = User::create(['name' => 'State Test']);
    $result = $user->createKeystone('Key A');
    $service->findByKeystone($result['client']); // populate resolved map
    tenancy()->end();

    // Simulate bootstrapper call
    $service->flushResolved();

    $reflection = new ReflectionProperty($service, 'resolved');
    $reflection->setAccessible(true);

    expect($reflection->getValue($service))->toBeEmpty();
});

it('individual keystone rate limit value takes priority over the global rate limit setting in multi_db mode', function (): void {
    Route::middleware('api.key')->get('/multi-db-rate-limit', fn () => response()->json(['ok' => true]));

    // Global limit is 1
    config(['keystone.rate_limit' => 1]);

    setupMultiDbTenant('tenant-a');
    $user = User::create(['name' => 'Rate Limit Tenant A']);

    // Individual limit is 5
    $result = $user->createKeystone('Key A', [], null, ['rate_limit' => 5]);

    // Explicitly warm the cache with the model
    $cache = app(KeystoneKeyCacheRepository::class);
    $cache->put($result['model']);

    // Flush the in-memory map to force the middleware to fetch from the cache
    app(KeystoneService::class)->flushResolved();

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);
    $headers = [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ];

    // Requests 1 to 5 will hit the CACHE and should pass, bypassing global limit of 1
    for ($i = 0; $i < 5; $i++) {
        $this->getJson('/multi-db-rate-limit', $headers)->assertOk();
        // Flush memory after each request so the next request also hits the cache
        app(KeystoneService::class)->flushResolved();
    }

    // Request 6 (also from cache) should be rate limited
    $this->getJson('/multi-db-rate-limit', $headers)->assertStatus(429);
});

it('keystone:warm command namespaces cache warming in multi_db mode', function (): void {
    setupMultiDbTenant('tenant-cmd');
    $user = User::create(['name' => 'Command User']);
    $result = $user->createKeystone('Cmd Key');

    // Clear cache first
    app(KeystoneKeyCacheRepository::class)->flush();

    // Warm the cache via command
    $this->artisan('keystone:warm')->assertExitCode(0);

    // Check that it exists in the tenant's cache namespace
    $cache = app('cache')->store('array');
    $tenantKey = 'keystone:tenant-cmd:key:'.$result['client'];

    expect($cache->has($tenantKey))->toBeTrue();

    // And it doesn't leak into the global namespace
    $globalKey = 'keystone:key:'.$result['client'];
    expect($cache->has($globalKey))->toBeFalse();

    tenancy()->end();
});

it('keystone:prune command namespaces cache eviction in multi_db mode', function (): void {
    setupMultiDbTenant('tenant-prune');
    $user = User::create(['name' => 'Prune User']);
    $result = $user->createKeystone('Prune Key');

    // Revoke the key 40 days ago
    $result['model']->update(['revoked_at' => now()->subDays(40)]);

    // Warm the cache manually
    $repo = app(KeystoneKeyCacheRepository::class);
    $repo->put($result['model']);

    // Verify it is in cache
    expect($repo->get($result['client']))->not->toBeNull();

    // Prune keys older than 30 days
    $this->artisan('keystone:prune', ['--days' => 30])->assertExitCode(0);

    // Verify the tenant's cache was successfully cleared for this key
    expect($repo->get($result['client']))->toBeNull();

    tenancy()->end();
});
