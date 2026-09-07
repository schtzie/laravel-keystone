<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Schtzie\Keystone\Tenancy\Concerns\TenantAware;

/**
 * @property int $id
 * @property string $keystoneable_type
 * @property int|string $keystoneable_id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $client
 * @property string $secret
 * @property array<string>|null $scopes
 * @property array<string>|null $ip_allowlist
 * @property array<string>|null $ip_blocklist
 * @property int|null $rate_limit
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_used_ip
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Keystone extends Model
{
    use TenantAware;

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = [
        'scopes' => 'array',
        'ip_allowlist' => 'array',
        'ip_blocklist' => 'array',
        'rate_limit' => 'integer',
        'expires_at' => 'immutable_datetime',
        'last_used_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return (string) config('keystone.table', 'keystoneables');
    }

    // ── Relationships ──────────────────────────────────────────────────────

    /**
     * Polymorphic owner — any model using the HasKeystones trait.
     *
     * @return MorphTo<Model, $this>
     */
    public function keystoneable(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Query Scopes ───────────────────────────────────────────────────────

    /**
     * Keys that are neither revoked nor expired.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(static function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Keys that have not been revoked.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeNotRevoked(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Keys that have not passed their expiry date (or have no expiry).
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(static function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Returns true if the key is not revoked and not expired.
     */
    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Soft-revoke this key.
     * Fires the Eloquent `updated` event which triggers cache invalidation.
     *
     * Idempotent — if the key is already revoked, this is a no-op and the
     * original revoked_at timestamp is preserved.
     */
    public function revoke(): bool
    {
        // Idempotent — if the key is already revoked, this is a no-op and the
        // original revoked_at timestamp is preserved.
        if ($this->revoked_at !== null) {
            return true;
        }

        return $this->update(['revoked_at' => now()]);
    }

    /**
     * Record usage metadata.
     * Called from middleware terminate() so it never adds request latency.
     */
    public function markUsed(Request $request): void
    {
        // updateQuietly suppresses events — we don't want markUsed to
        // trigger cache invalidation (the entry is still valid).
        $this->updateQuietly([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ]);
    }

    /**
     * Verify that the given signature matches hash_hmac('sha256', client, secret).
     * Uses hash_equals to prevent timing attacks.
     */
    public function verifySignature(string $signature): bool
    {
        $expected = hash_hmac('sha256', $this->client, $this->secret);

        return hash_equals($expected, $signature);
    }
}
