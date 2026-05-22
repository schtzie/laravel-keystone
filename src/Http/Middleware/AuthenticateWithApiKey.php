<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Models\ApiKey;
use Schatzie\Keystone\Services\ApiKeyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates incoming requests using an API key + HMAC-SHA256 signature.
 *
 * Middleware alias: api.key
 *
 * Usage:
 *   Route::middleware('api.key')->...
 *   Route::middleware('api.key:read,write')->...  // scope enforcement
 *
 * Resolution order:
 *   1. Read api_key  from config('keystone.header')       header
 *                    or config('keystone.query_param')    query param
 *   2. Read signature from config('keystone.signature_header') header
 *   3. ApiKeyService::resolve() → Redis → DB → HMAC verify → validity check
 *   4. Optional scope check
 *   5. Bind keystoneable owner into IoC + request attributes
 *   6. Optionally log in via auth guard
 *
 * Usage tracking (markUsed + cache re-warm) runs in terminate() after the
 * response is already sent, adding zero latency to API responses.
 */
final class AuthenticateWithApiKey
{
    public function __construct(
        private readonly ApiKeyService $service,
        private readonly ApiKeyCacheRepository $cache,
    ) {}

    /**
     * @param  string[]  $scopes  Optional required scopes passed as middleware parameters
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $apiKey = $this->service->resolve($request);

        if ($apiKey === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Scope enforcement
        if ($scopes !== []) {
            $keyScopes = $apiKey->scopes ?? [];

            foreach ($scopes as $required) {
                if (! in_array($required, $keyScopes, true)) {
                    return response()->json(['message' => 'Insufficient scope.'], 401);
                }
            }
        }

        // Bind the keystoneable owner
        $owner = $apiKey->keystoneable;
        app()->instance(get_class($owner), $owner);
        $request->attributes->set('keystoneable', $owner);

        // Optional auth guard login
        $guard = config('keystone.guard');

        if (is_string($guard) && $guard !== '') {
            Auth::guard($guard)->setUser($owner);
        }

        // Stash the resolved key for use in terminate()
        $request->attributes->set('_keystone_api_key', $apiKey);

        return $next($request);
    }

    /**
     * Runs after the response is sent.
     * Writes usage metadata to the DB and re-warms the Redis entry.
     */
    public function terminate(Request $request, Response $response): void
    {
        $apiKey = $request->attributes->get('_keystone_api_key');

        if (! $apiKey instanceof ApiKey) {
            return;
        }

        $apiKey->markUsed($request);

        if (config('keystone.cache.refresh_on_use', true)) {
            $this->cache->put($apiKey);
        }
    }
}
