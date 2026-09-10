<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\Models\KeystoneAccessLog;

/**
 * Artisan command to remove stale data from Keystone's database tables.
 *
 * Two independent pruning tasks are available and can be run together or
 * separately via flags:
 *
 *   1. **Revoked keys** (always runs unless --access-logs-only is set):
 *      Permanently deletes Keystone records whose `revoked_at` timestamp is
 *      older than `keystone.prune_revoked_after_days` (default: 30 days).
 *      Cache entries are evicted in batches before deletion to prevent stale
 *      Redis data accumulating after the rows are gone.
 *
 *   2. **Access log entries** (opt-in via --access-logs):
 *      Permanently deletes rows from `keystone_access_logs` older than
 *      `keystone.access_log.prune_after_days` (default: 90 days). Only
 *      runs when `keystone.access_log.enabled` is true.
 *
 * Usage:
 *   php artisan keystone:prune
 *   php artisan keystone:prune --days=7
 *   php artisan keystone:prune --access-logs
 *   php artisan keystone:prune --days=7 --access-logs
 *
 * Schedule recommended:
 *   $schedule->command('keystone:prune --access-logs')->daily();
 */
final class PruneKeystonesCommand extends Command
{
    /** @var string */
    protected $signature = 'keystone:prune
        {--days=         : Override the prune_revoked_after_days config value for revoked keys}
        {--access-logs   : Also prune access log entries older than access_log.prune_after_days}';

    /** @var string */
    protected $description = 'Delete old revoked API keys (and optionally old access log entries) and evict their cache entries.';

    /**
     * Execute the pruning operation — delete revoked keys (and optionally access
     * log rows) that have passed their configured retention period.
     */
    public function handle(KeystoneKeyCacheRepository $cache): int
    {
        $exitCode = self::SUCCESS;

        // ── Prune revoked keys ─────────────────────────────────────────────────
        $exitCode = $this->pruneRevokedKeys($cache) === self::FAILURE ? self::FAILURE : $exitCode;

        // ── Prune access logs (opt-in) ─────────────────────────────────────────
        if ($this->option('access-logs')) {
            $exitCode = $this->pruneAccessLogs() === self::FAILURE ? self::FAILURE : $exitCode;
        }

        return $exitCode;
    }

    /**
     * Delete revoked Keystone records that are older than the retention window.
     * Processes records in chunks to keep peak memory usage bounded.
     */
    private function pruneRevokedKeys(KeystoneKeyCacheRepository $cache): int
    {
        $daysOption = $this->option('days');
        $configDays = config('keystone.prune_revoked_after_days', 30);
        $days       = (int) (is_numeric($daysOption) ? $daysOption : (is_numeric($configDays) ? $configDays : 30));
        $cutoff     = now()->subDays($days);

        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);
        $pruned     = 0;

        $modelClass::whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->chunkById(500, function ($keys) use ($cache, $modelClass, &$pruned): void {
                /** @var array<int, string> $clients */
                $clients = array_values(array_filter($keys->pluck('client')->all(), 'is_string'));
                $cache->forgetMany($clients);

                $ids = $keys->pluck('id')->all();

                if ($ids !== []) {
                    $modelClass::whereIn('id', $ids)->delete();
                    $pruned += count($ids);
                }
            });

        $this->info("Pruned {$pruned} revoked API key(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    /**
     * Delete access log entries older than `keystone.access_log.prune_after_days`.
     * Only runs when access logging is enabled.
     */
    private function pruneAccessLogs(): int
    {
        if (! config('keystone.access_log.enabled', false)) {
            $this->warn('Access log pruning skipped — access logging is disabled (keystone.access_log.enabled = false).');

            return self::SUCCESS;
        }

        $configDays = config('keystone.access_log.prune_after_days', 90);
        $days       = is_numeric($configDays) ? (int) $configDays : 90;
        $cutoff     = now()->subDays($days);
        $pruned     = 0;

        try {
            KeystoneAccessLog::where('created_at', '<', $cutoff)
                ->chunkById(1000, function ($rows) use (&$pruned): void {
                    $ids = $rows->pluck('id')->all();

                    if ($ids !== []) {
                        KeystoneAccessLog::whereIn('id', $ids)->delete();
                        $pruned += count($ids);
                    }
                });

            $this->info("Pruned {$pruned} access log entry/entries older than {$days} day(s).");
        } catch (\Throwable $e) {
            $this->error('Failed to prune access logs: ' . $e->getMessage());
            $this->error('Ensure the keystone-migrations-access-logs migration has been run.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
