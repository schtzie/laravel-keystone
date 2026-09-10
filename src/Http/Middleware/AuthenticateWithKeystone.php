<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Contracts\KeystoneServiceContract;
use Schtzie\Keystone\Events\KeystoneAuthFailed;
use Schtzie\Keystone\Events\KeystoneAuthenticated;
use Schtzie\Keystone\Events\KeystoneRateLimitExceeded;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\RateLimiting\Contracts\RateLimitStrategy;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates incoming requests using a Client ID + HMAC-SHA256 signature pair.
 *
 * This middleware is the front gate for every API-key-protected route. It runs
 * sequentially through the following checks, returning the appropriate HTTP error
 * code (and dispatching a failure event) at the first failing step:
 *
 *   1. **Credential resolution** — reads client ID + signature from headers or
 *      query params, runs replay-protection timestamp check (if enabled), performs
 *      cache→DB lookup, validates HMAC, and confirms the key is not revoked/expired.
 *      → 401 on failure (event reason: 'missing_credentials' or 'invalid_credentials')
 *
 *   2. **IP allowlist / blocklist** — checks the request IP against the key's
 *      per-key lists using Symfony's IpUtils (supports CIDRs, ranges, wildcards).
 *      → 403 on failure (event reason: 'ip_not_allowed' or 'ip_blocked')
 *
 *   3. **Per-minute rate limiting** — delegates to the configured RateLimitStrategy
 *      (fixed_window / sliding_window / token_bucket) using the key's per-key limit
 *      or the global default, narrowed further by scope-specific limits if configured.
 *      → 429 on throttle (event: KeystoneRateLimitExceeded)
 *
 *   4. **Per-day rate limiting** — optional daily cap (config: `rate_limit_daily`).
 *      → 429 on throttle (event: KeystoneRateLimitExceeded, windowType: 'per_day')
 *
 *   5. **Scope enforcement** — verifies the key carries all scopes declared as
 *      middleware parameters (`api.key:read,write`).
 *      → 401 on failure (event reason: 'insufficient_scope')
 *
 *   6. **Owner binding** — resolves the polymorphic `keystoneable` owner and binds
 *      it into the IoC container + request attributes so route handlers access it
 *      without an additional DB query.
 *      → 401 if owner cannot be resolved (event reason: 'missing_owner')
 *
 *   7. **Auth guard login** — optionally calls `Auth::guard()->setUser($owner)` for
 *      the guard(s) in `keystone.guard`, enabling `auth()->user()` in controllers.
 *
 * After the response is sent (in `terminate()`), the cache entry is optionally
 * re-warmed to reset its TTL — adding zero latency to the actual request.
 *
 * Middleware alias: `api.key`
 *
 * Usage:
 *   Route::middleware('api.key')->...
 *   Route::middleware('api.key:read,write')->...  // scope enforcement
 */
final class AuthenticateWithKeystone
{
    public function __construct(
        private readonly KeystoneServiceContract $service,
        private readonly KeystoneKeyCacheRepository $cache,
        private readonly RateLimitStrategy $rateLimitStrategy,
    ) {}

