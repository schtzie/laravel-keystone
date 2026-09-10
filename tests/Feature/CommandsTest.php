<?php

declare(strict_types=1);

if (! class_exists(\Stancl\Tenancy\Tenancy::class)) { return; }

use Schtzie\Keystone\Testing\InteractsWithKeystone;
use Schtzie\Keystone\Tests\Fixtures\Tenant;
use Schtzie\Keystone\Tests\Fixtures\User;

uses(InteractsWithKeystone::class);

afterEach(function (): void {
    if (function_exists('tenancy') && app()->has(Stancl\Tenancy\Tenancy::class) && tenancy()->initialized) {
        tenancy()->end();
    }
    $files = glob(database_path('tenant*.sqlite'));
    foreach ($files as $file) {
        @unlink($file);
    }
    Schtzie\Keystone\Facades\Keystone::initializeTenantUsing(function () {});
});

function setupCommandTenant(string $id): Tenant
{
    config(['keystone.tenancy.mode' => 'multi_db']);
    $tenant = Tenant::firstOrCreate(['id' => $id]);

    Schtzie\Keystone\Facades\Keystone::initializeTenantUsing(function ($id) {
        $tenant = Tenant::find($id);
        if ($tenant) {
            tenancy()->initialize($tenant);
        }
    });

    tenancy()->initialize($tenant);
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
    tenancy()->end();

    return $tenant;
}

// ── Standard Artisan command tests ─────────────────────────────────────────

it('keystone:status runs without errors', function (): void {
    $this->artisan('keystone:status')->assertExitCode(0);
});

it('keystone:list shows an empty state when no keys exist', function (): void {
    $this->artisan('keystone:list')->assertExitCode(0);
});

it('keystone:list shows active keys in a table', function (): void {
    $user = User::create(['name' => 'Test']);
    $user->createKeystone('My Key');

    $this->artisan('keystone:list')->assertExitCode(0);
});

it('keystone:rotate-expiring respects the days parameter', function (): void {
    $this->artisan('keystone:rotate-expiring', ['--days' => 5])
        ->assertExitCode(0);
});

it('keystone:generate creates a new key for a valid owner', function (): void {
    $user = User::create(['name' => 'Test']);

    $this->artisan('keystone:generate', [
        'model' => User::class,
        'id' => $user->id,
        'name' => 'CI Bot',
        '--scope' => ['read'],
    ])->assertExitCode(0);

    expect($user->keystones()->count())->toBe(1);
});

it('keystone:generate fails for a non-existent model class', function (): void {
    $this->artisan('keystone:generate', [
        'model' => 'App\\Models\\NonExistent',
        'id' => 1,
        'name' => 'Fail',
    ])->assertExitCode(1);
});

it('keystone:generate fails for a non-existent owner ID', function (): void {
    $this->artisan('keystone:generate', [
        'model' => User::class,
        'id' => 9999,
        'name' => 'Fail',
    ])->assertExitCode(1);
});

it('keystone:revoke revokes the key with --force flag', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My Key');

    $this->artisan('keystone:revoke', [
        'client' => $result['client'],
        '--force' => true,
    ])->assertExitCode(0);

    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
});

it('keystone:revoke reports already-revoked keys gracefully', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My Key');
    $result['model']->revoke();

    $this->artisan('keystone:revoke', [
        'client' => $result['client'],
        '--force' => true,
    ])->assertExitCode(0);
});

it('keystone:warm runs without errors when cache is enabled', function (): void {
    $user = User::create(['name' => 'Test']);
    $user->createKeystone('My Key');

    $this->artisan('keystone:warm')->assertExitCode(0);
});

it('keystone:warm exits gracefully when cache is disabled', function (): void {
    config(['keystone.cache.enabled' => false]);

    $this->artisan('keystone:warm')->assertExitCode(0);
});

it('keystone:prune removes revoked keys older than the configured days', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('Old Key');
    $result['model']->update(['revoked_at' => now()->subDays(60)]);

    config(['keystone.prune_revoked_after_days' => 30]);

    $this->artisan('keystone:prune')->assertExitCode(0);

    $this->assertDatabaseMissing('keystoneables', ['id' => $result['model']->id]);
});

it('keystone:rotate-expiring with --dry-run does not rotate any keys', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('Expiring', [], now()->addDays(3)->toImmutable());

    $this->artisan('keystone:rotate-expiring', ['--days' => 7, '--dry-run' => true])->assertExitCode(0);

    expect($result['model']->fresh()->revoked_at)->toBeNull();
    expect($user->keystones()->count())->toBe(1);
});

it('keystone:rotate-expiring actually rotates keys when run without --dry-run', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('Expiring', [], now()->addDays(3)->toImmutable());

    $this->artisan('keystone:rotate-expiring', ['--days' => 7])->assertExitCode(0);

    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
    expect($user->keystones()->count())->toBe(2);
});

it('keystone:revoke exits with an error if the client key is not found', function (): void {
    $this->artisan('keystone:revoke', [
        'client' => 'ks_nonexistent',
        '--force' => true,
    ])->assertExitCode(1);
});

