<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schatzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schatzie\Keystone\Models\Keystone;

/**
 * Deletes revoked Clients that are older than the configured retention period
 * and evicts their Redis cache entries.
 *
 * Usage:
 *   php artisan keystone:prune
 *   php artisan keystone:prune --days=7
 */
final class PruneKeystonesCommand extends Command
{
    protected $signature = 'keystone:prune
        {--days= : Override the prune_revoked_after_days config value}';

    protected $description = 'Delete revoked Clients older than the configured retention period and evict their cache entries.';

    public function handle(KeystoneKeyCacheRepository $cache): int
    {
        $days = (int) ($this->option('days') ?? config('keystone.prune_revoked_after_days', 30));

        $cutoff = now()->subDays($days);

        $pruned = 0;

        Keystone::whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->chunkById(200, function ($keys) use ($cache, &$pruned): void {
                foreach ($keys as $key) {
                    $cache->forget($key->client);
                    $key->deleteQuietly();
                    $pruned++;
                }
            });

        $this->info("Pruned {$pruned} revoked Client(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
