<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

// ── API key UX features: metadata, description, max_keys_per_owner ─────────

it('stores metadata on a created key', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App', [], null, [
        'metadata' => ['env' => 'prod', 'team' => 'backend'],
    ]);

    expect($result['model']->metadata)->toBe(['env' => 'prod', 'team' => 'backend']);
    $this->assertDatabaseHas('keystoneables', ['id' => $result['model']->id]);
});

it('stores description on a created key', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App', [], null, [
        'description' => 'Used by the nightly CI pipeline',
    ]);

    expect($result['model']->description)->toBe('Used by the nightly CI pipeline'); // @phpstan-ignore-line
});

it('enforces max_keys_per_owner when configured', function (): void {
    config(['keystone.max_keys_per_owner' => 2]);

    $user = User::create(['name' => 'Test']);
    $user->createKeystone('Key 1');
    $user->createKeystone('Key 2');

    expect(fn () => $user->createKeystone('Key 3'))->toThrow(RuntimeException::class);
});

it('does not enforce max_keys_per_owner when set to null', function (): void {
    config(['keystone.max_keys_per_owner' => null]);

    $user = User::create(['name' => 'Test']);

    for ($i = 1; $i <= 5; $i++) {
        $user->createKeystone("Key {$i}");
    }

    expect($user->keystones()->count())->toBe(5);
});

it('rotation perfectly inherits all options and metadata from the old key', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App', [], null, [
        'metadata' => ['env' => 'staging'],
        'description' => 'Test Description',
        'rate_limit' => 100,
        'ip_allowlist' => ['127.0.0.1'],
        'ip_blocklist' => ['192.168.1.1'],
    ]);

    $rotated = $user->rotateKeystone($result['model']);
    $newKey = $rotated['model'];

    expect($newKey->metadata)->toBe(['env' => 'staging']); // @phpstan-ignore-line
    expect($newKey->description)->toBe('Test Description'); // @phpstan-ignore-line
    expect($newKey->rate_limit)->toBe(100);
    expect($newKey->ip_allowlist)->toBe(['127.0.0.1']);
    expect($newKey->ip_blocklist)->toBe(['192.168.1.1']);
});

it('scopeActive excludes grace-period keys', function (): void {
    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $result['model']->update([
        'revoked_at' => now(),
        'grace_expires_at' => now()->addMinutes(5),
    ]);

    // Active scope filters out revoked keys even if in grace period
    expect($user->keystones()->active()->count())->toBe(0);
});
