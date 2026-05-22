<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schatzie\Keystone\Tests\Fixtures\User;

// ── Helpers ────────────────────────────────────────────────────────────────

function makeUser(): User
{
    return User::create(['name' => 'Test User']);
}

function makeSignature(string $apiKey, string $secretKey): string
{
    return hash_hmac('sha256', $apiKey, $secretKey);
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

it('createApiKey returns plain api_key, secret_key, and the model', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    expect($result)->toHaveKeys(['api_key', 'secret_key', 'model'])
        ->and($result['api_key'])->toStartWith('ks_')
        ->and($result['secret_key'])->not->toBeEmpty()
        ->and($result['model'])->toBeInstanceOf(Schatzie\Keystone\Models\ApiKey::class);
});

it('stores the plain api_key in the database', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $this->assertDatabaseHas('api_keys', ['api_key' => $result['api_key']]);
});

it('stores the plain secret_key in the database', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $this->assertDatabaseHas('api_keys', ['secret_key' => $result['secret_key']]);
});

it('creates multiple keys for the same owner', function (): void {
    $user = makeUser();
    $user->createApiKey('Key 1');
    $user->createApiKey('Key 2');

    expect($user->apiKeys()->count())->toBe(2);
});

// ── HMAC Signature Verification ────────────────────────────────────────────

it('verifySignature passes for the correct HMAC', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $sig = makeSignature($result['api_key'], $result['secret_key']);

    expect($result['model']->verifySignature($sig))->toBeTrue();
});

it('verifySignature fails for a tampered signature', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    expect($result['model']->verifySignature('tampered-signature'))->toBeFalse();
});

it('verifySignature fails when signed with the wrong secret', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $sig = makeSignature($result['api_key'], 'wrong-secret');

    expect($result['model']->verifySignature($sig))->toBeFalse();
});

// ── Middleware — Happy Path ────────────────────────────────────────────────

it('middleware allows a valid api_key with correct HMAC signature', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $sig = makeSignature($result['api_key'], $result['secret_key']);

    $response = $this->getJson('/test-keystone', [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => $sig,
    ]);

    $response->assertOk()->assertJson(['owner_id' => $user->getKey()]);
});

// ── Middleware — Rejection Cases ───────────────────────────────────────────

it('middleware returns 401 when api_key header is missing', function (): void {
    keystoneRoute();

    $this->getJson('/test-keystone')->assertUnauthorized();
});

it('middleware returns 401 when signature header is missing', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $this->getJson('/test-keystone', ['X-API-Key' => $result['api_key']])
        ->assertUnauthorized();
});

it('middleware returns 401 for a wrong HMAC signature', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $this->getJson('/test-keystone', [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => 'bad-signature',
    ])->assertUnauthorized();
});

it('middleware returns 401 for an unknown api_key', function (): void {
    keystoneRoute();

    $this->getJson('/test-keystone', [
        'X-API-Key' => 'ks_unknown',
        'X-API-Signature' => 'irrelevant',
    ])->assertUnauthorized();
});

it('middleware returns 401 for an expired key', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createApiKey('My App', [], now()->subDay()->toImmutable());

    $sig = makeSignature($result['api_key'], $result['secret_key']);

    $this->getJson('/test-keystone', [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

it('middleware returns 401 for a revoked key', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createApiKey('My App');
    $result['model']->revoke();

    $sig = makeSignature($result['api_key'], $result['secret_key']);

    $this->getJson('/test-keystone', [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();
});

// ── Middleware — Scope Enforcement ─────────────────────────────────────────

it('middleware allows a request when the key has the required scope', function (): void {
    keystoneRoute('read');
    $user = makeUser();
    $result = $user->createApiKey('My App', ['read', 'write']);

    $sig = makeSignature($result['api_key'], $result['secret_key']);

    $this->getJson('/test-keystone', [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => $sig,
    ])->assertOk();
});

it('middleware returns 401 when the key is missing a required scope', function (): void {
    keystoneRoute('write');
    $user = makeUser();
    $result = $user->createApiKey('My App', ['read']); // no 'write'

    $sig = makeSignature($result['api_key'], $result['secret_key']);

    $this->getJson('/test-keystone', [
        'X-API-Key' => $result['api_key'],
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
    $result = $user->createApiKey('My App');
    $sig = makeSignature($result['api_key'], $result['secret_key']);

    $this->getJson('/test-owner', [
        'X-API-Key' => $result['api_key'],
        'X-API-Signature' => $sig,
    ])->assertOk()->assertJson([
        'class' => User::class,
        'id' => $user->getKey(),
    ]);
});

// ── Key Lifecycle ──────────────────────────────────────────────────────────

it('revokeApiKey marks the key as revoked', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $user->revokeApiKey($result['model']);

    $this->assertDatabaseHas('api_keys', [
        'id' => $result['model']->id,
        'revoked_at' => now()->toDateTimeString(),
    ]);
});

it('revokeAllApiKeys revokes every active key', function (): void {
    $user = makeUser();
    $user->createApiKey('Key 1');
    $user->createApiKey('Key 2');

    $count = $user->revokeAllApiKeys();

    expect($count)->toBe(2);
    expect($user->apiKeys()->whereNull('revoked_at')->count())->toBe(0);
});

it('rotateApiKey revokes the old key and returns a new one', function (): void {
    $user = makeUser();
    $result = $user->createApiKey('My App');

    $rotated = $user->rotateApiKey($result['model']);

    expect($rotated['api_key'])->not->toBe($result['api_key']);
    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
    expect($rotated['model']->revoked_at)->toBeNull();
});
