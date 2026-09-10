<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Events\KeystoneAuthenticated;
use Schtzie\Keystone\Events\KeystoneAuthFailed;
use Schtzie\Keystone\Events\KeystoneCreated;
use Schtzie\Keystone\Events\KeystoneRateLimitExceeded;
use Schtzie\Keystone\Events\KeystoneRevoked;
use Schtzie\Keystone\Events\KeystoneRotated;
use Schtzie\Keystone\Tests\Fixtures\User;

beforeEach(function (): void {
    Event::fake();
});

describe('KeystoneCreated', function (): void {
    it('dispatches KeystoneCreated when a key is created', function (): void {
        $user = User::create(['name' => 'Test']);
        $user->createKeystone('My App');

        Event::assertDispatched(KeystoneCreated::class, function (KeystoneCreated $event): bool {
            return str_starts_with($event->plainClient, 'ks_')
                && $event->plainSecret !== ''
                && $event->keystone->id !== null;
        });
    });

    it('KeystoneCreated carries the correct plain credentials', function (): void {
        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');

        Event::assertDispatched(KeystoneCreated::class, function (KeystoneCreated $event) use ($result): bool {
            return $event->plainClient === $result['client']
                && $event->plainSecret === $result['secret'];
        });
    });
});

describe('KeystoneRevoked', function (): void {
    it('dispatches KeystoneRevoked when a key is revoked', function (): void {
        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');

        Event::fake(); // reset so we only capture the revoke event
        $result['model']->revoke();

        Event::assertDispatched(KeystoneRevoked::class, fn (KeystoneRevoked $e) => $e->keystone->id === $result['model']->id);
    });

    it('does not dispatch KeystoneRevoked when already revoked', function (): void {
        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');
        $result['model']->revoke();

        Event::fake();
        $result['model']->revoke(); // idempotent — no-op

        Event::assertNotDispatched(KeystoneRevoked::class);
    });
});

describe('KeystoneAuthenticated', function (): void {
    it('dispatches KeystoneAuthenticated on a successful middleware auth', function (): void {
        Route::middleware('api.key')->get('/test-events', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        $this->getJson('/test-events', [
            'X-Client-Id' => $result['client'],
            'X-API-Signature' => $sig,
        ])->assertOk();

        Event::assertDispatched(KeystoneAuthenticated::class);
    });
});

describe('KeystoneAuthFailed', function (): void {
    it('dispatches KeystoneAuthFailed with reason missing_credentials when no headers sent', function (): void {
        Route::middleware('api.key')->get('/test-fail', fn () => response()->json(['ok' => true]));

        $this->getJson('/test-fail')->assertUnauthorized();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'missing_credentials');
    });

    it('dispatches KeystoneAuthFailed with reason invalid_credentials for a bad signature', function (): void {
        Route::middleware('api.key')->get('/test-fail-sig', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');

        $this->getJson('/test-fail-sig', [
            'X-Client-Id' => $result['client'],
            'X-API-Signature' => 'bad',
        ])->assertUnauthorized();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'invalid_credentials');
    });

    it('dispatches KeystoneAuthFailed with reason insufficient_scope', function (): void {
        Route::middleware('api.key:write')->get('/test-scope', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App', ['read']);
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        $this->getJson('/test-scope', [
            'X-Client-Id' => $result['client'],
            'X-API-Signature' => $sig,
        ])->assertUnauthorized();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'insufficient_scope');
    });

    it('dispatches KeystoneAuthFailed with reason ip_not_allowed', function (): void {
        Route::middleware('api.key')->get('/test-ip-allow', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App', [], null, ['ip_allowlist' => ['192.168.1.100']]);
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->getJson('/test-ip-allow', [
                'X-Client-Id' => $result['client'],
                'X-API-Signature' => $sig,
            ])->assertForbidden();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'ip_not_allowed');
    });

    it('dispatches KeystoneAuthFailed with reason ip_blocked', function (): void {
        Route::middleware('api.key')->get('/test-ip-block', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App', [], null, ['ip_blocklist' => ['10.0.0.1']]);
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->getJson('/test-ip-block', [
                'X-Client-Id' => $result['client'],
                'X-API-Signature' => $sig,
            ])->assertForbidden();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'ip_blocked');
    });

    it('dispatches KeystoneAuthFailed with reason invalid_credentials for an expired key', function (): void {
        Route::middleware('api.key')->get('/test-expired', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App', [], now()->subDay()->toImmutable());
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        $this->getJson('/test-expired', [
            'X-Client-Id' => $result['client'],
            'X-API-Signature' => $sig,
        ])->assertUnauthorized();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'invalid_credentials');
    });

    it('dispatches KeystoneAuthFailed with reason invalid_credentials for a revoked key', function (): void {
        Route::middleware('api.key')->get('/test-revoked', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');
        $result['model']->revoke();
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        $this->getJson('/test-revoked', [
            'X-Client-Id' => $result['client'],
            'X-API-Signature' => $sig,
        ])->assertUnauthorized();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'invalid_credentials');
    });

    it('dispatches KeystoneAuthFailed with reason missing_owner when owner is deleted', function (): void {
        Route::middleware('api.key')->get('/test-owner', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        // Hard delete the owner model from the database to create an orphan key
        $user->delete();

        $this->getJson('/test-owner', [
            'X-Client-Id' => $result['client'],
            'X-API-Signature' => $sig,
        ])->assertUnauthorized();

        Event::assertDispatched(KeystoneAuthFailed::class, fn (KeystoneAuthFailed $e) => $e->reason === 'missing_owner');
    });
});

describe('KeystoneRateLimitExceeded', function (): void {
    it('dispatches KeystoneRateLimitExceeded when rate limit is hit', function (): void {
        Route::middleware('api.key')->get('/test-rl', fn () => response()->json(['ok' => true]));

        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App', [], null, ['rate_limit' => 1]);
        $sig = hash_hmac('sha256', $result['client'], $result['secret']);

        $headers = ['X-Client-Id' => $result['client'], 'X-API-Signature' => $sig];

        $this->getJson('/test-rl', $headers)->assertOk();
        $this->getJson('/test-rl', $headers)->assertStatus(429);

        Event::assertDispatched(KeystoneRateLimitExceeded::class);
    });
});

describe('KeystoneRotated', function (): void {
    it('dispatches KeystoneRotated when a key is rotated', function (): void {
        $user = User::create(['name' => 'Test']);
        $result = $user->createKeystone('My App');

        Event::fake();
        $user->rotateKeystone($result['model']);

        Event::assertDispatched(KeystoneRotated::class, function (KeystoneRotated $e) use ($result): bool {
            return $e->oldKeystone->id === $result['model']->id
                && $e->newKeystone->id !== $result['model']->id;
        });
    });
});
