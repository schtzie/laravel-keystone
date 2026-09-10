<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Schtzie\Keystone\Events\KeystoneAuthenticated;
use Schtzie\Keystone\Events\KeystoneAuthFailed;
use Schtzie\Keystone\Events\KeystoneRateLimitExceeded;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\Models\KeystoneAccessLog;
use Throwable;

/**
 * Listens to Keystone authentication events and writes structured access log
 * entries to the configured driver(s) — database, log channel, or both.
 *
 * This class is registered as an event listener in the service provider when
 * `keystone.access_log.enabled` is true. It is intentionally kept thin: all
 * business logic lives in the event classes; this class only performs I/O.
 *
 * Supported drivers (configurable via `keystone.access_log.driver`):
 *   - 'database'  — Inserts a row into `keystone_access_logs` via Eloquent.
 *   - 'log'       — Writes a structured JSON entry to a named log channel
 *                   (`keystone.access_log.channel`, defaults to the app default).
 *   - 'both'      — Performs both database insert and log channel write.
 *
 * Each listener method is idempotent and silent on failure — a broken log
 * write should never prevent a legitimate API request from being served.
 */
final class KeystoneAccessLogger
{
    /**
     * Handle a successful authentication event.
     *
     * Records which key authenticated, from which IP, against which route,
     * and which scopes were presented — the core dataset for usage analytics.
     */
    public function handleAuthenticated(KeystoneAuthenticated $event): void
    {
        if (! config('keystone.access_log.enabled', false)) {
            return;
        }

        $this->log(
            keystone: $event->keystone,
            request: $event->request,
            event: 'authenticated',
            statusCode: 200,
        );
    }

    /**
     * Handle a failed authentication event.
     *
     * The `$reason` string in the event identifies exactly which guard check
     * failed, enabling fine-grained anomaly detection (e.g. spike in
     * 'invalid_credentials' can indicate credential-stuffing attacks).
     */
    public function handleAuthFailed(KeystoneAuthFailed $event): void
    {
        if (! config('keystone.access_log.enabled', false)) {
            return;
        }

        $statusCode = match ($event->reason) {
            'ip_not_allowed', 'ip_blocked', 'insufficient_scope' => 403,
            default => 401,
        };

        $this->log(
            keystone: null,
            request: $event->request,
            event: $this->reasonToEvent($event->reason),
            statusCode: $statusCode,
        );
    }

    /**
     * Handle a rate-limit-exceeded event.
     *
     * Logs the 429 occurrence along with the window type that was breached
     * so operators can distinguish between per-minute and per-day throttling.
     */
    public function handleRateLimitExceeded(KeystoneRateLimitExceeded $event): void
    {
        if (! config('keystone.access_log.enabled', false)) {
            return;
        }

        $this->log(
            keystone: $event->keystone,
            request: $event->request,
            event: 'rate_limited',
            statusCode: 429,
        );
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Write a log entry to the configured driver(s).
     *
     * Wrapped in a try/catch so that any storage failure is silently swallowed
     * rather than propagated up to the request/response cycle.
     */
    private function log(?Keystone $keystone, Request $request, string $event, int $statusCode): void
    {
        try {
            $payload = $this->buildPayload($keystone, $request, $event, $statusCode);
            $driver = config('keystone.access_log.driver', 'database');

            if ($driver === 'database' || $driver === 'both') {
                $this->writeToDatabase($payload);
            }

            if ($driver === 'log' || $driver === 'both') {
                $this->writeToLogChannel($event, $payload);
            }
        } catch (Throwable) {
            // Access logging must never interrupt the request lifecycle.
        }
    }

    /**
     * Build the canonical payload array shared between database and log writers.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(?Keystone $keystone, Request $request, string $event, int $statusCode): array
    {
        $tenantSegment = '';
        if (config('keystone.tenancy.mode') !== 'none' && function_exists('tenant')) {
            /** @var mixed $tenant */
            $tenant = tenant();
            if (is_object($tenant) && method_exists($tenant, 'getTenantKey')) {
                /** @var mixed $tenantKey */
                $tenantKey = $tenant->getTenantKey();
                $tenantSegment = is_scalar($tenantKey) ? (string) $tenantKey : '';
            }
        }

        return [
            'keystone_id' => $keystone?->id,
            'keystoneable_type' => $keystone?->keystoneable_type,
            'keystoneable_id' => $keystone?->keystoneable_id,
            'tenant_id' => $tenantSegment !== '' ? $tenantSegment : null,
            'ip_address' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
            'scopes_used' => $keystone?->scopes,
            'status_code' => $statusCode,
            'event' => $event,
        ];
    }

    /**
     * Insert one row into the `keystone_access_logs` table.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeToDatabase(array $payload): void
    {
        KeystoneAccessLog::create($payload);
    }

    /**
     * Write a structured JSON entry to the configured log channel.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeToLogChannel(string $event, array $payload): void
    {
        $channel = config('keystone.access_log.channel');
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = is_string($channel) ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->info("keystone.{$event}", $payload);
    }

    /**
     * Map a KeystoneAuthFailed reason string to an access-log event name.
     */
    private function reasonToEvent(string $reason): string
    {
        return match ($reason) {
            'ip_not_allowed', 'ip_blocked' => 'rejected_ip',
            'insufficient_scope' => 'rejected_scope',
            'replay_detected' => 'rejected_replay',
            default => 'rejected_invalid',
        };
    }
}
