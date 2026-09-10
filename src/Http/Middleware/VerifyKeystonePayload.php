<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional middleware that verifies an HMAC-SHA256 hash of the request body.
 *
 * While the core `api.key` middleware already verifies the client identity via
 * an HMAC of the client key itself, that check only proves "who is making the
 * request" — it does not guarantee that the request body has not been tampered
 * with in transit. This middleware closes that gap.
 *
 * How it works:
 *   The client computes: hash_hmac('sha256', $requestBody, $secret)
 *   and sends the hex digest in the `X-Body-Hash` header (configurable via
 *   `keystone.body_signing.header`). This middleware re-computes the same hash
 *   server-side using the resolved key's secret and rejects any mismatch.
 *
 * Usage — apply AFTER `api.key` (which resolves the key and binds the owner):
 *   Route::middleware(['api.key', 'api.key.payload'])->post('/orders', ...)
 *
 * Empty bodies:
 *   An empty or missing request body is treated as an empty string for hashing
 *   purposes. If `keystone.body_signing.require_on_empty` is false (default),
 *   requests with no body skip the check entirely to simplify GET requests.
 *
 * Middleware alias: `api.key.payload`
 */
final class VerifyKeystonePayload
{
    /**
     * Verify the request body has not been tampered with by checking the
     * HMAC-SHA256 hash against the resolved API key's secret.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \Schtzie\Keystone\Models\Keystone|null $keystone */
        $keystone = $request->attributes->get('_keystone_client');

        if ($keystone === null) {
            // Keystone has not been resolved yet — this middleware must be
            // placed after `api.key` in the middleware stack.
            return response()->json([
                'message' => 'Payload verification requires the api.key middleware to run first.',
            ], 500);
        }

        $headerConfig = config('keystone.body_signing.header', 'X-Body-Hash');
        $header       = is_string($headerConfig) ? $headerConfig : 'X-Body-Hash';
        $providedHash = $request->header($header);

        $body = (string) $request->getContent();

        // Skip the check for requests with no body unless explicitly required
        if ($body === '' && ! config('keystone.body_signing.require_on_empty', false)) {
            return $next($request);
        }

        if (! is_string($providedHash) || $providedHash === '') {
            return response()->json([
                'message' => "Missing body hash header ({$header}).",
            ], 400);
        }

        $expected = hash_hmac('sha256', $body, $keystone->secret);

        if (! hash_equals($expected, $providedHash)) {
            return response()->json([
                'message' => 'Request body integrity check failed.',
            ], 400);
        }

        return $next($request);
    }
}
