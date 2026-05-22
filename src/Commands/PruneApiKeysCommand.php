<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Models\ApiKey;

/**
 * Deletes revoked API keys that are older than the configured retention period
 * and evicts their Redis cache entries.
 *
 * Usage:
 *   php artisan keystone:prune
 *   php artisan keystone:prune --days=7
 */
final class PruneApiKeysCommand extends Command
{
    protected $signature = 'keystone:prune
        {--days= : Override the prune_revoked_after_days config value}';

    protected $description = 'Delete revoked API keys older than the configured retention period and evict their cache entries.';

    public function handle(ApiKeyCacheRepository $cache): int
    {
        $days = (int) ($this->option('days') ?? config('keystone.prune_revoked_after_days', 30));

        $cutoff = now()->subDays($days);

        $pruned = 0;

        ApiKey::whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->chunkById(200, function ($keys) use ($cache, &$pruned): void {
                foreach ($keys as $key) {
                    $cache->forget($key->api_key);
                    $key->deleteQuietly();
                    $pruned++;
                }
            });

        $this->info("Pruned {$pruned} revoked API key(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
