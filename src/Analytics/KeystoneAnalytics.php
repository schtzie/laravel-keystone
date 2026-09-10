<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Analytics;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\Models\KeystoneAccessLog;

/**
 * Provides aggregated usage analytics derived from the Keystone access log.
 *
 * All methods in this class query the `keystone_access_logs` table and therefore
 * require the access log migration to have been run AND `keystone.access_log.enabled`
 * to be true. Calling these methods when the table does not exist will throw a
 * QueryException — guard with `config('keystone.access_log.enabled')` at the call site.
 *
 * The class is registered as a singleton in the container and exposed via the
 * `KeystoneAnalytics` facade so it can be called statically anywhere in the app:
 *
 *   \Keystone\Analytics::dailyUsage($key, days: 7)
 *   \Keystone\Analytics::topKeys(limit: 10)
 *   \Keystone\Analytics::ownerUsage($user)
 *
 * Performance note:
 *   These queries run against a potentially large append-only table. For production
 *   workloads, consider scheduling analytics to run asynchronously and cache results,
 *   rather than calling them inline in a request handler.
 */
final class KeystoneAnalytics
{
    /**
     * Return a day-by-day breakdown of request counts for one API key.
     *
     * The result set groups entries by calendar date and event type, giving
     * operators a clear picture of both successful and failed request trends.
     *
     * @param  int  $days  Number of past days to include (default: 7).
     * @return EloquentCollection<int, KeystoneAccessLog>
     */
    public function dailyUsage(Keystone $key, int $days = 7): EloquentCollection
    {
        return KeystoneAccessLog::query()
            ->where('keystone_id', $key->id)
            ->withinDays($days)
            ->selectRaw('DATE(created_at) as date, event, COUNT(*) as count')
            ->groupByRaw('DATE(created_at), event')
            ->orderBy('date')
            ->get();
    }

    /**
     * Return the most-used API keys, ranked by total authenticated request count.
     *
     * Only 'authenticated' events are counted — rejected requests are excluded
     * so the ranking reflects genuine API consumers, not abuse attempts.
     *
     * @param  int  $limit  Maximum number of results to return (default: 10).
     * @return EloquentCollection<int, KeystoneAccessLog>
     */
    public function topKeys(int $limit = 10): EloquentCollection
    {
        return KeystoneAccessLog::query()
            ->authenticated()
            ->selectRaw('keystone_id, COUNT(*) as requests')
            ->groupBy('keystone_id')
            ->orderByDesc('requests')
            ->limit($limit)
            ->get();
    }

    /**
     * Return a day-by-day aggregated view of all API activity for a given
     * Eloquent model owner (e.g. a User or Organisation).
     *
     * Covers all Keystones belonging to the owner, aggregated together. Use
     * `dailyUsage()` for per-key granularity.
     *
     * @param  Model  $owner  Any model that uses the HasKeystones trait.
     * @return EloquentCollection<int, KeystoneAccessLog>
     */
    public function ownerUsage(Model $owner, int $days = 30): EloquentCollection
    {
        return KeystoneAccessLog::query()
            ->where('keystoneable_type', $owner::class)
            ->where('keystoneable_id', $owner->getKey())
            ->withinDays($days)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();
    }

    /**
     * Return a summary of rejection events for a given API key over the past N days.
     *
     * Groups by rejection reason to surface patterns like repeated HMAC failures
     * (possible credential theft) or frequent scope errors (misconfigured client).
     *
     * @return EloquentCollection<int, KeystoneAccessLog>
     */
    public function rejectionSummary(Keystone $key, int $days = 7): EloquentCollection
    {
        return KeystoneAccessLog::query()
            ->where('keystone_id', $key->id)
            ->rejected()
            ->withinDays($days)
            ->selectRaw('event, COUNT(*) as count')
            ->groupBy('event')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * Return the total number of authenticated requests across all keys for
     * a given period. Useful as a high-level API health metric.
     *
     * @param  int  $days  Lookback window in days (default: 30).
     */
    public function totalRequests(int $days = 30): int
    {
        return (int) KeystoneAccessLog::query()
            ->authenticated()
            ->withinDays($days)
            ->count();
    }
}
