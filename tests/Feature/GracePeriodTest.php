<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

// ── Grace period (zero-downtime key rotation) ─────────────────────────────

it('a rotated key with grace period is still valid until grace_expires_at', function (): void {
    Route::middleware('api.key')->get('/test-grace', fn () => response()->json(['ok' => true]));

    config(['keystone.rotation_grace_seconds' => 300]); // 5-minute grace window

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');
    $oldKey = $result['model'];

    $user->rotateKeystone($oldKey);

    // Old key should be revoked but have a future grace_expires_at
    $oldKey->refresh();
    expect($oldKey->revoked_at)->not->toBeNull();
    expect($oldKey->grace_expires_at)->not->toBeNull();
    expect($oldKey->grace_expires_at->isFuture())->toBeTrue();

    // isValid() returns true because grace period is active
    expect($oldKey->isValid())->toBeTrue();

    // Middleware should still accept the old key during grace window
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-grace', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();
});

it('a revoked key without grace period is immediately invalid', function (): void {
    Route::middleware('api.key')->get('/test-no-grace', fn () => response()->json(['ok' => true]));

    config(['keystone.rotation_grace_seconds' => 0]);

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $user->rotateKeystone($result['model']);

    $result['model']->refresh();
    expect($result['model']->isValid())->toBeFalse();

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-no-grace', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

it('a key whose grace period has expired is rejected', function (): void {
    Route::middleware('api.key')->get('/test-expired-grace', fn () => response()->json(['ok' => true]));

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    // Manually set grace_expires_at to the past to simulate an expired grace window
    $result['model']->update([
        'revoked_at' => now()->subMinute(),
        'grace_expires_at' => now()->subSeconds(1),
    ]);

    $result['model']->refresh();
    expect($result['model']->isValid())->toBeFalse();

    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-expired-grace', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});
