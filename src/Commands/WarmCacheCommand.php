<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Models\Keystone;

/**
 * Artisan command to pre-warm the Redis cache with all active Keystone records.
 *
 * Normally the cache is populated lazily — a key is written to Redis the first
 * time it is seen on an incoming request (the "warm-on-miss" path). This command
 * proactively loads every active key into Redis in bulk, eliminating cold-cache
 * penalties after:
 *
 *   - A Redis flush or restart that wiped existing entries
 *   - A deployment where the cache was cleared as part of the release process
 *   - A new Redis cluster being provisioned for a blue/green switch
 *   - Predictable traffic spikes where you want zero DB round-trips from the start
 *
 * Records are streamed in chunks to avoid loading thousands of rows into memory
 * at once, making the command safe to run against very large key tables.
 *
 * Usage:
 *   php artisan keystone:warm
 *   php artisan keystone:warm --chunk=200
 */
final class WarmCacheCommand extends Command
{
    /** @var string */
    protected $signature = 'keystone:warm
        {--chunk=500 : Number of records to load per database batch}';

    /** @var string */
    protected $description = 'Pre-warm the Redis cache with all active API keys, eliminating cold-cache DB round-trips.';

    public function __construct(private readonly KeystoneKeyCacheRepository $cache)
    {
        parent::__construct();
    }

    /**
     * Iterate all non-revoked, non-expired Keystones in chunks and write each
     * one to the cache, reporting progress as we go.
     */
    public function handle(): int
    {
        if (! config('keystone.cache.enabled', true)) {
            $this->warn('Keystone cache is disabled (keystone.cache.enabled = false). Nothing to warm.');

            return self::SUCCESS;
        }

        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        $chunk = max(1, (int) ($this->option('chunk') ?? 500));
        $total = 0;

        $this->info('Warming Keystone cache…');
        $bar = $this->output->createProgressBar(
            $modelClass::whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count()
        );
        $bar->start();

        $modelClass::whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->chunkById($chunk, function ($keys) use ($bar, &$total): void {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Keystone> $keys */
                foreach ($keys as $key) {
                    $this->cache->put($key);
                    $total++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->line("<fg=green;options=bold>✓ Warmed {$total} key(s) into cache.</>");
        $this->newLine();

        return self::SUCCESS;
    }
}
