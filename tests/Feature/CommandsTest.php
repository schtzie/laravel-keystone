<?php

declare(strict_types=1);

use Schtzie\Keystone\Testing\InteractsWithKeystone;
use Schtzie\Keystone\Tests\Fixtures\User;

uses(InteractsWithKeystone::class);

// ── Artisan command tests ──────────────────────────────────────────────────

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

it('keystone:generate creates a new key for a valid owner', function (): void {
    $user = User::create(['name' => 'Test']);

    $this->artisan('keystone:generate', [
        'model' => User::class,
        'id'    => $user->id,
        'name'  => 'CI Bot',
        '--scope' => ['read'],
    ])->assertExitCode(0);

    expect($user->keystones()->count())->toBe(1);
});

it('keystone:generate fails for a non-existent model class', function (): void {
    $this->artisan('keystone:generate', [
        'model' => 'App\\Models\\NonExistent',
        'id'    => 1,
        'name'  => 'Fail',
    ])->assertExitCode(1);
});

it('keystone:generate fails for a non-existent owner ID', function (): void {
    $this->artisan('keystone:generate', [
        'model' => User::class,
        'id'    => 9999,
        'name'  => 'Fail',
    ])->assertExitCode(1);
});

it('keystone:revoke revokes the key with --force flag', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My Key');

    $this->artisan('keystone:revoke', [
        'client'  => $result['client'],
        '--force' => true,
    ])->assertExitCode(0);

    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
});

it('keystone:revoke reports already-revoked keys gracefully', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My Key');
    $result['model']->revoke();

    $this->artisan('keystone:revoke', [
        'client'  => $result['client'],
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
    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('Old Key');
    $result['model']->update(['revoked_at' => now()->subDays(60)]);

    config(['keystone.prune_revoked_after_days' => 30]);

    $this->artisan('keystone:prune')->assertExitCode(0);

    $this->assertDatabaseMissing('keystoneables', ['id' => $result['model']->id]);
});

it('keystone:rotate-expiring with --dry-run does not rotate any keys', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('Expiring', [], now()->addDays(3)->toImmutable());
    $oldId  = $result['model']->id;

    $this->artisan('keystone:rotate-expiring', ['--days' => 7, '--dry-run' => true])->assertExitCode(0);

    expect($result['model']->fresh()->revoked_at)->toBeNull();
    expect($user->keystones()->count())->toBe(1);
});