    /**
     * Run the complete authentication and authorization pipeline for an incoming request.
     *
     * @param  Closure(Request): Response  $next
     * @param  string  ...$scopes  Optional required scopes passed as middleware parameters.
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        // ── Step 1: Credential resolution ─────────────────────────────────────
        $rawKey = $request->header(
            is_string($h = config('keystone.header', 'X-Client-Id')) ? $h : 'X-Client-Id'
        ) ?? $request->query(
            is_string($q = config('keystone.query_param', 'client')) ? $q : 'client'
        );

        $sigAvailable = $request->header(
            is_string($sh = config('keystone.signature_header', 'X-API-Signature')) ? $sh : 'X-API-Signature'
        ) ?? $request->query(
            is_string($sq = config('keystone.signature_query_param', 'signature')) ? $sq : 'signature'
        );

        if (! is_string($rawKey) || $rawKey === '' || ! is_string($sigAvailable) || $sigAvailable === '') {
            event(new KeystoneAuthFailed($request, 'missing_credentials'));

            return $this->unauthorized('Unauthorized.');
        }

        $client = $this->service->resolve($request);

        if ($client === null) {
            event(new KeystoneAuthFailed($request, 'invalid_credentials'));

            return $this->unauthorized('Unauthorized.');
        }

        // ── Step 2: IP filtering ───────────────────────────────────────────────
        $ip = $request->ip();

        if (! is_null($ip)) {
            $allowlist = $client->ip_allowlist ?? [];

            if ($allowlist !== [] && ! IpUtils::checkIp($ip, $allowlist)) {
                event(new KeystoneAuthFailed($request, 'ip_not_allowed'));

                return $this->forbidden('IP address not allowed.');
            }

            $blocklist = $client->ip_blocklist ?? [];

            if ($blocklist !== [] && IpUtils::checkIp($ip, $blocklist)) {
                event(new KeystoneAuthFailed($request, 'ip_blocked'));

                return $this->forbidden('IP address blocked.');
            }
        }

        // ── Step 3: Per-minute rate limiting ───────────────────────────────────
        $tenantSegment = $this->cache->tenantSegment();
        $configWindow  = config('keystone.rate_limit_window_seconds', 60);
        $windowSeconds = is_numeric($configWindow) ? (int) $configWindow : 60;

        $effectiveLimit = $this->resolveEffectiveRateLimit($client);
        $limitKey       = null;

        if ($effectiveLimit > 0) {
            $limitKey = 'keystone:rate_limit:' . $tenantSegment . $client->id;

            if (! $this->rateLimitStrategy->attempt($limitKey, $effectiveLimit, $windowSeconds)) {
                $retryAfter = $this->rateLimitStrategy->retryAfter($limitKey);

                event(new KeystoneRateLimitExceeded($client, $request, $effectiveLimit, $retryAfter, 'per_minute'));

                return $this->tooManyRequests($limitKey, $effectiveLimit, $retryAfter);
            }
        }

        // ── Step 4: Per-day rate limiting ──────────────────────────────────────
        $dailyLimit = $this->resolveDaily($client);

        if ($dailyLimit > 0) {
            $dailyKey = 'keystone:rate_limit_daily:' . $tenantSegment . $client->id;

            if (! $this->rateLimitStrategy->attempt($dailyKey, $dailyLimit, 86400)) {
                $retryAfter = $this->rateLimitStrategy->retryAfter($dailyKey);

                event(new KeystoneRateLimitExceeded($client, $request, $dailyLimit, $retryAfter, 'per_day'));

                return $this->tooManyRequests($dailyKey, $dailyLimit, $retryAfter);
            }
        }

        // ── Step 5: Scope enforcement ──────────────────────────────────────────
        if ($scopes !== []) {
            $keyScopes = $client->scopes ?? [];

            foreach ($scopes as $required) {
                if (! in_array($required, $keyScopes, true)) {
                    event(new KeystoneAuthFailed($request, 'insufficient_scope'));

                    return $this->unauthorized('Insufficient scope.');
                }
            }
        }

        // ── Step 6: Owner binding ──────────────────────────────────────────────
        $owner = $client->keystoneable;

        if (! $owner instanceof \Illuminate\Database\Eloquent\Model) {
            event(new KeystoneAuthFailed($request, 'missing_owner'));

            return $this->unauthorized('Unauthorized.');
        }

        app()->instance($owner::class, $owner);
        $request->attributes->set('keystoneable', $owner);

        // ── Step 7: Auth guard login ───────────────────────────────────────────
        $guardsConfig = config('keystone.guard');

        if ($guardsConfig !== null && $owner instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            $guards = is_array($guardsConfig)
                ? $guardsConfig
                : (is_string($guardsConfig) ? explode(',', $guardsConfig) : []);

            foreach ($guards as $guard) {
                if (is_string($guard) || is_numeric($guard)) {
                    $guardStr = trim((string) $guard);

                    if ($guardStr !== '') {
                        try {
                            Auth::guard($guardStr)->setUser($owner);
                        } catch (\InvalidArgumentException) {
                            // Guard does not exist — ignore and continue
                        }
                    }
                }
            }
        }

        // Stash the resolved Keystone for use in terminate() and VerifyKeystonePayload
        $request->attributes->set('_keystone_client', $client);

        // Dispatch success event for access logging and analytics
        event(new KeystoneAuthenticated($client, $request));

        /** @var Response $response */
        $response = $next($request);

