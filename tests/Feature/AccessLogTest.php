<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

// ── Access log database driver ─────────────────────────────────────────────

beforeEach(function (): void {
    config([
        'keystone.access_log.enabled' => true,
        'keystone.access_log.driver'  => 'database',
    ]);
});

function accessLogRoute(): void
{
    Route::middleware('api.key')->get('/test-log', fn () => response()->json(['ok' => true]));
}

it('writes an authenticated log entry on a successful request', function (): void {
    accessLogRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');
    $sig    = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-log', [
        'X-Client-Id'    => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();

    $this->assertDatabaseHas('keystone_access_logs', [
        'keystone_id' => $result['model']->id,
        'event'       => 'authenticated',
        'status_code' => 200,
    ]);
});

it('writes a rejected_invalid log entry when credentials are wrong', function (): void {
    accessLogRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');

    $this->getJson('/test-log', [
        'X-Client-Id'    => $result['client'],
        'X-API-Signature' => 'wrong-sig',
    ])->assertUnauthorized();

    $this->assertDatabaseHas('keystone_access_logs', [
        'event'       => 'rejected_invalid',
        'status_code' => 401,
    ]);
});

it('writes a rate_limited log entry when the limit is exceeded', function (): void {
    accessLogRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App', [], null, ['rate_limit' => 1]);
    $sig    = hash_hmac('sha256', $result['client'], $result['secret']);
    $headers = ['X-Client-Id' => $result['client'], 'X-API-Signature' => $sig];

    $this->getJson('/test-log', $headers)->assertOk();
    $this->getJson('/test-log', $headers)->assertStatus(429);

    $this->assertDatabaseHas('keystone_access_logs', [
        'keystone_id' => $result['model']->id,
        'event'       => 'rate_limited',
        'status_code' => 429,
    ]);
});

it('does not write log entries when access logging is disabled', function (): void {
    config(['keystone.access_log.enabled' => false]);
    accessLogRoute();

    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My App');
    $sig    = hash_hmac('sha256', $result['client'], $result['secret']);

    $this->getJson('/test-log', [
        'X-Client-Id'    => $result['client'],
        'X-API-Signature' => $sig,
    ])->assertOk();

    $this->assertDatabaseMissing('keystone_access_logs', ['event' => 'authenticated']);
});
