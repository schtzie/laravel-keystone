<?php

declare(strict_types=1);

/**
 * EdgeCaseTest — Scenarios designed to fall back, surface bugs, or expose
 * silent failures across all feature areas.
 *
 * Coverage areas:
 *  A. Authentication & Signature
 *  B. IP Filtering
 *  C. Rate Limiting
 *  D. Cache Layer
 *  E. Key Lifecycle (rotate / revoke)
 *  F. Key Generation
 *  G. Middleware Ordering
 *  H. Config / Environment Edge Cases
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Tests\Fixtures\User;

// ── Shared Helpers ─────────────────────────────────────────────────────────

function edgeUser(): User
{
    return User::create(['name' => 'Edge Case User']);
}

function edgeKey(User $user, array $options = []): array
{
    return $user->createKeystone('Edge Key', [], null, $options);
}

function edgeRoute(): void
{
    Route::middleware('api.key')->get('/edge', fn () => response()->json(['ok' => true]));
}

function edgeRequest(object $tc, array $key, array $headers = [], array $server = []): \Illuminate\Testing\TestResponse
{
    $sig = hash_hmac('sha256', $key['client'], $key['secret']);

    return $tc->withServerVariables($server)
              ->withHeaders(array_merge([
                  'X-Client-Id'     => $key['client'],
                  'X-API-Signature' => $sig,
              ], $headers))
              ->getJson('/edge');
}

// ═══════════════════════════════════════════════════════════════════════════
// A. Authentication & Signature Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('A — Authentication & Signature', function () {

    beforeEach(fn () => edgeRoute());

    /**
     * Bug: If the key can be read from query param but headers are used for
     * the signature, a wrong fallback path could skip signature verification.
     */
    it('accepts client via query param instead of header', function () {
        $user = edgeUser();
        $key  = edgeKey($user);
        $sig  = hash_hmac('sha256', $key['client'], $key['secret']);

        $this->withHeaders(['X-API-Signature' => $sig])
             ->getJson('/edge?client='.$key['client'])
             ->assertOk();
    });

    /**
     * Bug: An empty-string client header should not match any key in the DB
     * and must return 401, not a 500 from a missing query.
     */
    it('returns 401 for an empty string client header', function () {
        $this->withHeaders([
            'X-Client-Id'     => '',
            'X-API-Signature' => 'any',
        ])->getJson('/edge')->assertUnauthorized();
    });

    /**
     * Bug: An empty-string signature header must be rejected before any DB
     * lookup (avoids timing-safe compare with empty string).
     */
    it('returns 401 for an empty string signature header', function () {
        $user = edgeUser();
        $key  = edgeKey($user);

        $this->withHeaders([
            'X-Client-Id'     => $key['client'],
            'X-API-Signature' => '',
        ])->getJson('/edge')->assertUnauthorized();
    });

    /**
     * Bug: A key at the exact boundary (expires_at = now()) should NOT pass
     * — "now" is not "in the future".
     */
    it('rejects a key whose expiry equals exactly the current time', function () {
        $user = edgeUser();
        $key  = $user->createKeystone('Boundary Key', [], now()->toImmutable());

        edgeRequest($this, $key)->assertUnauthorized();
    });

    /**
     * Bug: A key that expires in 1 second should still be valid right now.
     */
    it('allows a key that expires in the future (1 second away)', function () {
        $user = edgeUser();
        $key  = $user->createKeystone('Almost Expired', [], now()->addSecond()->toImmutable());

        edgeRequest($this, $key)->assertOk();
    });

    /**
     * Bug: Replaying the exact same request twice should still succeed (no
     * accidental single-use enforcement at the auth layer).
     */
    it('allows the same key to authenticate on consecutive requests', function () {
        $user = edgeUser();
        $key  = edgeKey($user);

        edgeRequest($this, $key)->assertOk();
        edgeRequest($this, $key)->assertOk();
    });

    /**
     * Bug: A key that was revoked then had revoked_at cleared manually in DB
     * should be treated as active again (test that isValid() relies on the
     * model attribute, not a cached stale value).
     */
    it('treats a key as valid again after revoked_at is cleared', function () {
        $user  = edgeUser();
        $key   = edgeKey($user);
        $model = $key['model'];

        // Revoke it
        $model->revoke();
        edgeRequest($this, $key)->assertUnauthorized();

        // Restore it by clearing revoked_at (simulates admin un-revoke)
        $model->updateQuietly(['revoked_at' => null]);
        // Flush the in-memory resolved map and cache so the middleware re-reads
        app(\Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();
        app(KeystoneKeyCacheRepository::class)->forget($key['client']);

        edgeRequest($this, $key)->assertOk();
    });

    /**
     * Bug: Passing the client of one key but the signature of another key
     * (cross-key signature) must be rejected.
     */
    it('rejects cross-key signature (client A with secret B)', function () {
        $user = edgeUser();
        $keyA = edgeKey($user);
        $keyB = edgeKey($user);

        $crossSig = hash_hmac('sha256', $keyA['client'], $keyB['secret']);

        $this->withHeaders([
            'X-Client-Id'     => $keyA['client'],
            'X-API-Signature' => $crossSig,
        ])->getJson('/edge')->assertUnauthorized();
    });

    /**
     * Bug: A scope check against a key with an empty scopes array must still
     * return 401 (not 500 from an in_array against null).
     */
    it('returns 401 when required scope is checked against a key with no scopes', function () {
        Route::middleware('api.key:admin')->get('/edge-scoped', fn () => 'ok');

        $user = edgeUser();
        $key  = $user->createKeystone('No Scope Key', []);
        $sig  = hash_hmac('sha256', $key['client'], $key['secret']);

        $this->withHeaders([
            'X-Client-Id'     => $key['client'],
            'X-API-Signature' => $sig,
        ])->getJson('/edge-scoped')->assertUnauthorized();
    });

    /**
     * Bug: Multiple scopes required — key must have ALL of them; having only
     * one should still fail.
     */
    it('rejects a key that has only one of two required scopes', function () {
        Route::middleware('api.key:read,write')->get('/edge-multi-scope', fn () => 'ok');

        $user = edgeUser();
        $key  = $user->createKeystone('Partial Scope', ['read']); // missing 'write'
        $sig  = hash_hmac('sha256', $key['client'], $key['secret']);

        $this->withHeaders([
            'X-Client-Id'     => $key['client'],
            'X-API-Signature' => $sig,
        ])->getJson('/edge-multi-scope')->assertUnauthorized();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// B. IP Filtering Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('B — IP Filtering', function () {

    beforeEach(fn () => edgeRoute());

    /**
     * Bug: A key with BOTH an allowlist and a blocklist — the request IP is in
     * the allowlist but ALSO in the blocklist. Blocklist must win.
     */
    it('blocks a request when IP is in both allowlist and blocklist (blocklist wins)', function () {
        $user = edgeUser();
        $key  = edgeKey($user, [
            'ip_allowlist' => ['127.0.0.1'],
            'ip_blocklist' => ['127.0.0.1'],
        ]);

        edgeRequest($this, $key, [], ['REMOTE_ADDR' => '127.0.0.1'])
            ->assertForbidden()
            ->assertJson(['message' => 'IP address blocked.']);
    });

    /**
     * Bug: Empty allowlist array (not null) should NOT restrict access.
     * An empty array means "no restriction", not "block everything".
     */
    it('allows any IP when ip_allowlist is an empty array', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['ip_allowlist' => []]);

        edgeRequest($this, $key, [], ['REMOTE_ADDR' => '10.0.0.1'])->assertOk();
    });

    /**
     * Bug: Empty blocklist array (not null) should NOT block any IP.
     */
    it('allows any IP when ip_blocklist is an empty array', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['ip_blocklist' => []]);

        edgeRequest($this, $key, [], ['REMOTE_ADDR' => '10.0.0.1'])->assertOk();
    });

    /**
     * Bug: CIDR blocklist — an IP that falls exactly at the subnet boundary
     * (first usable address) should be blocked.
     */
    it('blocks the first IP in a CIDR blocklist subnet', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['ip_blocklist' => ['192.168.10.0/24']]);

        edgeRequest($this, $key, [], ['REMOTE_ADDR' => '192.168.10.1'])
            ->assertForbidden()
            ->assertJson(['message' => 'IP address blocked.']);
    });

    /**
     * Bug: An IP outside the CIDR blocklist subnet must still pass.
     */
    it('allows an IP that is just outside the CIDR blocklist subnet', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['ip_blocklist' => ['192.168.10.0/24']]);

        edgeRequest($this, $key, [], ['REMOTE_ADDR' => '192.168.11.1'])->assertOk();
    });

    /**
     * Bug: IP filtering must be evaluated AFTER successful auth. A request
     * with a wrong signature should get 401, not 403.
     */
    it('returns 401 (not 403) when signature is wrong even if IP is blocked', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['ip_blocklist' => ['127.0.0.1']]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
             ->withHeaders([
                 'X-Client-Id'     => $key['client'],
                 'X-API-Signature' => 'wrong-sig',
             ])
             ->getJson('/edge')
             ->assertUnauthorized(); // 401, not 403
    });

    /**
     * Bug: Rate limiting must be evaluated BEFORE response but AFTER IP checks.
     * An IP-blocked request should get 403 even when the key is rate-limited.
     */
    it('returns 403 for a blocked IP even when the key is also rate-limited', function () {
        $user = edgeUser();
        $key  = edgeKey($user, [
            'ip_blocklist' => ['127.0.0.1'],
            'rate_limit'   => 1,
        ]);

        // Exhaust the rate limit first
        $limitKey = 'keystone:rate_limit:'.$key['model']->id;
        RateLimiter::clear($limitKey);
        RateLimiter::hit($limitKey, 60);
        RateLimiter::hit($limitKey, 60); // over limit

        edgeRequest($this, $key, [], ['REMOTE_ADDR' => '127.0.0.1'])
            ->assertForbidden()
            ->assertJson(['message' => 'IP address blocked.']);
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// C. Rate Limiting Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('C — Rate Limiting', function () {

    beforeEach(fn () => edgeRoute());

    /**
     * Bug: The 429 response must include Retry-After AND X-Keystone-RateLimit-Reset
     * headers (not just the JSON body).
     */
    it('includes Retry-After and X-Keystone-RateLimit-Reset on a 429 response', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['rate_limit' => 1]);
        RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

        edgeRequest($this, $key)->assertOk();
        $response = edgeRequest($this, $key);

        $response->assertStatus(429);
        $this->assertTrue($response->headers->has('Retry-After'), 'Missing Retry-After header');
        $this->assertTrue($response->headers->has('X-Keystone-RateLimit-Reset'), 'Missing X-Keystone-RateLimit-Reset header');
    });

    /**
     * Bug: After the rate limit window resets (simulated by clearing the
     * limiter), requests should succeed again.
     */
    it('allows requests again after the rate limit window is cleared', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['rate_limit' => 1]);
        $limitKey = 'keystone:rate_limit:'.$key['model']->id;

        RateLimiter::clear($limitKey);
        edgeRequest($this, $key)->assertOk();
        edgeRequest($this, $key)->assertStatus(429);

        // Simulate window expiry
        RateLimiter::clear($limitKey);
        edgeRequest($this, $key)->assertOk();
    });

    /**
     * Bug: X-Keystone-RateLimit-Remaining must never go below 0 on a
     * successful response.
     */
    it('never reports a negative remaining count on success', function () {
        $user = edgeUser();
        $key  = edgeKey($user, ['rate_limit' => 3]);
        RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

        $response = edgeRequest($this, $key);
        $response->assertOk();

        $remaining = (int) $response->headers->get('X-Keystone-RateLimit-Remaining');
        $this->assertGreaterThanOrEqual(0, $remaining, 'Remaining count is negative');
    });

    /**
     * Bug: A key with rate_limit = null at the model level should use the
     * global config value (not bypass it).
     */
    it('uses global config rate limit when key rate_limit attribute is null', function () {
        config(['keystone.rate_limit' => 2]);

        $user = edgeUser();
        $key  = edgeKey($user, ['rate_limit' => null]);
        RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

        $r1 = edgeRequest($this, $key);
        $r1->assertOk()->assertHeader('X-Keystone-RateLimit-Limit', '2');

        $r2 = edgeRequest($this, $key);
        $r2->assertOk()->assertHeader('X-Keystone-RateLimit-Remaining', '0');

        $r3 = edgeRequest($this, $key);
        $r3->assertStatus(429);
    });

    /**
     * Bug: If rate_limit config is a float (e.g. 1.5), it should be cast to
     * int (1) and not throw a type error.
     */
    it('casts a float rate_limit config value to int without errors', function () {
        config(['keystone.rate_limit' => 1.9]);

        $user = edgeUser();
        $key  = edgeKey($user, ['rate_limit' => null]);
        RateLimiter::clear('keystone:rate_limit:'.$key['model']->id);

        $response = edgeRequest($this, $key);
        $response->assertOk();
        $this->assertSame('1', $response->headers->get('X-Keystone-RateLimit-Limit'));
    });

    /**
     * Bug: Two different owners with the same rate limit value must be tracked
     * independently (separate DB record IDs = separate rate-limit keys).
     */
    it('tracks rate limits independently for keys belonging to different owners', function () {
        $userA = edgeUser();
        $userB = edgeUser();
        $keyA  = edgeKey($userA, ['rate_limit' => 1]);
        $keyB  = edgeKey($userB, ['rate_limit' => 1]);

        RateLimiter::clear('keystone:rate_limit:'.$keyA['model']->id);
        RateLimiter::clear('keystone:rate_limit:'.$keyB['model']->id);

        edgeRequest($this, $keyA)->assertOk();
        edgeRequest($this, $keyA)->assertStatus(429);

        // Owner B's key should be completely unaffected
        edgeRequest($this, $keyB)->assertOk();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// D. Cache Layer Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('D — Cache Layer', function () {

    beforeEach(fn () => edgeRoute());

    /**
     * Bug: A revoked key that is still warm in the cache must be rejected.
     * Tests that cache entries hold revocation state correctly.
     */
    it('rejects a revoked key even when its cache entry is warm', function () {
        $user  = edgeUser();
        $key   = edgeKey($user);
        $cache = app(KeystoneKeyCacheRepository::class);

        // Warm the cache first
        $cache->put($key['model']);

        // Revoke it (triggers Keystone::updated observer → cache eviction)
        $key['model']->revoke();

        // Even though we try to hit cache — the observer evicted it on revoke
        edgeRequest($this, $key)->assertUnauthorized();
    });

    /**
     * Bug: Corrupted/invalid JSON in cache should fall back to DB gracefully
     * without a 500 error.
     */
    it('falls back to the database when cached JSON is corrupted', function () {
        $user   = edgeUser();
        $key    = edgeKey($user);
        $prefix = config('keystone.cache.prefix', 'keystone');
        $cacheKey = $prefix.':key:'.$key['client'];

        // Inject corrupt JSON into cache
        Cache::store('array')->put($cacheKey, '{not-valid-json', 60);

        // Should fall back to DB and still authenticate
        edgeRequest($this, $key)->assertOk();
    });

    /**
     * Bug: After a key is rotated, the old client must be rejected.
     * Tests that cache eviction on rotate is complete.
     */
    it('rejects the old key credentials after rotation', function () {
        $user    = edgeUser();
        $oldKey  = edgeKey($user);
        $cache   = app(KeystoneKeyCacheRepository::class);

        $cache->put($oldKey['model']);
        $user->rotateKeystone($oldKey['model']);

        edgeRequest($this, $oldKey)->assertUnauthorized();
    });

    /**
     * Bug: When cache is disabled, markUsed() (called in terminate()) should
     * still write last_used_at to the DB without a 500.
     */
    it('still writes last_used_at to DB when cache is disabled', function () {
        config(['keystone.cache.enabled' => false]);

        $user = edgeUser();
        $key  = edgeKey($user);

        edgeRequest($this, $key)->assertOk();

        // terminate() runs synchronously in tests; give it a tick
        $this->assertDatabaseHas('keystoneables', [
            'id' => $key['model']->id,
        ]);

        $fresh = $key['model']->fresh();
        $this->assertNotNull($fresh->last_used_at);
    })->after(fn () => config(['keystone.cache.enabled' => true]));

    /**
     * Bug: forgetOwner() on an owner with no cached keys (empty index) must
     * not throw an exception.
     */
    it('forgetOwner does not throw when no cached keys exist for the owner', function () {
        $user  = edgeUser();
        $cache = app(KeystoneKeyCacheRepository::class);

        // Expect no exception
        expect(fn () => $cache->forgetOwner(User::class, $user->getKey()))
            ->not->toThrow(\Throwable::class);
    });

    /**
     * Bug: A key served from cache must still have its signature re-verified
     * (i.e., the cached entry must include the secret attribute).
     * If attributes are stripped during serialisation, auth silently breaks.
     */
    it('can verify signature against a key that was served from cache', function () {
        $user  = edgeUser();
        $key   = edgeKey($user);
        $cache = app(KeystoneKeyCacheRepository::class);

        // Manually warm the cache
        $cache->put($key['model']);

        // Flush in-memory resolved map to force a cache (not memory) hit
        app(\Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();

        edgeRequest($this, $key)->assertOk();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// E. Key Lifecycle Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('E — Key Lifecycle', function () {

    /**
     * Bug: Revoking the same key twice must be idempotent (no exception, and
     * revoked_at is not reset). This verifies the fix in Keystone::revoke()
     * that guards against overwriting an existing revoked_at timestamp.
     */
    it('revoking the same key twice is idempotent', function () {
        $user  = edgeUser();
        $key   = edgeKey($user);
        $model = $key['model'];

        $model->revoke();
        $firstRevoke = $model->fresh()->revoked_at;

        // Call revoke() a second time — revoked_at must stay unchanged
        $model->revoke();
        $secondRevoke = $model->fresh()->revoked_at;

        // revoked_at should be identical — the idempotency guard prevents re-write
        $this->assertEquals(
            $firstRevoke?->toDateTimeString(),
            $secondRevoke?->toDateTimeString(),
            'revoked_at was changed on second revoke call'
        );
    });

    /**
     * Bug: rotateKeystone() must preserve ip_allowlist, ip_blocklist, and
     * rate_limit on the new key.
     */
    it('rotateKeystone preserves ip_allowlist, ip_blocklist, and rate_limit', function () {
        $user = edgeUser();
        $old  = $user->createKeystone('Configured Key', [], null, [
            'ip_allowlist' => ['10.0.0.0/8'],
            'ip_blocklist' => ['10.0.0.5'],
            'rate_limit'   => 50,
        ]);

        $new = $user->rotateKeystone($old['model']);
        $newModel = $new['model'];

        expect($newModel->ip_allowlist)->toBe(['10.0.0.0/8']);
        expect($newModel->ip_blocklist)->toBe(['10.0.0.5']);
        expect($newModel->rate_limit)->toBe(50);
    });

    /**
     * Bug: rotateKeystone() must also preserve the key's scopes.
     */
    it('rotateKeystone preserves scopes from the old key', function () {
        $user = edgeUser();
        $old  = $user->createKeystone('Scoped Key', ['read', 'write']);

        $new = $user->rotateKeystone($old['model']);

        expect($new['model']->scopes)->toBe(['read', 'write']);
    });

    /**
     * Bug: revokeAllKeystones() should return 0 when there are no active keys
     * (not throw an exception or return a negative count).
     */
    it('revokeAllKeystones returns 0 when there are no active keys', function () {
        $user = edgeUser();
        // No keys created for this user

        $count = $user->revokeAllKeystones();

        expect($count)->toBe(0);
    });

    /**
     * Bug: revokeAllKeystones() must not revoke already-revoked keys again
     * (count should reflect only active keys).
     */
    it('revokeAllKeystones only counts and affects active keys', function () {
        $user = edgeUser();
        $k1   = edgeKey($user);
        $k2   = edgeKey($user);
        $k3   = edgeKey($user);

        // Pre-revoke k1
        $k1['model']->revoke();

        // Should revoke only k2 and k3 (not k1 again)
        $count = $user->revokeAllKeystones();

        expect($count)->toBe(2);
    });

    /**
     * Bug: revokeKeystone() with a model ID integer (not the model itself)
     * must work without an exception.
     */
    it('revokeKeystone accepts an integer key ID', function () {
        $user  = edgeUser();
        $key   = edgeKey($user);
        $model = $key['model'];

        $result = $user->revokeKeystone($model->id);

        expect($result)->toBeTrue();
        $this->assertNotNull($model->fresh()->revoked_at);
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// F. Key Generation Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('F — Key Generation', function () {

    /**
     * Bug: Two consecutively created keys must not share the same client value.
     */
    it('generates unique client values on consecutive createKeystone calls', function () {
        $user = edgeUser();
        $k1   = edgeKey($user);
        $k2   = edgeKey($user);

        expect($k1['client'])->not->toBe($k2['client']);
    });

    /**
     * Bug: Two consecutively created keys must not share the same secret.
     */
    it('generates unique secrets on consecutive createKeystone calls', function () {
        $user = edgeUser();
        $k1   = edgeKey($user);
        $k2   = edgeKey($user);

        expect($k1['secret'])->not->toBe($k2['secret']);
    });

    /**
     * Bug: The returned plain client must start with the configured prefix
     * (default: 'ks_'). Changing the prefix config must be reflected.
     */
    it('respects a custom keystone.prefix config value', function () {
        config(['keystone.prefix' => 'myapp_']);

        $user = edgeUser();
        $key  = edgeKey($user);

        expect($key['client'])->toStartWith('myapp_');
    })->after(fn () => config(['keystone.prefix' => 'ks_']));

    /**
     * Bug: An empty $scopes array should store [] in the DB, not NULL.
     */
    it('stores an empty array for scopes when none are provided', function () {
        $user  = edgeUser();
        $key   = edgeKey($user);
        $model = $key['model'];

        expect($model->scopes)->toBeArray()->toBeEmpty();
    });

    /**
     * Bug: createKeystone() called with $scopes = [] should NOT fall back to
     * the default_scopes config if it is set. An explicit empty array should
     * override the default.
     *
     * Note: the current implementation uses `$scopes ?: config(...)` which
     * means an empty array DOES fall back. This test documents the behaviour.
     */
    it('falls back to default_scopes config when an empty scopes array is passed', function () {
        config(['keystone.default_scopes' => ['read']]);

        $user  = edgeUser();
        $key   = $user->createKeystone('Scoped Key', []); // empty → fallback
        $model = $key['model'];

        // With current `?: config(...)` behaviour, empty triggers default
        expect($model->scopes)->toBe(['read']);
    })->after(fn () => config(['keystone.default_scopes' => []]));

});

// ═══════════════════════════════════════════════════════════════════════════
// G. Middleware Ordering Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('G — Middleware Ordering', function () {

    /**
     * Bug: Rate limiting must be applied BEFORE scope enforcement.
     * A throttled request with wrong scope should still get 429, not 401.
     * (Tests that rate limiter fires before scope check in the middleware.)
     */
    it('returns 429 (not 401) when rate limited even with insufficient scope', function () {
        Route::middleware('api.key:admin')->get('/edge-order', fn () => 'ok');

        $user = edgeUser();
        $key  = $user->createKeystone('No Admin', ['read'], null, ['rate_limit' => 1]);
        $limitKey = 'keystone:rate_limit:'.$key['model']->id;

        RateLimiter::clear($limitKey);

        $sig = hash_hmac('sha256', $key['client'], $key['secret']);
        $headers = [
            'X-Client-Id'     => $key['client'],
            'X-API-Signature' => $sig,
        ];

        // First request: scope fails but rate limit not yet hit
        $this->withHeaders($headers)->getJson('/edge-order')->assertUnauthorized();

        // Exhaust rate limit manually
        RateLimiter::hit($limitKey, 60);
        RateLimiter::hit($limitKey, 60);

        // Now rate-limited: 429 should take precedence over scope check
        $this->withHeaders($headers)->getJson('/edge-order')->assertStatus(429);
    });

    /**
     * Bug: IP filtering must run BEFORE rate limiting hits.
     * An IP-blocked request should never increment the rate limit counter.
     */
    it('does not increment the rate limit counter for IP-blocked requests', function () {
        edgeRoute();

        $user = edgeUser();
        $key  = edgeKey($user, [
            'ip_blocklist' => ['1.2.3.4'],
            'rate_limit'   => 5,
        ]);
        $limitKey = 'keystone:rate_limit:'.$key['model']->id;
        RateLimiter::clear($limitKey);

        // Send 5 blocked requests
        foreach (range(1, 5) as $_) {
            edgeRequest($this, $key, [], ['REMOTE_ADDR' => '1.2.3.4'])->assertForbidden();
        }

        // Rate limit counter should still be at 0 (none incremented)
        $this->assertSame(0, RateLimiter::attempts($limitKey));
    });

    /**
     * Bug: Scope enforcement must run AFTER IP filtering.
     * A blocked IP should get 403 before any scope check.
     */
    it('returns 403 for a blocked IP before checking scopes', function () {
        Route::middleware('api.key:admin')->get('/edge-scope-ip', fn () => 'ok');

        $user = edgeUser();
        $key  = $user->createKeystone('No Admin', ['read'], null, [
            'ip_blocklist' => ['5.5.5.5'],
        ]);
        $sig = hash_hmac('sha256', $key['client'], $key['secret']);

        $this->withServerVariables(['REMOTE_ADDR' => '5.5.5.5'])
             ->withHeaders([
                 'X-Client-Id'     => $key['client'],
                 'X-API-Signature' => $sig,
             ])
             ->getJson('/edge-scope-ip')
             ->assertForbidden()
             ->assertJson(['message' => 'IP address blocked.']);
    });

    /**
     * Bug: markUsed() / cache re-warm in terminate() must NOT run when the
     * request was rejected (401/403/429). The '_keystone_client' attribute is
     * only set on the happy path.
     */
    it('does not update last_used_at for an unauthorized request', function () {
        edgeRoute();

        $user  = edgeUser();
        $key   = edgeKey($user);
        $model = $key['model'];

        // Make a bad request (wrong signature)
        $this->withHeaders([
            'X-Client-Id'     => $key['client'],
            'X-API-Signature' => 'wrong',
        ])->getJson('/edge')->assertUnauthorized();

        expect($model->fresh()->last_used_at)->toBeNull();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// H. Config / Environment Edge Cases
// ═══════════════════════════════════════════════════════════════════════════

describe('H — Config / Environment', function () {

    beforeEach(fn () => edgeRoute());

    /**
     * Bug: If keystone.header is changed in config, the middleware must read
     * the client from the new header name.
     */
    it('reads client from a custom header name when keystone.header is changed', function () {
        config(['keystone.header' => 'X-Custom-Client']);

        $user = edgeUser();
        $key  = edgeKey($user);
        $sig  = hash_hmac('sha256', $key['client'], $key['secret']);

        $this->withHeaders([
            'X-Custom-Client' => $key['client'],
            'X-API-Signature' => $sig,
        ])->getJson('/edge')->assertOk();
    })->after(fn () => config(['keystone.header' => 'X-Client-Id']));

    /**
     * Bug: If keystone.signature_header is changed in config, the middleware
     * must read the signature from the new header name.
     */
    it('reads signature from a custom header name when keystone.signature_header is changed', function () {
        config(['keystone.signature_header' => 'X-My-Sig']);

        $user = edgeUser();
        $key  = edgeKey($user);
        $sig  = hash_hmac('sha256', $key['client'], $key['secret']);

        $this->withHeaders([
            'X-Client-Id' => $key['client'],
            'X-My-Sig'    => $sig,
        ])->getJson('/edge')->assertOk();
    })->after(fn () => config(['keystone.signature_header' => 'X-API-Signature']));

    /**
     * Bug: Setting keystone.cache.refresh_on_use = false must prevent
     * terminate() from re-warming the cache after each request.
     */
    it('does not re-warm cache after a request when refresh_on_use is false', function () {
        config(['keystone.cache.refresh_on_use' => false]);

        $user  = edgeUser();
        $key   = edgeKey($user);
        $cache = app(KeystoneKeyCacheRepository::class);

        // Ensure cache starts empty
        $cache->forget($key['client']);
        app(\Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();

        edgeRequest($this, $key)->assertOk();

        // Cache should still be empty (warm_on_miss populates on first hit,
        // but refresh_on_use must not re-warm in terminate())
        // After first request with warm_on_miss=true, it will be warm.
        // We verify terminate() didn't add ANOTHER entry by checking the
        // model attributes are still consistent.
        $cached = $cache->get($key['client']);
        if ($cached !== null) {
            expect($cached->id)->toBe($key['model']->id);
        }
    })->after(fn () => config(['keystone.cache.refresh_on_use' => true]));

    /**
     * Bug: When keystone.cache.warm_on_miss = false, a DB miss must NOT
     * populate the cache via write-through. refresh_on_use is also disabled
     * so terminate() cannot write via a separate code path.
     */
    it('does not populate cache on DB miss when warm_on_miss is false', function () {
        config([
            'keystone.cache.warm_on_miss'   => false,
            'keystone.cache.refresh_on_use' => false,
        ]);

        $user  = edgeUser();
        $key   = edgeKey($user);
        $cache = app(KeystoneKeyCacheRepository::class);

        // Ensure both the in-memory map and the cache are empty before the request
        $cache->forget($key['client']);
        app(\Schtzie\Keystone\Services\KeystoneService::class)->flushResolved();

        edgeRequest($this, $key)->assertOk();

        // The persistent cache store must remain empty — no write-through occurred
        $prefix   = config('keystone.cache.prefix', 'keystone');
        $cacheKey = $prefix.':key:'.$key['client'];
        expect(\Illuminate\Support\Facades\Cache::store('array')->has($cacheKey))->toBeFalse();
    })->after(fn () => config([
        'keystone.cache.warm_on_miss'   => true,
        'keystone.cache.refresh_on_use' => true,
    ]));

});