        // Append rate-limit headers to the outgoing response
        if ($limitKey !== null) {
            $response->headers->set('X-Keystone-RateLimit-Limit',     (string) $effectiveLimit);
            $response->headers->set('X-Keystone-RateLimit-Remaining', (string) $this->rateLimitStrategy->remaining($limitKey, $effectiveLimit, $windowSeconds));
        }

        return $response;
    }

    /**
     * Optionally re-warm the Redis cache entry after the response is sent.
     *
     * Running this in terminate() (which executes after the response is
     * delivered to the client) means cache refreshes add zero latency to the
     * actual request — the user gets their response immediately while the cache
     * write happens in the background teardown phase.
     */
    public function terminate(Request $request, Response $response): void
    {
        $client = $request->attributes->get('_keystone_client');

        if (! $client instanceof Keystone) {
            return;
        }

        if (config('keystone.cache.refresh_on_use', true)) {
            $this->cache->put($client);
        }
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Determine the effective per-minute rate limit for a key by combining the
     * key's own rate limit, the global config default, and any scope-specific caps.
     *
     * The most restrictive (lowest non-zero) limit wins.
     */
    private function resolveEffectiveRateLimit(Keystone $client): int
    {
        $globalLimit = config('keystone.rate_limit');
        $keyLimit    = $client->rate_limit ?? (is_numeric($globalLimit) ? (int) $globalLimit : 0);

        // Apply scope-based rate limit overrides (config: keystone.scopes_rate_limits)
        $scopeLimits = config('keystone.scopes_rate_limits', []);

        if (is_array($scopeLimits) && $scopeLimits !== [] && $client->scopes !== null) {
            foreach ($client->scopes as $scope) {
                if (isset($scopeLimits[$scope]) && is_numeric($scopeLimits[$scope])) {
                    $scopeLimit = (int) $scopeLimits[$scope];

                    if ($scopeLimit > 0) {
                        $keyLimit = $keyLimit > 0 ? min($keyLimit, $scopeLimit) : $scopeLimit;
                    }
                }
            }
        }

        return max(0, (int) $keyLimit);
    }

    /**
     * Determine the per-day rate limit for a key.
     * Falls back to the global `rate_limit_daily` config; 0 means no daily cap.
     */
    private function resolveDaily(Keystone $client): int
    {
        $daily = config('keystone.rate_limit_daily');

        return is_numeric($daily) && (int) $daily > 0 ? (int) $daily : 0;
    }

    /** Build a 401 Unauthorized JSON response. */
    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }

    /** Build a 403 Forbidden JSON response. */
    private function forbidden(string $message): Response
    {
        return response()->json(['message' => $message], 403);
    }

    /**
     * Build a 429 Too Many Requests response with standard rate-limit headers.
     * Includes Retry-After so clients know exactly when to try again.
     */
    private function tooManyRequests(string $limitKey, int $limit, int $retryAfter): Response
    {
        return response()->json(['message' => 'Too many requests.'], 429, [
            'Retry-After'                    => (string) $retryAfter,
            'X-Keystone-RateLimit-Limit'     => (string) $limit,
            'X-Keystone-RateLimit-Remaining' => '0',
            'X-Keystone-RateLimit-Reset'     => (string) (time() + $retryAfter),
        ]);
    }
}
