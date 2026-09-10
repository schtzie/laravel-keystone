<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

// ── Access log database driver ─────────────────────────────────────────────

beforeEach(function (): void {
    config([
        'keystone.access_log.enabled' => true,
        'keystone.access_log.driver' => 'database',
    ]);
});

function accessLogRoute(): void
{
    Route::middleware('api.key')->get('/test-log', fn () => response()->json(['ok' => true]));
}

it('writes an authenticated log entry on a successful request', function (): void {
    accessLogRoute();

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-log', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();

    $this->assertDatabaseHas('keystone_access_logs', [
        'keystone_id' => $result['model']->id,
        'event' => 'authenticated',
        'status_code' => 200,
    ]);
});

it('writes a rejected_invalid log entry when credentials are wrong', function (): void {
    accessLogRoute();

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $this->getJson('/test-log', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => 'wrong-sig',
    ])->assertUnauthorized();

    $this->assertDatabaseHas('keystone_access_logs', [
        'event' => 'rejected_invalid',
        'status_code' => 401,
    ]);
});

it('writes a rate_limited log entry when the limit is exceeded', function (): void {
    accessLogRoute();

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App', [], null, ['rate_limit' => 1]);
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $headers = [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ];

    $this->getJson('/test-log', $headers)->assertOk();
    $this->getJson('/test-log', $headers)->assertStatus(429);

    $this->assertDatabaseHas('keystone_access_logs', [
        'keystone_id' => $result['model']->id,
        'event' => 'rate_limited',
        'status_code' => 429,
    ]);
});

it('writes a rejected_ip log entry when the IP is blocked', function (): void {
    accessLogRoute();

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App', [], null, ['ip_blocklist' => ['10.0.0.1']]);
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->getJson('/test-log', [
            'X-Client-Id' => $result['client'],
            'X-API-Signature' => $sig,
        ])->assertForbidden();

    $this->assertDatabaseHas('keystone_access_logs', [
        'event' => 'rejected_ip',
        'status_code' => 403,
    ]);
});

it('writes a rejected_scope log entry when scopes are insufficient', function (): void {
    Route::middleware('api.key:admin')->get('/test-log-scope', fn () => response()->json(['ok' => true]));

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App', ['user']); // Missing admin scope
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-log-scope', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertUnauthorized();

    $this->assertDatabaseHas('keystone_access_logs', [
        'event' => 'rejected_scope',
        'status_code' => 403,
    ]);
});

it('does not write log entries when access logging is disabled', function (): void {
    config(['keystone.access_log.enabled' => false]);
    accessLogRoute();

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-log', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();

    $this->assertDatabaseMissing('keystone_access_logs', ['event' => 'authenticated']);
});

it('gracefully swallows database exceptions during logging without crashing the request', function (): void {
    accessLogRoute();

    $user = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');
    $sig = hash_hmac('sha256', $result['client'], $result['secret']);

    // Force a database error by dropping the logging table
    Illuminate\Support\Facades\Schema::drop('keystone_access_logs');

    $this->getJson('/test-log', [
        'X-Client-Id' => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk(); // Must not crash with 500
});
