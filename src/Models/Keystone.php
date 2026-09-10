<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Schtzie\Keystone\Events\KeystoneRevoked;
use Schtzie\Keystone\Tenancy\Concerns\TenantAware;

/**
 * Eloquent model representing a single API key pair (client ID + HMAC secret).
 *
 * Each Keystone belongs to exactly one owner model (a User, Organisation, etc.)
 * via a polymorphic morph-to relationship. The owner is bound to the IoC container
 * and to the request attributes on every successful authentication so that route
 * handlers can access it without an additional DB query.
 *
 * Key lifecycle:
 *   created → [active] → revoked OR expired → pruned (by keystone:prune)
 *
 * Rotation creates a new key while optionally keeping the old one alive for a
 * configurable grace period (`grace_expires_at`) to enable zero-downtime credential
 * rollovers — the new key can be deployed to consumers before the old one dies.
 *
 * @property int $id
 * @property string $keystoneable_type
 * @property int|string $keystoneable_id
 * @property string|null $tenant_id
 * @property string $name
 * @property string|null $description Extended notes beyond the short name.
 * @property string $client Plain public identifier sent by consumers.
 * @property string $secret Plain HMAC-SHA256 signing secret.
 * @property array<int,string>|null $scopes Allowed operation scopes, e.g. ['read','write'].
 * @property array<int,string>|null $ip_allowlist Only these IPs may use this key.
 * @property array<int,string>|null $ip_blocklist These IPs are always rejected.
 * @property int|null $rate_limit Per-minute request cap (null = global default).
 * @property array<string,mixed>|null $metadata Arbitrary key/value tags for filtering and auditing.
 * @property CarbonImmutable|null $expires_at Null means the key never expires.
 * @property CarbonImmutable|null $grace_expires_at A revoked key is still valid until this datetime.
 * @property CarbonImmutable|null $revoked_at Non-null stamps the key as soft-revoked.
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Model|null        $keystoneable
 *
 * @use TenantAware<self>
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
        'metadata' => 'array',
        'rate_limit' => 'integer',
        'expires_at' => 'immutable_datetime',
        'grace_expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    /**
     * Resolve the database table name from config, allowing host applications
     * to override the default `keystoneables` name without modifying this class.
     */
    public function getTable(): string
    {
        $table = config('keystone.table', 'keystoneables');

        return is_string($table) ? $table : 'keystoneables';
    }

    /**
     * The polymorphic owner — any Eloquent model that uses the HasKeystones trait.
     * May be a User, Organisation, Application, or any other domain entity.
     *
     * @return MorphTo<Model, $this>
     */
    public function keystoneable(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    /**
     * Filter to keys that are neither revoked nor expired.
     * Excludes grace-period keys (they have a revoked_at but are still valid).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(static function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Filter to keys that have not been revoked (may include grace-period keys).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotRevoked(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Filter to keys that have not passed their expiry date, or have no expiry set.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(static function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    // ── Domain Logic ──────────────────────────────────────────────────────────

    /**
     * Determine whether this key can authenticate requests right now.
     *
     * A key is valid if:
     *   1. It has not been revoked (revoked_at is null) — OR it has been revoked but
     *      is still within its grace period (grace_expires_at is in the future).
     *      Grace periods support zero-downtime key rotation.
     *   2. It has not passed its `expires_at` timestamp (or has no expiry set).
     */
    public function isValid(): bool
    {
        // Check revocation — but honour the grace period if one is active
        if ($this->revoked_at !== null) {
            if ($this->grace_expires_at === null || $this->grace_expires_at->isPast()) {
                return false;
            }
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Soft-revoke this key by stamping `revoked_at` with the current timestamp.
     *
     * This immediately invalidates the key for future requests (cache eviction
     * is handled separately by model observers in the service provider). Fires
     * the `KeystoneRevoked` event after a successful update so listeners can
     * trigger notifications, audit entries, or downstream de-registrations.
     *
     * Idempotent — calling revoke() on an already-revoked key is a no-op;
     * the original `revoked_at` timestamp is preserved.
     */
    public function revoke(): bool
    {
        if ($this->revoked_at !== null) {
            return true;
        }

        $result = $this->update(['revoked_at' => now()]);

        if ($result) {
            event(new KeystoneRevoked($this));
        }

        return $result;
    }

    /**
     * Verify that the given signature matches the expected HMAC-SHA256 value.
     *
     * The expected signature is computed as: hash_hmac('sha256', $client, $secret)
     * where `$client` is the plain public identifier and `$secret` is the stored
     * signing secret. Comparison is performed with `hash_equals()` to prevent
     * timing attacks that could leak the secret through response-time differences.
     */
    public function verifySignature(string $signature): bool
    {
        $expected = hash_hmac('sha256', $this->client, $this->secret);

        return hash_equals($expected, $signature);
    }
}
