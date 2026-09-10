<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model representing a single entry in the Keystone access log.
 *
 * Every authenticated request and every rejected authentication attempt can
 * be written to this table when `keystone.access_log.enabled` is true. The
 * log is append-only by design — rows are never updated, only inserted and
 * eventually pruned by `keystone:prune --access-logs`.
 *
 * Event taxonomy (stored in the `event` column):
 *   - 'authenticated'      — Request passed all checks and was accepted.
 *   - 'rejected_invalid'   — Key not found, already revoked, expired, or HMAC mismatch.
 *   - 'rejected_replay'    — Timestamp header fell outside the replay-protection window.
 *   - 'rejected_ip'        — IP address failed allowlist or blocklist checks.
 *   - 'rejected_scope'     — Key lacked a required scope declared on the route.
 *   - 'rate_limited'       — Key exceeded its per-minute, per-day, or scope rate limit.
 *
 * Performance notes:
 *   - `created_at` is indexed to support time-range queries efficiently.
 *   - `updated_at` is disabled (`UPDATED_AT = null`) since rows are never mutated.
 *   - For very high-traffic APIs consider using a dedicated log store or an
 *     async queue worker to absorb write spikes.
 *
 * @property int                    $id
 * @property int|null               $keystone_id
 * @property string|null            $keystoneable_type
 * @property int|string|null        $keystoneable_id
 * @property string|null            $tenant_id
 * @property string|null            $ip_address
 * @property string                 $method
 * @property string                 $path
 * @property array<int,string>|null $scopes_used
 * @property int                    $status_code
 * @property string                 $event
 * @property CarbonImmutable        $created_at
 * @property-read Keystone|null     $keystone
 */
class KeystoneAccessLog extends Model
{
    /**
     * Disable the `updated_at` timestamp — access log rows are write-once.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'keystone_id',
        'keystoneable_type',
        'keystoneable_id',
        'tenant_id',
        'ip_address',
        'method',
        'path',
        'scopes_used',
        'status_code',
        'event',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'scopes_used' => 'array',
        'status_code' => 'integer',
        'created_at'  => 'immutable_datetime',
    ];

    /**
     * Resolve the table name from config so it can be overridden by the host
     * application without modifying the model class.
     */
    public function getTable(): string
    {
        $table = config('keystone.access_log.table', 'keystone_access_logs');

        return is_string($table) ? $table : 'keystone_access_logs';
    }

    /**
     * The Keystone (API key) that this log entry is associated with.
     *
     * May be null when the access log captures a request that failed before
     * a valid key could be resolved (e.g. 'missing_credentials').
     *
     * @return BelongsTo<Keystone, $this>
     */
    public function keystone(): BelongsTo
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        return $this->belongsTo($modelClass, 'keystone_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    /**
     * Filter to only authenticated (successful) access log entries.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAuthenticated(Builder $query): Builder
    {
        return $query->where('event', 'authenticated');
    }

    /**
     * Filter to only rejected (failed authentication) entries.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('event', '!=', 'authenticated');
    }

    /**
     * Filter entries within a given time range.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithinDays(Builder $query, int $days): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
