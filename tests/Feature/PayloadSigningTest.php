<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

// ── Payload signing middleware ─────────────────────────────────────────────

function setupPayloadRoute(): void
{
    Route::middleware(['api.key', 'api.key.payload'])->post('/test-payload', fn () => response()->json(['ok' => true]));
}

function authHeaders(string $client, string $secret): array
{
    return [
        'X-Client-Id'    => $client,
        'X-API-Signature' => hash_hmac('sha256', $client, $secret),
    ];
}

it('allows a request with a valid body hash', function (): void {
    setupPayloadRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $body = json_encode(['order_id' => 123]);
    $hash = hash_hmac('sha256', $body, $result['secret']);

    $this->postJson('/test-payload', ['order_id' => 123], array_merge(
        authHeaders($result['client'], $result['secret']),
        ['X-Body-Hash' => $hash],
    ))->assertOk();
});

it('rejects a request with a mismatched body hash', function (): void {
    setupPayloadRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $this->postJson('/test-payload', ['order_id' => 123], array_merge(
        authHeaders($result['client'], $result['secret']),
        ['X-Body-Hash' => 'tampered-hash'],
    ))->assertStatus(400);
});

it('rejects a request with a missing body hash header', function (): void {
    setupPayloadRoute();

    config(['keystone.body_signing.require_on_empty' => true]);

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $this->postJson('/test-payload', ['order_id' => 123], authHeaders($result['client'], $result['secret'])
    )->assertStatus(400);
});

it('passes empty body requests when require_on_empty is false', function (): void {
    config(['keystone.body_signing.require_on_empty' => false]);

    Route::middleware(['api.key', 'api.key.payload'])->get('/test-payload-empty', fn () => response()->json(['ok' => true]));

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    // No X-Body-Hash header provided — should still pass because require_on_empty is false
    // and a GET request has no body (empty string).
    $this->call('GET', '/test-payload-empty', [], [], [], array_merge(
        ['HTTP_X-Client-Id' => $result['client']],
        ['HTTP_X-API-Signature' => hash_hmac('sha256', $result['client'], $result['secret'])],
    ))->assertOk();
});

