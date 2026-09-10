<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Events;

use Schtzie\Keystone\Models\Keystone;

/**
 * Fired after a Keystone has been soft-revoked (revoked_at stamped).
 *
 * Revocation is the primary mechanism for disabling a compromised or
 * decommissioned API key. The model's `revoked_at` timestamp is already set
 * when this event is dispatched, meaning the key will be rejected by the
 * middleware on the very next request.
 *
 * The cache entry is evicted synchronously before this event fires (see
 * Keystone::revoke()), so there is no window in which a revoked key can
 * still pass through a warm cache hit.
 *
 * Typical use-cases for listeners:
 *   - Alerting the key owner that one of their keys was revoked
 *   - Recording revocation in a security audit log
 *   - Triggering downstream de-registration in third-party services
 */
final class KeystoneRevoked
{
    /**
     * @param  Keystone  $keystone  The revoked Keystone model with revoked_at already populated.
     */
    public function __construct(
        public readonly Keystone $keystone,
    ) {}
}
