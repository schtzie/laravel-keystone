<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schatzie\Keystone\Tests\Fixtures\User;

// ── Helpers ────────────────────────────────────────────────────────────────

function makeUser(): User
{
    return User::create(['name' => 'Test User']);
}

function makeSignature(string $client, string $secret): string
{
    return hash_hmac('sha256', $client, $secret);
}

function keystoneRoute(string ...$scopes): void
{
    $middleware = $scopes === [] ? 'api.key' : 'api.key:'.implode(',', $scopes);

    Route::middleware($middleware)->get('/test-keystone', function () {
        $owner = request()->attributes->get('keystoneable');

        return response()->json(['owner_id' => $owner?->getKey()]);
    });
}

// ── Key Generation & Storage ───────────────────────────────────────────────

it('createKeystone returns plain client, secret, and the model', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    expect($result)->toHaveKeys(['client', 'secret', 'model'])
        ->and($result['client'])->toStartWith('ks_')
        ->and($result['secret'])->not->toBeEmpty()
        ->and($result['model'])->toBeInstanceOf(Schatzie\Keystone\Models\Keystone::class);
});

it('stores the plain client in the database', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $this->assertDatabaseHas('keystoneables', ['client' => $result['client']]);
});

it('stores the plain secret in the database', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $this->assertDatabaseHas('keystoneables', ['secret' => $result['secret']]);
});

it('creates multiple keys for the same owner', function (): void {
    $user = makeUser();
    $user->createKeystone('Key 1');
    $user->createKeystone('Key 2');

    expect($user->keystones()->count())->toBe(2);
});

// ── HMAC Signature Verification ────────────────────────────────────────────

it('verifySignature passes for the correct HMAC', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $sig = makeSignature($result['client'], $result['secret']);

    expect($result['model']->verifySignature($sig))->toBeTrue();
});

it('verifySignature fails for a tampered signature', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    expect($result['model']->verifySignature('tampered-signature'))->toBeFalse();
});

it('verifySignature fails when signed with the wrong secret', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $sig = makeSignature($result['client'], 'wrong-secret');

    expect($result['model']->verifySignature($sig))->toBeFalse();
});

// ── Middleware — Happy Path ────────────────────────────────────────────────

it('middleware allows a valid client with correct HMAC signature', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $sig = makeSignature($result['client'], $result['secret']);

    $response = $this->getJson('/test-keystone', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ]);

    $response->assertOk()->assertJson(['owner_id' => $user->getKey()]);
});

// ── Middleware — Rejection Cases ───────────────────────────────────────────

it('middleware returns 401 when client header is missing', function (): void {
    keystoneRoute();

    $this->getJson('/test-keystone')->assertUnauthorized();
});

it('middleware returns 401 when signature header is missing', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $this->getJson('/test-keystone', ['X-Client-Id' => $result['client']])
        ->assertUnauthorized();
});

it('middleware returns 401 for a wrong HMAC signature', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $this->getJson('/test-keystone', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => 'bad-signature',
    ])->assertUnauthorized();
});

it('middleware returns 401 for an unknown client', function (): void {
    keystoneRoute();

    $this->getJson('/test-keystone', [
        'X-Client-Id' => 'ks_unknown',
        'X-API-Signature' => 'irrelevant',
    ])->assertUnauthorized();
});

it('middleware returns 401 for an expired key', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createKeystone('My App', [], now()->subDay()->toImmutable());

    $sig = makeSignature($result['client'], $result['secret']);

    $this->getJson('/test-keystone', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

it('middleware returns 401 for a revoked key', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createKeystone('My App');
    $result['model']->revoke();

    $sig = makeSignature($result['client'], $result['secret']);

    $this->getJson('/test-keystone', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

// ── Middleware — Scope Enforcement ─────────────────────────────────────────

it('middleware allows a request when the key has the required scope', function (): void {
    keystoneRoute('read');
    $user = makeUser();
    $result = $user->createKeystone('My App', ['read', 'write']);

    $sig = makeSignature($result['client'], $result['secret']);

    $this->getJson('/test-keystone', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();
});

it('middleware returns 401 when the key is missing a required scope', function (): void {
    keystoneRoute('write');
    $user = makeUser();
    $result = $user->createKeystone('My App', ['read']); // no 'write'

    $sig = makeSignature($result['client'], $result['secret']);

    $this->getJson('/test-keystone', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

// ── Owner Binding ──────────────────────────────────────────────────────────

it('middleware binds the keystoneable owner on request attributes', function (): void {
    Route::middleware('api.key')->get('/test-owner', function () {
        $owner = request()->attributes->get('keystoneable');

        return response()->json([
            'class' => get_class($owner),
            'id' => $owner->getKey(),
        ]);
    });

    $user = makeUser();
    $result = $user->createKeystone('My App');
    $sig = makeSignature($result['client'], $result['secret']);

    $this->getJson('/test-owner', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk()->assertJson([
        'class' => User::class,
        'id' => $user->getKey(),
    ]);
});

// ── Key Lifecycle ──────────────────────────────────────────────────────────

it('revokeKeystone marks the key as revoked', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $user->revokeKeystone($result['model']);

    $this->assertDatabaseHas('keystoneables', [
        'id' => $result['model']->id,
        'revoked_at' => now()->toDateTimeString(),
    ]);
});

it('revokeAllKeystones revokes every active key', function (): void {
    $user = makeUser();
    $user->createKeystone('Key 1');
    $user->createKeystone('Key 2');

    $count = $user->revokeAllKeystones();

    expect($count)->toBe(2);
    expect($user->keystones()->whereNull('revoked_at')->count())->toBe(0);
});

it('rotateKeystone revokes the old key and returns a new one', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $rotated = $user->rotateKeystone($result['model']);

    expect($rotated['client'])->not->toBe($result['client']);
    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
    expect($rotated['model']->revoked_at)->toBeNull();
});
