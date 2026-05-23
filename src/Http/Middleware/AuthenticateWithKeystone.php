<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Schatzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schatzie\Keystone\Models\Keystone;
use Schatzie\Keystone\Services\KeystoneService;
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
    public function __construct(
        private readonly KeystoneService $service,
        private readonly KeystoneKeyCacheRepository $cache,
    ) {}

    /**
     * @param  string  ...$scopes  Optional required scopes passed as middleware parameters
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $client = $this->service->resolve($request);

        if ($client === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
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

        // Optional auth guard login
        $guard = config('keystone.guard');

        if (is_string($guard) && $guard !== '') {
            if ($owner instanceof \Illuminate\Contracts\Auth\Authenticatable) {
                Auth::guard($guard)->setUser($owner);
            }
        }

        // Stash the resolved key for use in terminate()
        $request->attributes->set('_keystone_client', $client);

        return $next($request);
    }

    /**
     * Runs after the response is sent.
     * Writes usage metadata to the DB and re-warms the Redis entry.
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
