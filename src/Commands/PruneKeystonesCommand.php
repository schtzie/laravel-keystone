<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Models\Keystone;

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
    /** @var string */
    protected $signature = 'keystone:prune
        {--days= : Override the prune_revoked_after_days config value}';

    /** @var string */
    protected $description = 'Delete revoked Clients older than the configured retention period and evict their cache entries.';

    /**
     * Execute the console command.
     *
     * @param KeystoneKeyCacheRepository $cache
     * @return int
     */
    public function handle(KeystoneKeyCacheRepository $cache): int
    {
        $daysOption = $this->option('days');
        $configDays = config('keystone.prune_revoked_after_days', 30);
        $days = (int) (is_numeric($daysOption) ? $daysOption : (is_numeric($configDays) ? $configDays : 30));

        $cutoff = now()->subDays($days);

        $pruned = 0;

        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

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

        $this->info("Pruned {$pruned} revoked Client(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
