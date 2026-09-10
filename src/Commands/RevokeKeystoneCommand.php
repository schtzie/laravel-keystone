<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schtzie\Keystone\Commands\Traits\HasTenantOption;
use Schtzie\Keystone\Models\Keystone;

/**
 * Artisan command to soft-revoke an API key by its plain client identifier.
 *
 * Revocation stamps `revoked_at` on the database record and immediately evicts
 * the corresponding Redis cache entry, so the key is rejected on the very next
 * request with zero propagation delay — no cache TTL to wait out.
 *
 * This command is useful for emergency key invalidation (e.g. a leaked secret)
 * where you need to act fast from the command line rather than through a UI.
 *
 * Usage:
 *   php artisan keystone:revoke ks_abc123…
 *   php artisan keystone:revoke ks_abc123… --force   (skip confirmation prompt)
 */
final class RevokeKeystoneCommand extends Command
{
    use HasTenantOption;

    /** @var string */
    protected $signature = 'keystone:revoke
        {--tenant= : The ID of the tenant database to execute within}
        {client : The plain client identifier of the key to revoke (e.g. ks_abc123…)}
        {--force : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Immediately revoke an API key by its client identifier, evicting it from cache.';

    /**
     * Locate the key, confirm with the operator, and call revoke() to stamp
     * `revoked_at` and evict the cache entry atomically.
     */
    public function handle(): int
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        $clientArg = $this->argument('client');
        $client = is_string($clientArg) ? $clientArg : '';

        /** @var Keystone|null $keystone */
        $keystone = $modelClass::where('client', $client)->first();

        if ($keystone === null) {
            $this->error("No Keystone found with client identifier [{$client}].");

            return self::FAILURE;
        }

        if ($keystone->revoked_at !== null) {
            $this->warn("Key [{$client}] is already revoked (revoked at: {$keystone->revoked_at->toDateTimeString()}).");

            return self::SUCCESS;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Key ID',   $keystone->id],
                ['Name',     $keystone->name],
                ['Client',   $keystone->client],
                ['Owner',    "{$keystone->keystoneable_type}#{$keystone->keystoneable_id}"],
                ['Created',  $keystone->created_at->toDateTimeString()],
            ],
        );

        if (! $this->option('force') && ! $this->confirm('Revoke this key? This action cannot be undone.')) {
            $this->info('Revocation cancelled.');

            return self::SUCCESS;
        }

        $keystone->revoke();

        $this->newLine();
        $this->line("<fg=green;options=bold>✓ Key [{$client}] has been revoked and evicted from cache.</>");
        $this->newLine();

        return self::SUCCESS;
    }
}
