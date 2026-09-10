<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Tests\Fixtures\User;

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
        ->and($result['model'])->toBeInstanceOf(Schtzie\Keystone\Models\Keystone::class);
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

it('saves options fields to the database automatically', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('Enhanced Key', [], null, [
        'description' => 'My custom test description',
        'metadata' => ['tag' => 'production'],
        'ip_allowlist' => ['192.168.1.0/24'],
        'ip_blocklist' => ['10.0.0.15'],
        'rate_limit' => 500,
    ]);

    /** @var Schtzie\Keystone\Models\Keystone $model */
    $model = $result['model']->fresh();

    expect($model->description)->toBe('My custom test description')
        ->and($model->metadata)->toBe(['tag' => 'production'])
        ->and($model->ip_allowlist)->toBe(['192.168.1.0/24'])
        ->and($model->ip_blocklist)->toBe(['10.0.0.15'])
        ->and($model->rate_limit)->toBe(500);
});

it('supports owner models with UUID/ULID string primary keys', function (): void {
    // 1. Temporarily change the keystoneables table to support string IDs
    Illuminate\Support\Facades\Schema::table(config('keystone.table', 'keystoneables'), function (Illuminate\Database\Schema\Blueprint $table) {
        $table->string('keystoneable_id')->change();
    });

    // 2. Create a mock UUID User table
    Illuminate\Support\Facades\Schema::create('uuid_users', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->uuid('id')->primary();
        $table->timestamps();
    });

    // 3. Define the Eloquent Model on the fly
    $uuidUserClass = new class extends Illuminate\Database\Eloquent\Model
    {
        use Illuminate\Database\Eloquent\Concerns\HasUuids;
        use Schtzie\Keystone\Traits\HasKeystones;

        protected $table = 'uuid_users';
    };

    // 4. Create the UUID user and generate an API key for them
    $uuidUser = $uuidUserClass::create();
    $result = $uuidUser->createKeystone('UUID Owner Key');

    // 5. Assert it works perfectly!
    $this->assertDatabaseHas('keystoneables', [
        'keystoneable_type' => get_class($uuidUserClass),
        'keystoneable_id' => $uuidUser->id,
        'client' => $result['client'],
    ]);

    expect($result['model']->keystoneable_id)->toBe($uuidUser->id);
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

it('middleware handles invalid guard configurations gracefully', function (): void {
    keystoneRoute();
    $user = makeUser();
    $result = $user->createKeystone('My App');
    $sig = makeSignature($result['client'], $result['secret']);

    // Configure a guard that doesn't exist
    config(['keystone.guard' => 'nonexistent_guard']);

    // Middleware should swallow InvalidArgumentException and still authenticate the request
    $this->getJson('/test-keystone', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();
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

it('revokeKeystone throws an exception if the model belongs to a different owner', function (): void {
    $userA = User::create(['name' => 'User A']);
    $userB = User::create(['name' => 'User B']);

    $keyB = $userB->createKeystone('Key B')['model'];

    // User A attempts to revoke User B's key model instance directly
    expect(fn () => $userA->revokeKeystone($keyB))->toThrow(InvalidArgumentException::class, 'This keystone does not belong to this owner.');
});

it('rotateKeystone revokes the old key and returns a new one', function (): void {
    $user = makeUser();
    $result = $user->createKeystone('My App');

    $rotated = $user->rotateKeystone($result['model']);

    expect($rotated['client'])->not->toBe($result['client']);
    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
    expect($rotated['model']->revoked_at)->toBeNull();
});

it('rotateKeystone throws an exception if the model belongs to a different owner', function (): void {
    $userA = User::create(['name' => 'User A']);
    $userB = User::create(['name' => 'User B']);

    $keyB = $userB->createKeystone('Key B')['model'];

    // User A attempts to rotate User B's key model instance directly
    expect(fn () => $userA->rotateKeystone($keyB))->toThrow(InvalidArgumentException::class, 'This keystone does not belong to this owner.');
});
