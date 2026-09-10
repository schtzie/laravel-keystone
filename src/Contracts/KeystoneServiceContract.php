<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Schtzie\Keystone\Models\Keystone;

/**
 * Defines the public surface of the Keystone authentication service.
 *
 * Coding to this interface rather than the concrete KeystoneService class
 * unlocks two important capabilities:
 *
 *   1. **Testability** — Tests can swap in KeystoneFake (which implements this
 *      interface) via the service container to stub authentication behaviour
 *      without touching the database or cache. See `Keystone::fake()`.
 *
 *   2. **Extensibility** — Applications can publish their own service
 *      implementation (e.g. adding custom validation logic) and bind it to
 *      this interface in the container without modifying the package.
 *
 * The AuthenticateWithKeystone middleware and the Keystone Facade both resolve
 * this interface from the container, ensuring the swap is transparent.
 */
interface KeystoneServiceContract
{
    /**
     * Attempt to authenticate an incoming HTTP request.
     *
     * Reads the client ID and HMAC signature from the configured header or
     * query parameter, resolves the corresponding Keystone record through the
     * cache → database pipeline, validates HMAC-SHA256, checks expiry and
     * revocation status, and optionally verifies the request timestamp against
     * the replay-protection window.
     *
     * Returns null if any check fails; the middleware will respond with 401.
     */
    public function resolve(Request $request): ?Keystone;

    /**
     * Perform a cache-aware lookup for a Keystone by its plain client identifier.
     *
     * Checks in-memory → Redis → database in that order. On a database hit with
     * `warm_on_miss` enabled, the record is written back into Redis so subsequent
     * requests skip the DB round-trip entirely.
     */
    public function findByKeystone(string $rawKey): ?Keystone;

    /**
     * Convenience proxy to create a new Keystone for the given owner model.
     *
     * Delegates to the owner's `createKeystone()` method (provided by the
     * HasKeystones trait), surfacing key generation through the service layer
     * so Facade consumers do not need a direct model reference.
     *
     * @param  array{scopes?: array<int, string>, expires_at?: \Carbon\CarbonImmutable|null}  $options
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function generate(Model $owner, string $name, array $options = []): array;

    /**
     * Immediately evict a client from both the in-memory resolved map and Redis.
     *
     * Call this when you have revoked or updated a key outside the normal
     * Eloquent model lifecycle (e.g. a raw DB update) and need to ensure the
     * cache is purged without waiting for the next TTL expiry.
     */
    public function invalidate(string $client): void;

    /**
     * Clear the in-memory resolved-key map for the current request/process.
     *
     * Called automatically by KeystoneBootstrapper on every tenant switch in
     * Octane and queue-worker contexts to prevent cross-tenant key leakage.
     */
    public function flushResolved(): void;
}
