<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\Services\KeystoneService;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates incoming requests using an Client + HMAC-SHA256 signature.
 *
 * Middleware alias: api.key
 *
 * Usage:
 *   Route::middleware('api.key')->...
 *   Route::middleware('api.key:read,write')->...  // scope enforcement
 *
 * Resolution order:
 *   1. Read client  from config('keystone.header')       header
 *                    or config('keystone.query_param')    query param
 *   2. Read signature from config('keystone.signature_header') header
 *   3. KeystoneService::resolve() → Redis → DB → HMAC verify → validity check
 *   4. Optional scope check
 *   5. Bind keystoneable owner into IoC + request attributes
 *   6. Optionally log in via auth guard
 *
 * Usage tracking (markUsed + cache re-warm) runs in terminate() after the
 * response is already sent, adding zero latency to API responses.
 */
final class AuthenticateWithKeystone
{
    /**
     * @param KeystoneService $service
     * @param KeystoneKeyCacheRepository $cache
     */
    public function __construct(
        private readonly KeystoneService $service,
        private readonly KeystoneKeyCacheRepository $cache,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): Response $next
     * @param string ...$scopes Optional required scopes passed as middleware parameters
     * @return Response
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $client = $this->service->resolve($request);

        if ($client === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $ip = $request->ip();
        if (! is_null($ip)) {
            $allowlist = $client->ip_allowlist ?? [];
            if ($allowlist !== [] && ! IpUtils::checkIp($ip, $allowlist)) {
                return response()->json(['message' => 'IP address not allowed.'], 403);
            }

            $blocklist = $client->ip_blocklist ?? [];
            if ($blocklist !== [] && IpUtils::checkIp($ip, $blocklist)) {
                return response()->json(['message' => 'IP address blocked.'], 403);
            }
        }

        $limitKey = null;
        $rateLimit = $client->rate_limit ?? config('keystone.rate_limit');

        if (is_numeric($rateLimit) && (int) $rateLimit > 0) {
            $rateLimit = (int) $rateLimit;
            $limitKey = 'keystone:rate_limit:'.$client->id;

            if (RateLimiter::tooManyAttempts($limitKey, $rateLimit)) {
                $retryAfter = RateLimiter::availableIn($limitKey);

                return response()->json(['message' => 'Too many requests.'], 429, [
                    'Retry-After' => (string) $retryAfter,
                    'X-Keystone-RateLimit-Limit' => (string) $rateLimit,
                    'X-Keystone-RateLimit-Remaining' => '0',
                    'X-Keystone-RateLimit-Reset' => (string) (time() + $retryAfter),
                ]);
            }

            RateLimiter::hit($limitKey, 60);
        }

        // Scope enforcement
        if ($scopes !== []) {
            $keyScopes = $client->scopes ?? [];

            foreach ($scopes as $required) {
                if (! in_array($required, $keyScopes, true)) {
                    return response()->json(['message' => 'Insufficient scope.'], 401);
                }
            }
        }

        // Bind the keystoneable owner
        $owner = $client->keystoneable;

        if (! $owner instanceof \Illuminate\Database\Eloquent\Model) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        app()->instance($owner::class, $owner);
        $request->attributes->set('keystoneable', $owner);

        // Optional auth guard login (supports string, array, or comma-separated guards)
        $guardsConfig = config('keystone.guard');

        if ($guardsConfig !== null && $owner instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            $guards = is_array($guardsConfig)
                ? $guardsConfig
                : (is_string($guardsConfig) ? explode(',', $guardsConfig) : []);

            foreach ($guards as $guard) {
                if (is_string($guard) || is_numeric($guard)) {
                    $guardStr = trim((string) $guard);
                    if ($guardStr !== '') {
                        Auth::guard($guardStr)->setUser($owner);
                    }
                }
            }
        }

        // Stash the resolved key for use in terminate()
        $request->attributes->set('_keystone_client', $client);

        /** @var Response $response */
        $response = $next($request);

        if (! is_null($limitKey)) {
            $response->headers->set('X-Keystone-RateLimit-Limit', (string) $rateLimit);
            $response->headers->set('X-Keystone-RateLimit-Remaining', (string) RateLimiter::retriesLeft($limitKey, $rateLimit));
        }

        return $response;
    }

    /**
     * Runs after the response is sent.
     * Writes usage metadata to the DB and re-warms the Redis entry.
     *
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function terminate(Request $request, Response $response): void
    {
        $client = $request->attributes->get('_keystone_client');

        if (! $client instanceof Keystone) {
            return;
        }

        $client->markUsed($request);

        if (config('keystone.cache.refresh_on_use', true)) {
            $this->cache->put($client);
        }
    }
}

