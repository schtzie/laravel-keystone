<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Events;

use Illuminate\Http\Request;
use Schtzie\Keystone\Models\Keystone;

/**
 * Fired when an API key has exhausted its rate limit allowance and the
 * AuthenticateWithKeystone middleware is about to return a 429 response.
 *
 * This event is dispatched for every exceeded window type independently:
 *   - Per-minute window (standard rate limit)
 *   - Per-day window (daily cap, if configured via `rate_limit_daily`)
 *   - Scope-specific window (if `scopes_rate_limits` is configured)
 *
 * Typical use-cases for listeners:
 *   - Triggering real-time alerts when a key is being hammered unexpectedly
 *   - Auto-revoking keys that consistently abuse the rate limit
 *   - Surfacing throttling events in analytics dashboards for capacity planning
 *   - Notifying the key owner that they should request a higher quota
 */
final class KeystoneRateLimitExceeded
{
    /**
     * @param  Keystone  $keystone  The API key that hit the rate limit.
     * @param  Request  $request  The HTTP request that triggered the limit.
     * @param  int  $limit  The maximum number of requests allowed in the window.
     * @param  int  $retryAfter  Seconds remaining until the rate limit resets.
     * @param  string  $windowType  Which limit was breached: 'per_minute', 'per_day', or 'scope:{name}'.
     */
    public function __construct(
        public readonly Keystone $keystone,
        public readonly Request $request,
        public readonly int $limit,
        public readonly int $retryAfter,
        public readonly string $windowType = 'per_minute',
    ) {}
}
