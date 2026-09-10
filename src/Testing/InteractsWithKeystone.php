<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Testing;

use Illuminate\Database\Eloquent\Model;
use Schtzie\Keystone\Models\Keystone;

/**
 * Trait for PHPUnit / Pest test classes that provides ergonomic helpers for
 * authenticating HTTP requests through the Keystone middleware stack.
 *
 * Include this trait in your test class to gain access to `actingWithKeystone()`
 * — a single-call alternative to manually setting the client + signature headers
 * on every request that needs to pass `api.key` authentication.
 *
 * Usage (Pest):
 *
 *   uses(\Schtzie\Keystone\Testing\InteractsWithKeystone::class);
 *
 *   it('returns orders for the authenticated user', function (): void {
 *       $user = User::factory()->create();
 *
 *       $this->actingWithKeystone($user)
 *            ->getJson('/api/orders')
 *            ->assertOk();
 *   });
 *
 * Scope-restricted variant:
 *
 *   $this->actingWithKeystoneScopes($user, ['read'])
 *        ->postJson('/api/orders', [...])
 *        ->assertForbidden(); // 'write' scope required, only 'read' granted
 *
 * @phpstan-require-extends \Illuminate\Foundation\Testing\TestCase
 */
trait InteractsWithKeystone
{
    /**
     * Authenticate subsequent HTTP requests as the given Eloquent model owner.
     *
     * Generates a temporary API key pair for `$owner`, computes the HMAC-SHA256
     * signature, and injects both into the default request headers so that all
     * following `$this->getJson(...)` / `$this->postJson(...)` calls pass the
     * `api.key` middleware without any additional header setup.
     *
     * @param  Model  $owner  Any model that uses the HasKeystones trait.
     * @param  string  $name  Label for the temporary test key (visible in DB during test).
     * @param  array<int, string>  $scopes  Scopes to assign to the temporary key.
     */
    public function actingWithKeystone(
        Model $owner,
        string $name = 'Test Key',
        array $scopes = [],
    ): static {
        /** @var array{client: string, secret: string, model: Keystone} $result */
        $result = $owner->createKeystone($name, $scopes); // @phpstan-ignore-line
        $client = $result['client'];
        $secret = $result['secret'];
        $signature = hash_hmac('sha256', $client, $secret);

        $clientHeader = config('keystone.header', 'X-Client-Id');
        $sigHeader = config('keystone.signature_header', 'X-API-Signature');

        return $this->withHeaders([
            is_string($clientHeader) ? $clientHeader : 'X-Client-Id' => $client,
            is_string($sigHeader) ? $sigHeader : 'X-API-Signature' => $signature,
        ]);
    }

    /**
     * Authenticate subsequent HTTP requests with a scope-restricted temporary key.
     *
     * Shorthand for `actingWithKeystone($owner, 'Scoped Key', $scopes)`.
     * Useful when testing that scope-enforcement on a route works correctly.
     *
     * @param  array<int, string>  $scopes
     */
    public function actingWithKeystoneScopes(
        Model $owner,
        array $scopes,
        string $name = 'Scoped Test Key',
    ): static {
        return $this->actingWithKeystone($owner, $name, $scopes);
    }
}
