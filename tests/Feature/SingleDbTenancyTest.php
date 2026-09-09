<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Tests\Fixtures\User;
use Schtzie\Keystone\Tests\Support\FakeTenant;

// ── Setup ──────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    config([
        'keystone.tenancy.mode' => 'single_db',
        'keystone.tenancy.tenant_id_column' => 'tenant_id',
    ]);

    // Add tenant_id column to the existing keystoneables table if not present
    if (Schema::hasTable('keystoneables') && ! Schema::hasColumn('keystoneables', 'tenant_id')) {
        Schema::table('keystoneables', function ($table): void {
            $table->string('tenant_id')->nullable()->index('keystoneables_tenant_id_index')->after('id');
        });
    }
});

afterEach(function (): void {
    FakeTenant::clear();
    config(['keystone.tenancy.mode' => 'none']);

    // SQLite cannot DROP COLUMN when an index references it.
    // Drop all indexes that reference tenant_id first, then drop the column.
    if (Schema::hasColumn('keystoneables', 'tenant_id')) {
        DB::statement('DROP INDEX IF EXISTS keystoneables_tenant_id_index');
        DB::statement('DROP INDEX IF EXISTS keystoneables_tenant_key_index');

        Schema::table('keystoneables', function ($table): void {
            $table->dropColumn('tenant_id');
        });
    }
});

// ── Tenant Isolation ───────────────────────────────────────────────────────

it('stamps tenant_id on the client record when creating', function (): void {
    FakeTenant::set('tenant-a');

    $user = User::create(['name' => 'Tenant A User']);
    $result = $user->createKeystone('Tenant A Key');

    $this->assertDatabaseHas('keystoneables', [
        'client' => $result['client'],
        'tenant_id' => 'tenant-a',
    ]);
});

it('does not return keys from another tenant', function (): void {
    FakeTenant::set('tenant-a');
    $userA = User::create(['name' => 'Tenant A']);
    $userA->createKeystone('Key A');
    FakeTenant::clear();

    FakeTenant::set('tenant-b');

    // Tenant B's scope: userA's key should be invisible
    expect($userA->keystones()->count())->toBe(0);
});

it('namespaces cache keys by tenant_id in single_db mode', function (): void {
    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Cache Tenant A']);
    $result = $user->createKeystone('Key A');

    $repo = app(KeystoneKeyCacheRepository::class);
    $repo->put($result['model']);

    // Cache key must include the tenant segment
    $cache = app('cache')->store('array');
    $tenantKey = 'keystone:tenant-a:key:'.$result['client'];
    expect($cache->has($tenantKey))->toBeTrue();
});

it('middleware rejects a key that belongs to a different tenant', function (): void {
    Route::middleware('api.key')->get('/single-db-test', fn () => response()->json(['ok' => true]));

    // Create key under tenant-a
    FakeTenant::set('tenant-a');
    $user = User::create(['name' => 'Tenant A']);
    $result = $user->createKeystone('Key A');
    FakeTenant::clear();

    // Attempt auth as tenant-b — key is invisible under tenant-b's scope
    FakeTenant::set('tenant-b');

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/single-db-test', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

it('revokeAllKeystones only affects the current tenant keys', function (): void {
    // Create key under tenant-a
    FakeTenant::set('tenant-a');
    $userA = User::create(['name' => 'Tenant A']);
    $userA->createKeystone('A Key');
    FakeTenant::clear();

    // Create key under tenant-b and revoke all
    FakeTenant::set('tenant-b');
    $userB = User::create(['name' => 'Tenant B']);
    $userB->createKeystone('B Key');
    $userB->revokeAllKeystones();
    FakeTenant::clear();

    // Tenant-a key must still be active
    FakeTenant::set('tenant-a');
    expect($userA->keystones()->whereNull('revoked_at')->count())->toBe(1);
});

it('individual keystone rate limit value takes priority over the global rate limit setting in single_db mode', function (): void {
    Route::middleware('api.key')->get('/single-db-rate-limit', fn () => response()->json(['ok' => true]));

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
        $this->getJson('/single-db-rate-limit', $headers)->assertOk();
        // Flush memory after each request so the next request also hits the cache
        app(\Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();
    }
    
    // Request 6 (also from cache) should be rate limited
    $this->getJson('/single-db-rate-limit', $headers)->assertStatus(429);
});
