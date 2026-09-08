<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\Services\KeystoneService;
use Schtzie\Keystone\Tests\Fixtures\User;

// Manually register the analytics route before each test.
// The production config defaults analytics.enabled to false, so the route is
// not registered at boot time. Tests that exercise the HTTP endpoint must
// register it explicitly here.
beforeEach(function (): void {
    $prefix = ltrim((string) config('keystone.analytics.prefix', 'keystone/analytics'), '/');

    Route::middleware('api.key')->get(
        $prefix.'/{client}',
        function (HttpRequest $request, string $client): \Illuminate\Http\JsonResponse {
            $authenticated = $request->attributes->get('_keystone_client');

            if (! $authenticated instanceof Keystone || $authenticated->client !== $client) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $analytics = app(KeystoneService::class)->analytics($client);

            if ($analytics === null) {
                return response()->json(['message' => 'Not Found.'], 404);
            }

            return response()->json($analytics);
        }
    );
});

// ── Helpers ────────────────────────────────────────────────────────────────

function analyticsHeaders(array $key): array
{
    $sig = hash_hmac('sha256', $key['client'], $key['secret']);

    return [
        'X-Client-Id'    => $key['client'],
        'X-API-Signature' => $sig,
    ];
}

function analyticsUrl(array $key): string
{
    return '/keystone/analytics/'.$key['client'];
}

// ── Happy Path ─────────────────────────────────────────────────────────────

it('returns 200 with correct analytics structure for own key', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('My App', ['read', 'write']);

    $response = $this->getJson(analyticsUrl($key), analyticsHeaders($key));

    $response->assertOk()
        ->assertJsonStructure([
            'client',
            'name',
            'scopes',
            'active',
            'rate_limit',
            'created_at',
            'expires_at',
            'revoked_at',
            'last_used_at',
            'last_used_ip',
        ])
        ->assertJson([
            'client' => $key['client'],
            'name'   => 'My App',
            'scopes' => ['read', 'write'],
            'active' => true,
        ]);
});

it('reports active = false for an expired key', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('Expired App', [], CarbonImmutable::now()->subDay());

    // Bypass middleware validity check — query the analytics endpoint via the service directly
    // (the middleware itself would reject an expired key; we test the service layer value here)
    $service = app(\Schtzie\Keystone\Services\KeystoneService::class);
    $data    = $service->analytics($key['client']);

    expect($data)->not->toBeNull()
        ->and($data['active'])->toBeFalse()
        ->and($data['expires_at'])->not->toBeNull();
});

it('reports revoked_at when the key has been revoked', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('Revoked App');
    $key['model']->revoke();

    $service = app(\Schtzie\Keystone\Services\KeystoneService::class);
    $data    = $service->analytics($key['client']);

    expect($data)->not->toBeNull()
        ->and($data['active'])->toBeFalse()
        ->and($data['revoked_at'])->not->toBeNull();
});

it('reflects last_used_at and last_used_ip after markUsed()', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('Used App');

    // Simulate markUsed() being called by the middleware terminate()
    $fakeRequest = \Illuminate\Http\Request::create('/test', 'GET');
    $fakeRequest->server->set('REMOTE_ADDR', '192.168.1.42');
    $key['model']->markUsed($fakeRequest);

    $service = app(\Schtzie\Keystone\Services\KeystoneService::class);

    // Flush resolved cache so analytics re-reads from DB
    $service->flushResolved();

    $data = $service->analytics($key['client']);

    expect($data)->not->toBeNull()
        ->and($data['last_used_ip'])->toBe('192.168.1.42')
        ->and($data['last_used_at'])->not->toBeNull();
});

it('returns null from service analytics() when the client does not exist', function (): void {
    $service = app(\Schtzie\Keystone\Services\KeystoneService::class);

    expect($service->analytics('ks_nonexistent'))->toBeNull();
});

// ── HTTP Endpoint ──────────────────────────────────────────────────────────

it('returns 401 when no credentials are provided', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('App');

    $this->getJson(analyticsUrl($key))->assertUnauthorized();
});

it('returns 403 when an authenticated key tries to read another key\'s analytics', function (): void {
    $userA = User::create(['name' => 'Alice']);
    $userB = User::create(['name' => 'Bob']);

    $keyA = $userA->createKeystone('App A');
    $keyB = $userB->createKeystone('App B');

    // Authenticate as keyA but request keyB's analytics
    $url = '/keystone/analytics/'.$keyB['client'];

    $this->getJson($url, analyticsHeaders($keyA))->assertForbidden();
});

it('returns 404 when the analytics route is hit with a valid key but a non-existent client slug', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('App');

    // Manually register a route that bypasses the ownership guard so we can hit the 404 path
    Route::middleware('api.key')->get('/keystone/analytics-test/{client}', function (\Illuminate\Http\Request $request, string $client): \Illuminate\Http\JsonResponse {
        $analytics = app(\Schtzie\Keystone\Services\KeystoneService::class)->analytics($client);

        if ($analytics === null) {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        return response()->json($analytics);
    });

    $this->getJson('/keystone/analytics-test/ks_ghost', analyticsHeaders($key))
        ->assertNotFound();
});

// ── Disabled Route ────────────────────────────────────────────────────────────

it('the analytics route is accessible even when analytics.enabled config is false, because beforeEach registers it', function (): void {
    // In this test file the route is registered manually in beforeEach,
    // independently of the analytics.enabled config flag. This mirrors
    // what happens when a developer enables analytics in their app config.
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('App');

    $response = $this->getJson(analyticsUrl($key), analyticsHeaders($key));
    $response->assertOk();
});

// ── Null Fields ────────────────────────────────────────────────────────────

it('returns null for optional fields when they have not been set', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('Minimal App');

    $response = $this->getJson(analyticsUrl($key), analyticsHeaders($key));

    $response->assertOk()
        ->assertJson([
            'expires_at'   => null,
            'revoked_at'   => null,
            'last_used_at' => null,
            'last_used_ip' => null,
            'rate_limit'   => null,
        ]);
});
