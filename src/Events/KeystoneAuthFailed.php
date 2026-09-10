<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Events;

use Illuminate\Http\Request;

/**
 * Fired whenever the AuthenticateWithKeystone middleware rejects a request,
 * regardless of which specific guard check caused the rejection.
 *
 * The `$reason` string identifies the exact failure point so that listeners
 * can triage security incidents, populate audit logs, and surface targeted
 * error metrics in dashboards without needing to parse HTTP response codes.
 *
 * Possible reason values and their semantics:
 *   - 'missing_credentials'  — No client ID or signature was supplied
 *   - 'invalid_credentials'  — Client not found, revoked, or expired; HMAC mismatch
 *   - 'replay_detected'      — The X-Timestamp header falls outside the replay window
 *   - 'ip_not_allowed'       — Request IP not present in the key's allowlist
 *   - 'ip_blocked'           — Request IP is present in the key's blocklist
 *   - 'insufficient_scope'   — Key does not possess all scopes required by the route
 *   - 'missing_owner'        — The polymorphic owner relation resolved to null (data integrity issue)
 *
 * Typical use-cases for listeners:
 *   - Incrementing per-key failure counters to detect brute-force probing
 *   - Alerting on repeated 'invalid_credentials' or 'replay_detected' events
 *   - Writing structured rejection logs for security forensics
 */
final class KeystoneAuthFailed
{
    /**
     * @param  Request  $request  The incoming HTTP request that was rejected.
     * @param  string   $reason   A machine-readable identifier for the specific failure point.
     */
    public function __construct(
        public readonly Request $request,
        public readonly string $reason,
    ) {}
}
