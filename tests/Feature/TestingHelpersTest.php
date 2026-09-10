<?php

declare(strict_types=1);

use Schtzie\Keystone\Testing\InteractsWithKeystone;
use Schtzie\Keystone\Testing\KeystoneFactory;
use Schtzie\Keystone\Tests\Fixtures\User;

uses(InteractsWithKeystone::class);

// ── Keystone::fake() ───────────────────────────────────────────────────────

it('Keystone::fake() bypasses real auth and allows requests', function (): void {
    Route::middleware('api.key')->get('/test-fake', fn () => response()->json(['ok' => true]));

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $fake = \Keystone::fake($result['model']);

    $this->getJson('/test-fake', [
        'X-Client-Id'    => 'any-value',
        'X-API-Signature' => 'any-value',
    ])->assertOk();

    $fake->assertAuthenticated();
});

it('Keystone::fake(null) makes all auth attempts fail', function (): void {
    Route::middleware('api.key')->get('/test-fake-fail', fn () => response()->json(['ok' => true]));

    $fake = \Keystone::fake(null);

    $this->getJson('/test-fake-fail', [
        'X-Client-Id'    => 'any',
        'X-API-Signature' => 'any',
    ])->assertUnauthorized();

    $fake->assertAuthAttempted();
});

it('KeystoneFake::assertAuthenticatedTimes counts correctly', function (): void {
    Route::middleware('api.key')->get('/test-count', fn () => response()->json(['ok' => true]));

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $fake = \Keystone::fake($result['model']);

    $this->getJson('/test-count', ['X-Client-Id' => 'x', 'X-API-Signature' => 'x']);
    $this->getJson('/test-count', ['X-Client-Id' => 'x', 'X-API-Signature' => 'x']);

    $fake->assertAuthenticatedTimes(2);
});

it('KeystoneFake::assertNotAuthenticated passes when no requests were made', function (): void {
    $fake = \Keystone::fake();

    $fake->assertNotAuthenticated();
});

// ── actingWithKeystone() ───────────────────────────────────────────────────

it('actingWithKeystone() sets the correct auth headers and passes middleware', function (): void {
    Route::middleware('api.key')->get('/test-acting', fn () => response()->json(['ok' => true]));

    $user = User::create(['name' => 'Test']);

    $this->actingWithKeystone($user)->getJson('/test-acting')->assertOk();
});

it('actingWithKeystoneScopes() passes with matching scope', function (): void {
    Route::middleware('api.key:read')->get('/test-scoped', fn () => response()->json(['ok' => true]));

    $user = User::create(['name' => 'Test']);

    $this->actingWithKeystoneScopes($user, ['read'])->getJson('/test-scoped')->assertOk();
});

it('actingWithKeystoneScopes() fails when scope is missing', function (): void {
    Route::middleware('api.key:write')->get('/test-scoped-fail', fn () => response()->json(['ok' => true]));

    $user = User::create(['name' => 'Test']);

    $this->actingWithKeystoneScopes($user, ['read'])->getJson('/test-scoped-fail')->assertUnauthorized();
});

// ── KeystoneFactory ────────────────────────────────────────────────────────

it('KeystoneFactory creates an active key by default', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = KeystoneFactory::for($user)->create();

    expect($result['model']->isValid())->toBeTrue();
    expect($result['model']->revoked_at)->toBeNull();
});

it('KeystoneFactory::expired() creates an already-expired key', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = KeystoneFactory::for($user)->expired()->create();

    expect($result['model']->isValid())->toBeFalse();
    expect($result['model']->expires_at->isPast())->toBeTrue();
});

it('KeystoneFactory::revoked() creates an already-revoked key', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = KeystoneFactory::for($user)->revoked()->create();

    expect($result['model']->isValid())->toBeFalse();
    expect($result['model']->revoked_at)->not->toBeNull();
});

it('KeystoneFactory::withScopes() assigns scopes to the key', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = KeystoneFactory::for($user)->withScopes(['read', 'write'])->create();

    expect($result['model']->scopes)->toBe(['read', 'write']);
});

it('KeystoneFactory::withMetadata() assigns metadata', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = KeystoneFactory::for($user)->withMetadata(['env' => 'test'])->create();

    expect($result['model']->metadata)->toBe(['env' => 'test']); // @phpstan-ignore-line
});
