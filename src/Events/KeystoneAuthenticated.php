<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Events;

use Illuminate\Http\Request;
use Schtzie\Keystone\Models\Keystone;

/**
 * Fired at the end of the AuthenticateWithKeystone middleware's happy path,
 * after all security checks have passed and the owner has been bound to the
 * request — but before the inner route handler is invoked.
 *
 * This event is the primary hook for:
 *   - Access logging: record every successful authenticated request for audit
 *     trails, usage analytics, and anomaly detection
 *   - Usage metering: count requests per key for billing or quota enforcement
 *   - Real-time monitoring: stream authenticated events to an observability
 *     platform (Datadog, New Relic, etc.)
 *
 * The full `Request` object is included so listeners can inspect headers,
 * route, method, IP address, and any other request attributes without
 * needing to rebind the container.
 */
final class KeystoneAuthenticated
{
    /**
     * @param  Keystone  $keystone  The authenticated API key that passed all validation checks.
     * @param  Request  $request  The current HTTP request associated with this authentication attempt.
     */
    public function __construct(
        public readonly Keystone $keystone,
        public readonly Request $request,
    ) {}
}
