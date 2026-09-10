<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;
use Schtzie\Keystone\Contracts\KeystoneServiceContract;
use Schtzie\Keystone\Models\Keystone;

/**
 * Test double for the Keystone authentication service.
 *
 * KeystoneFake implements {@see KeystoneServiceContract} and replaces the real
 * service in the container when you call `Keystone::fake()`. It bypasses all
 * real authentication logic (no DB lookups, no HMAC verification, no cache
 * interaction), allowing feature tests to focus on application behaviour rather
 * than authentication ceremony.
 *
 * Typical usage in a Pest / PHPUnit test:
 *
 *   $fake = \Keystone::fake();           // Swap the real service with the fake
 *   $this->getJson('/api/orders');       // Middleware sees the fake and returns $stubbedKey
 *   $fake->assertAuthenticated();        // Verify the auth path was exercised
 *
 * To simulate an authentication failure (e.g. revoked key):
 *
 *   $fake = \Keystone::fake(null);       // null → every resolve() call returns null → 401
 *   $this->getJson('/api/orders')->assertUnauthorized();
 *   $fake->assertAuthAttempted();
 *
 * @see \Schtzie\Keystone\Facades\Keystone::fake()
 */
final class KeystoneFake implements KeystoneServiceContract
{
    /** @var list<Request> Requests that reached resolve() */
    private array $resolvedRequests = [];

    /**
     * @param  Keystone|null  $stubbedKey  The key to return from every resolve() call.
     *                                     Pass null to simulate a failed authentication.
     */
    public function __construct(private readonly ?Keystone $stubbedKey = null) {}

    // ── KeystoneServiceContract ───────────────────────────────────────────────

    /**
     * Return the stubbed key (or null) without performing any real validation.
     * Records the request for later assertion.
     */
    public function resolve(Request $request): ?Keystone
    {
        $this->resolvedRequests[] = $request;

        return $this->stubbedKey;
    }

    /** @return Keystone|null Always returns the stubbed key. */
    public function findByKeystone(string $rawKey): ?Keystone
    {
        return $this->stubbedKey;
    }

    /**
     * @param  array{scopes?: array<int, string>, expires_at?: \Carbon\CarbonImmutable|null}  $options
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function generate(Model $owner, string $name, array $options = []): array
    {
        return $owner->createKeystone($name, $options['scopes'] ?? [], $options['expires_at'] ?? null); // @phpstan-ignore-line
    }

    /** No-op in the fake — no real cache to evict. */
    public function invalidate(string $client): void {}

    /** No-op in the fake — no in-memory map to clear. */
    public function flushResolved(): void {}

    // ── Assertions ────────────────────────────────────────────────────────────

    /**
     * Assert that the middleware exercised the authentication path at least once.
     *
     * Fails if no request ever reached resolve(), which may indicate the route
     * is not protected by `api.key` or the middleware was not registered.
     */
    public function assertAuthenticated(): void
    {
        Assert::assertNotEmpty(
            $this->resolvedRequests,
            'Expected at least one authenticated request, but no requests reached the Keystone resolver.'
        );

        Assert::assertNotNull(
            $this->stubbedKey,
            'Expected a successful authentication (non-null stubbed key), but the fake was configured to reject all requests.'
        );
    }

    /**
     * Assert that resolve() was called a specific number of times.
     *
     * Useful for verifying that caching or route grouping does not cause
     * redundant authentication calls.
     */
    public function assertAuthenticatedTimes(int $times): void
    {
        Assert::assertCount(
            $times,
            $this->resolvedRequests,
            "Expected {$times} authentication attempt(s), got ".count($this->resolvedRequests).'.'
        );
    }

    /**
     * Assert that the authentication resolver was called at least once,
     * regardless of whether it succeeded or failed.
     */
    public function assertAuthAttempted(): void
    {
        Assert::assertNotEmpty(
            $this->resolvedRequests,
            'Expected at least one authentication attempt, but resolve() was never called.'
        );
    }

    /**
     * Assert that no authentication attempts were made.
     *
     * Useful for verifying that public (unauthenticated) routes are not
     * accidentally going through the Keystone middleware stack.
     */
    public function assertNotAuthenticated(): void
    {
        Assert::assertEmpty(
            $this->resolvedRequests,
            'Expected no authentication attempts, but resolve() was called '.count($this->resolvedRequests).' time(s).'
        );
    }
}
