<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Facades;

use Illuminate\Support\Facades\Facade;
use Schtzie\Keystone\Analytics\KeystoneAnalytics as KeystoneAnalyticsService;

/**
 * Facade providing static access to the KeystoneAnalytics service.
 *
 * All methods proxy to {@see KeystoneAnalyticsService}, which queries the
 * `keystone_access_logs` table. The access log migration must have been run
 * and `keystone.access_log.enabled` must be true for data to be present.
 *
 * @method static \Illuminate\Support\Collection<int, mixed> dailyUsage(\Schtzie\Keystone\Models\Keystone $key, int $days = 7)
 * @method static \Illuminate\Support\Collection<int, mixed> topKeys(int $limit = 10)
 * @method static \Illuminate\Support\Collection<int, mixed> ownerUsage(\Illuminate\Database\Eloquent\Model $owner, int $days = 30)
 * @method static \Illuminate\Support\Collection<int, mixed> rejectionSummary(\Schtzie\Keystone\Models\Keystone $key, int $days = 7)
 * @method static int totalRequests(int $days = 30)
 *
 * @see KeystoneAnalyticsService
 */
final class KeystoneAnalytics extends Facade
{
    /**
     * Return the container binding key for the underlying analytics service.
     */
    protected static function getFacadeAccessor(): string
    {
        return KeystoneAnalyticsService::class;
    }
}
