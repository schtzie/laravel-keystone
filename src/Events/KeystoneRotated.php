<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Events;

use Schtzie\Keystone\Models\Keystone;

/**
 * Fired after a key-rotation transaction completes successfully.
 *
 * Key rotation is the recommended way to cycle credentials without service
 * interruption. When `rotation_grace_seconds` is greater than zero, the old
 * key continues to be accepted until its `grace_expires_at` timestamp passes,
 * giving API consumers a safe migration window to update their configuration.
 *
 * Both the old and new Keystone records are included in this event:
 *   - `$oldKeystone` — the revoked (or grace-period) key, for cross-referencing audit logs
 *   - `$newKeystone` — the freshly created replacement key
 *
 * Typical use-cases for listeners:
 *   - Notifying the key owner that a rotation has taken place (with the new client ID)
 *   - Logging the rotation chain in an audit trail (old-id → new-id)
 *   - Triggering automated distribution of the new key to registered webhooks
 */
final class KeystoneRotated
{
    /**
     * @param  Keystone  $oldKeystone  The predecessor key — soft-revoked or in its grace period.
     * @param  Keystone  $newKeystone  The newly issued replacement key.
     */
    public function __construct(
        public readonly Keystone $oldKeystone,
        public readonly Keystone $newKeystone,
    ) {}
}
