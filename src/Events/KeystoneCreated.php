<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Events;

use Schtzie\Keystone\Models\Keystone;

/**
 * Fired immediately after a new API key pair is persisted to the database.
 *
 * This event is the earliest hook in the key-creation lifecycle. It carries
 * the plain-text client ID and secret — the only moment in time those values
 * are available in memory together. Listeners must treat them as sensitive
 * credentials: log sparingly, never broadcast to the front-end, and never
 * persist the secret a second time unless it is encrypted.
 *
 * Typical use-cases for listeners:
 *   - Sending a "new API key created" notification to the owner
 *   - Auditing key-creation events in an external SIEM/logging service
 *   - Triggering post-creation webhooks in an integration platform
 */
final class KeystoneCreated
{
    /**
     * @param  Keystone  $keystone     The fully persisted Keystone model (secret stored as plain text in DB).
     * @param  string    $plainClient  The plain client identifier handed to the API consumer (e.g. "ks_abc…").
     * @param  string    $plainSecret  The plain HMAC-signing secret — shown once, then impossible to recover.
     */
    public function __construct(
        public readonly Keystone $keystone,
        public readonly string $plainClient,
        public readonly string $plainSecret,
    ) {}
}
