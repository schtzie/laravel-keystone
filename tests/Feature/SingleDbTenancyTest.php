<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Tests\Fixtures\User;
use Schatzie\Keystone\Tests\Support\FakeTenant;

// ── Setup ──────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    config([
        'keystone.tenancy.mode'             => 'single_db',
        'keystone.tenancy.tenant_id_column' => 'tenant_id',
    ]);

    // Add tenant_id column to the existing api_keys table if not present
    if (Schema::hasTable('api_keys') && ! Schema::hasColumn('api_keys', 'tenant_id')) {
        Schema::table('api_keys', function ($table): void {
            $table->string('tenant_id')->nullable()->index('api_keys_tenant_id_index')->after('id');
        });
    }
});

afterEach(function (): void {
    FakeTenant::clear();
    config(['keystone.tenancy.mode' => 'none']);

    // SQLite cannot DROP COLUMN when an index references it.
    // Drop all indexes that reference tenant_id first, then drop the column.
    if (Schema::hasColumn('api_keys', 'tenant_id')) {
        DB::statement('DROP INDEX IF EXISTS api_keys_tenant_id_index');
        DB::statement('DROP INDEX IF EXISTS api_keys_tenant_key_index');

        Schema::table('api_keys', function ($table): void {
            $table->dropColumn('tenant_id');
        });
    }
});

// ── Tenant Isolation ───────────────────────────────────────────────────────

it('stamps tenant_id on the api_key record when creating', function (): void {
    FakeTenant::set('tenant-a');

    $user   = User::create(['name' => 'Tenant A User']);
    $result = $user->createApiKey('Tenant A Key');

    $this->assertDatabaseHas('api_keys', [
        'api_key'   => $result['api_key'],
        'tenant_id' => 'tenant-a',
    ]);
});

it('does not return keys from another tenant', function (): void {
    FakeTenant::set('tenant-a');
    $userA = User::create(['name' => 'Tenant A']);
    $userA->createApiKey('Key A');
    FakeTenant::clear();

    FakeTenant::set('tenant-b');

    // Tenant B's scope: userA's key should be invisible
    expect($userA->apiKeys()->count())->toBe(0);
});

it('namespaces Redis cache keys by tenant_id in single_db mode', function (): void {
    FakeTenant::set('tenant-a');
    $user   = User::create(['name' => 'Cache Tenant A']);
    $result = $user->createApiKey('Key A');

    $repo = app(ApiKeyCacheRepository::class);
    $repo->put($result['model']);

    // Cache key must include the tenant segment
    $cache     = app('cache')->store('array');
    $tenantKey = 'keystone:tenant-a:key:' . $result['api_key'];
    expect($cache->has($tenantKey))->toBeTrue();
});

it('middleware rejects a key that belongs to a different tenant', function (): void {
    Route::middleware('api.key')->get('/single-db-test', fn () => response()->json(['ok' => true]));

    // Create key under tenant-a
    FakeTenant::set('tenant-a');
    $user   = User::create(['name' => 'Tenant A']);
    $result = $user->createApiKey('Key A');
    FakeTenant::clear();

    // Attempt auth as tenant-b — key is invisible under tenant-b's scope
    FakeTenant::set('tenant-b');

    $sig = hash_hmac('sha256', $result['api_key'], $result['secret_key']);

    $this->getJson('/single-db-test', [
        'X-API-Key'       => $result['api_key'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

it('revokeAllApiKeys only affects the current tenant keys', function (): void {
    // Create key under tenant-a
    FakeTenant::set('tenant-a');
    $userA = User::create(['name' => 'Tenant A']);
    $userA->createApiKey('A Key');
    FakeTenant::clear();

    // Create key under tenant-b and revoke all
    FakeTenant::set('tenant-b');
    $userB = User::create(['name' => 'Tenant B']);
    $userB->createApiKey('B Key');
    $userB->revokeAllApiKeys();
    FakeTenant::clear();

    // Tenant-a key must still be active
    FakeTenant::set('tenant-a');
    expect($userA->apiKeys()->whereNull('revoked_at')->count())->toBe(1);
});
