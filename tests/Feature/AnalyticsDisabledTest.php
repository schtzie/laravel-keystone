<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

// ── Analytics Disabled (default) ───────────────────────────────────────────

it('analytics.enabled defaults to false', function (): void {
    expect(config('keystone.analytics.enabled'))->toBeFalse();
});

it('the analytics route is not registered when analytics.enabled is false', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('App');
    $sig  = hash_hmac('sha256', $key['client'], $key['secret']);

    // Route was never registered during boot — any request to the analytics
    // URL should return 404 (route not found), not 401/403.
    $this->getJson('/keystone/analytics/'.$key['client'], [
        'X-Client-Id'     => $key['client'],
        'X-API-Signature' => $sig,
    ])->assertNotFound();
});

it('the analytics route is not registered even for unauthenticated requests when disabled', function (): void {
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('App');

    // Without credentials and with the route absent, the response must
    // still be 404 (not 401), because the route itself does not exist.
    $this->getJson('/keystone/analytics/'.$key['client'])->assertNotFound();
});

it('the service-layer analytics() still works when the route is disabled', function (): void {
    // Disabling the HTTP route does not affect the KeystoneService::analytics()
    // method — it is always available for direct programmatic use.
    $user = User::create(['name' => 'Alice']);
    $key  = $user->createKeystone('App');

    $service = app(\Schtzie\Keystone\Services\KeystoneService::class);
    $data    = $service->analytics($key['client']);

    expect($data)->not->toBeNull()
        ->and($data['client'])->toBe($key['client'])
        ->and($data['active'])->toBeTrue();
});
