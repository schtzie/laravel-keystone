<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

beforeEach(function (): void {
    config([
        'keystone.replay_protection.enabled'        => true,
        'keystone.replay_protection.window_seconds' => 30,
    ]);
});

function replayRoute(): void
{
    Route::middleware('api.key')->get('/test-replay', fn () => response()->json(['ok' => true]));
}

function keystoneHeaders(string $client, string $secret, ?int $timestamp = null): array
{
    return [
        'X-Client-Id'    => $client,
        'X-API-Signature' => hash_hmac('sha256', $client, $secret),
        'X-Timestamp'    => (string) ($timestamp ?? time()),
    ];
}

it('allows a request with a fresh timestamp', function (): void {
    replayRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $this->getJson('/test-replay', keystoneHeaders($result['client'], $result['secret']))->assertOk();
});

it('rejects a request with a timestamp older than the window', function (): void {
    replayRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    // Timestamp from 60 seconds ago — outside the 30-second window
    $staleTs = time() - 60;

    $this->getJson('/test-replay', keystoneHeaders($result['client'], $result['secret'], $staleTs))->assertUnauthorized();
});

it('rejects a request with a future timestamp beyond the window', function (): void {
    replayRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $futureTs = time() + 60; // 60 seconds in the future

    $this->getJson('/test-replay', keystoneHeaders($result['client'], $result['secret'], $futureTs))->assertUnauthorized();
});

it('rejects a request with a missing timestamp header when replay protection is on', function (): void {
    replayRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $this->getJson('/test-replay', [
        'X-Client-Id'    => $result['client'],
        'X-API-Signature' => hash_hmac('sha256', $result['client'], $result['secret']),
    ])->assertUnauthorized();
});

it('passes all requests when replay protection is disabled', function (): void {
    config(['keystone.replay_protection.enabled' => false]);
    replayRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $staleTs = time() - 9999;

    $this->getJson('/test-replay', [
        'X-Client-Id'    => $result['client'],
        'X-API-Signature' => hash_hmac('sha256', $result['client'], $result['secret']),
        'X-Timestamp'    => (string) $staleTs,
    ])->assertOk();
});
