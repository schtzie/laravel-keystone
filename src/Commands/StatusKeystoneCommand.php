<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schtzie\Keystone\Commands\Traits\HasTenantOption;
use Schtzie\Keystone\Models\Keystone;
use Throwable;

/**
 * Artisan command to display a health and statistics summary for Keystone.
 *
 * Provides a quick at-a-glance view of the system's current state — useful
 * for debugging, capacity planning, and post-deployment verification:
 *
 *   - Total keys in the database broken down by status (active / revoked / expired)
 *   - Cache configuration (store, TTL, prefix)
 *   - Key distribution (keys with and without rate limits, with scopes, with IP filters)
 *   - Access log status (enabled/disabled, row count if enabled)
 *
 * Usage:
 *   php artisan keystone:status
 */
final class StatusKeystoneCommand extends Command
{
    use HasTenantOption;

    /** @var string */
    protected $signature = 'keystone:status {--tenant= : The ID of the tenant database to execute within}';

    /** @var string */
    protected $description = 'Display a summary of Keystone configuration, key counts, and cache status.';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Gather statistics from the database and config and render a status report.
     */
    public function handle(): int
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        $total = $modelClass::count();
        $revoked = $modelClass::whereNotNull('revoked_at')->count();
        $expired = $modelClass::whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();
        $active = $total - $revoked - $expired;

        $withRateLimit = $modelClass::whereNotNull('rate_limit')->count();
        $withScopes = $modelClass::whereNotNull('scopes')->count();
        $withAllowlist = $modelClass::whereNotNull('ip_allowlist')->count();
        $withBlocklist = $modelClass::whereNotNull('ip_blocklist')->count();

        $this->newLine();
        $this->line('<fg=blue;options=bold>── Keystone Status ──────────────────────────────</> ');
        $this->newLine();

        // Key counts
        $this->line('<fg=white;options=bold>Database</> ');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total keys',           $total],
                ['Active keys',          "<fg=green>{$active}</>"],
                ['Revoked keys',         "<fg=red>{$revoked}</>"],
                ['Expired keys',         "<fg=yellow>{$expired}</>"],
                ['Keys with rate limit', $withRateLimit],
                ['Keys with scopes',     $withScopes],
                ['Keys with IP allow',   $withAllowlist],
                ['Keys with IP block',   $withBlocklist],
            ],
        );

        // Cache configuration
        $this->line('<fg=white;options=bold>Cache</> ');
        $cacheEnabled = config('keystone.cache.enabled', true) ? '<fg=green>enabled</>' : '<fg=red>disabled</>';
        $this->table(
            ['Setting', 'Value'],
            [
                ['Status',   $cacheEnabled],
                ['Store',    config('keystone.cache.store', 'redis')],
                ['TTL',      ($ttl = config('keystone.cache.ttl')) !== null && is_scalar($ttl) ? ((string) $ttl).'s' : 'none'],

                ['Prefix',   config('keystone.cache.prefix', 'keystone')],
                ['Strategy', config('keystone.rate_limit_strategy', 'fixed_window')],
            ],
        );

        // Access log
        $this->line('<fg=white;options=bold>Access Log</> ');
        $logEnabled = config('keystone.access_log.enabled', false);
        $logStatus = $logEnabled ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>';
        $logRows = [];

        if ($logEnabled) {
            try {
                $logRows[] = ['Log rows (total)', \Schtzie\Keystone\Models\KeystoneAccessLog::count()];
            } catch (Throwable) {
                $logRows[] = ['Log rows (total)', '<fg=red>table not found</>'];
            }
        }

        $this->table(
            ['Setting', 'Value'],
            array_merge(
                [['Status', $logStatus], ['Driver', config('keystone.access_log.driver', 'database')]],
                $logRows,
            ),
        );

        $this->newLine();

        return self::SUCCESS;
    }
}