it('keystone:list exits with an error if both --active and --revoked are passed', function (): void {
    $this->artisan('keystone:list', [
        '--active' => true,
        '--revoked' => true,
    ])->assertExitCode(1);
});

it('keystone:generate exits with an error if the --expires date format is invalid', function (): void {
    $user = User::create(['name' => 'Test']);

    $this->artisan('keystone:generate', [
        'model' => User::class,
        'id' => $user->id,
        'name' => 'CI Bot',
        '--expires' => 'not-a-date',
    ])->assertExitCode(1);
});

it('keystone:generate exits with an error if the model does not use the HasKeystones trait', function (): void {
    $dummy = new class extends Illuminate\Database\Eloquent\Model
    {
        protected $table = 'users';
    };
    $class = get_class($dummy);

    User::create(['name' => 'Traitless']);

    $this->artisan('keystone:generate', [
        'model' => $class,
        'id' => 1,
        'name' => 'Fail',
    ])->assertExitCode(1);
});

it('keystone:status handles access log table missing exceptions gracefully', function (): void {
    config(['keystone.access_log.enabled' => true]);
    Illuminate\Support\Facades\Schema::dropIfExists('keystone_access_logs');

    $this->artisan('keystone:status')
        ->assertExitCode(0)
        ->expectsOutputToContain('table not found');
});

// ── Exhaustive Tenancy Command Testing ─────────────────────────────────────

it('keystone:generate supports the --tenant option and creates key on tenant DB', function (): void {
    $tenant = setupCommandTenant('tenant-gen');

    tenancy()->initialize($tenant);
    $user = User::create(['name' => 'Gen User']);
    tenancy()->end();

    $this->artisan('keystone:generate', [
        'model' => User::class,
        'id' => $user->id,
        'name' => 'Tenant Key',
        '--tenant' => 'tenant-gen',
    ])->assertExitCode(0);

    tenancy()->initialize($tenant);
    expect($user->keystones()->count())->toBe(1);
    tenancy()->end();
});

it('keystone:list supports the --tenant option', function (): void {
    $tenant = setupCommandTenant('tenant-list');

    tenancy()->initialize($tenant);
    $user = User::create(['name' => 'List User']);
    $user->createKeystone('List Key');
    tenancy()->end();

    $this->artisan('keystone:list', ['--tenant' => 'tenant-list'])->assertExitCode(0);
});

it('keystone:revoke supports the --tenant option', function (): void {
    $tenant = setupCommandTenant('tenant-revoke');

    tenancy()->initialize($tenant);
    $user = User::create(['name' => 'Revoke User']);
    $result = $user->createKeystone('Revoke Key');
    tenancy()->end();

    $this->artisan('keystone:revoke', [
        'client' => $result['client'],
        '--force' => true,
        '--tenant' => 'tenant-revoke',
    ])->assertExitCode(0);

    tenancy()->initialize($tenant);
    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
    tenancy()->end();
});

it('keystone:prune supports the --tenant option', function (): void {
    $tenant = setupCommandTenant('tenant-prune');

    tenancy()->initialize($tenant);
    $user = User::create(['name' => 'Prune User']);
    $result = $user->createKeystone('Old Key');
    $result['model']->update(['revoked_at' => now()->subDays(60)]);
    tenancy()->end();

    config(['keystone.prune_revoked_after_days' => 30]);

    $this->artisan('keystone:prune', ['--tenant' => 'tenant-prune'])->assertExitCode(0);

    tenancy()->initialize($tenant);
    expect(Schtzie\Keystone\Models\Keystone::count())->toBe(0);
    tenancy()->end();
});

it('keystone:rotate-expiring supports the --tenant option', function (): void {
    $tenant = setupCommandTenant('tenant-rotate');

    tenancy()->initialize($tenant);
    $user = User::create(['name' => 'Rotate User']);
    $result = $user->createKeystone('Expiring Key', [], now()->addDays(3)->toImmutable());
    tenancy()->end();

    $this->artisan('keystone:rotate-expiring', [
        '--days' => 7,
        '--tenant' => 'tenant-rotate',
    ])->assertExitCode(0);

    tenancy()->initialize($tenant);
    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
    expect($user->keystones()->count())->toBe(2);
    tenancy()->end();
});

it('keystone:warm supports the --tenant option', function (): void {
    $tenant = setupCommandTenant('tenant-warm');

    tenancy()->initialize($tenant);
    $user = User::create(['name' => 'Warm User']);
    $result = $user->createKeystone('Warm Key');
    tenancy()->end();

    $this->artisan('keystone:warm', ['--tenant' => 'tenant-warm'])->assertExitCode(0);

    // Verify it warmed in the tenant's cache namespace
    $cache = app('cache')->store('array');
    $tenantKey = 'keystone:tenant-warm:key:'.$result['client'];

    expect($cache->has($tenantKey))->toBeTrue();
});

it('keystone:status supports the --tenant option', function (): void {
    $tenant = setupCommandTenant('tenant-status');

    $this->artisan('keystone:status', ['--tenant' => 'tenant-status'])->assertExitCode(0);
});
