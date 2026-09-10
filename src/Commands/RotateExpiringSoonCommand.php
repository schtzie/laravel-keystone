<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Illuminate\Console\Command;
use Schtzie\Keystone\Commands\Traits\HasTenantOption;
use Schtzie\Keystone\Models\Keystone;
use Throwable;

/**
 * Artisan command to proactively rotate API keys that are approaching expiry.
 *
 * Rather than waiting for a key to expire and break a consumer's integration,
 * this command identifies keys expiring within the configured time window and
 * automatically rotates them — revoking the old key and issuing a fresh one
 * that inherits all original settings (scopes, IP lists, rate limit, name).
 *
 * If `keystone.rotation_grace_seconds` is greater than zero, the old key
 * continues to be accepted for that many seconds after rotation, giving
 * consumers time to pick up the new credentials without a service interruption.
 *
 * Schedule this command via Laravel's task scheduler to run daily:
 *
 *   $schedule->command('keystone:rotate-expiring')->daily();
 *
 * Usage:
 *   php artisan keystone:rotate-expiring            # defaults to 7 days ahead
 *   php artisan keystone:rotate-expiring --days=14
 *   php artisan keystone:rotate-expiring --dry-run  # preview without making changes
 */
final class RotateExpiringSoonCommand extends Command
{
    use HasTenantOption;

    /** @var string */
    protected $signature = 'keystone:rotate-expiring
        {--tenant= : The ID of the tenant database to execute within}
        {--days=7    : Rotate keys expiring within this many days}
        {--dry-run   : Preview which keys would be rotated without making changes}';

    /** @var string */
    protected $description = 'Auto-rotate API keys expiring soon, issuing replacements before the old key becomes invalid.';

    /**
     * Identify expiring-soon keys, rotate each one, and report results.
     * In dry-run mode, only the list of affected keys is printed.
     */
    public function handle(): int
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        $days = max(1, (int) ($this->option('days') ?? 7));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->addDays($days);
        $rotated = 0;

        $keys = $modelClass::whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $cutoff)
            ->where('expires_at', '>', now())
            ->get();

        if ($keys->isEmpty()) {
            $this->info("No active keys expiring within {$days} day(s).");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] The following {$keys->count()} key(s) would be rotated:");
        } else {
            $this->info("Rotating {$keys->count()} key(s) expiring within {$days} day(s)…");
        }

        $rows = $keys->map(fn (Keystone $k): array => [
            $k->id,
            $k->name,
            mb_substr($k->client, 0, 20).'…',
            "{$k->keystoneable_type}#{$k->keystoneable_id}",
            $k->expires_at?->toDateTimeString() ?? '—',
        ])->all();

        $this->table(['ID', 'Name', 'Client (truncated)', 'Owner', 'Expires At'], $rows);

        if ($dryRun) {
            $this->newLine();
            $this->line('<fg=yellow>Dry run complete — no changes made.</>');

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            try {
                $owner = $key->keystoneable;

                if ($owner === null || ! method_exists($owner, 'rotateKeystone')) {
                    $this->warn("  Skipping key #{$key->id} — owner not found or lacks HasKeystones.");

                    continue;
                }

                /** @var array{client: string, secret: string, model: Keystone} $newResult */
                $newResult = $owner->rotateKeystone($key);

                $this->line("  <fg=green>✓</> Key #{$key->id} → New key #{$newResult['model']->id}");
                $rotated++;
            } catch (Throwable $e) {
                $this->error("  Failed to rotate key #{$key->id}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line("<fg=green;options=bold>✓ Rotated {$rotated} of {$keys->count()} key(s).</>");
        $this->newLine();

        return self::SUCCESS;
    }
}
