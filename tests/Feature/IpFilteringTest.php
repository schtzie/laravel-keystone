<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Tests\Fixtures\User;

beforeEach(function () {
    Route::middleware('api.key')->get('/protected', fn () => 'ok');
});

function getProtectedIp(object $testCase, array $key, array $serverVars = [])
{
    $client = $key['client'];
    $secret = $key['secret'];
    $signature = hash_hmac('sha256', $client, $secret);

    return $testCase->withServerVariables($serverVars)
        ->withHeaders([
            'X-Client-Id' => $client,
            'X-API-Signature' => $signature,
        ])
        ->get('/protected');
}

it('allows request when IP is in allowlist', function () {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('Test Key', [], null, ['ip_allowlist' => ['127.0.0.1']]);

    getProtectedIp($this, $key, ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertOk();
});

it('rejects request when IP is not in allowlist', function () {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('Test Key', [], null, ['ip_allowlist' => ['192.168.1.1']]);

    getProtectedIp($this, $key, ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertForbidden()
        ->assertJson(['message' => 'IP address not allowed.']);
});

it('rejects request when IP is in blocklist', function () {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('Test Key', [], null, ['ip_blocklist' => ['127.0.0.1']]);

    getProtectedIp($this, $key, ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertForbidden()
        ->assertJson(['message' => 'IP address blocked.']);
});

it('supports CIDR notation in allowlist', function () {
    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('Test Key', [], null, ['ip_allowlist' => ['192.168.1.0/24']]);

    getProtectedIp($this, $key, ['REMOTE_ADDR' => '192.168.1.50'])
        ->assertOk();

    getProtectedIp($this, $key, ['REMOTE_ADDR' => '10.0.0.1'])
        ->assertForbidden();
});

it('gracefully handles missing IP columns for backwards compatibility', function () {
    // Drop the columns to simulate an older v2.2.x database schema
    Illuminate\Support\Facades\Schema::table(config('keystone.table', 'keystoneables'), function (Illuminate\Database\Schema\Blueprint $table) {
        $table->dropColumn(['ip_allowlist', 'ip_blocklist']);
    });

    $user = User::create(['name' => 'Test']);
    $key = $user->createKeystone('Backwards Compat Key');

    // The middleware should successfully authenticate without crashing or throwing SQL errors
    getProtectedIp($this, $key, ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertOk();
});
