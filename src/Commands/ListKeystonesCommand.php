<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Schtzie\Keystone\Commands\Traits\HasTenantOption;
use Schtzie\Keystone\Models\Keystone;

/**
 * Artisan command to list API keys stored in the database.
 *
 * Displays a formatted table with the most important fields for each key,
 * making it easy to audit what keys exist, who owns them, and what their
 * current status is — without needing to query the database manually or
 * build a UI.
 *
 * The client ID is shown truncated to keep the table readable in narrow
 * terminals; use the full ID with `keystone:revoke` when needed.
 *
 * Usage:
 *   php artisan keystone:list
 *   php artisan keystone:list --active
 *   php artisan keystone:list --revoked
 *   php artisan keystone:list --owner-type="App\Models\User" --owner-id=1
 *   php artisan keystone:list --limit=50
 */
final class ListKeystonesCommand extends Command
{
    use HasTenantOption;

    /** @var string */
    protected $signature = 'keystone:list
        {--tenant= : The ID of the tenant database to execute within}
        {--owner-type=  : Filter by polymorphic owner class (e.g. "App\\Models\\User")}
        {--owner-id=    : Filter by polymorphic owner primary key}
        {--active       : Show only active (not revoked, not expired) keys}
        {--revoked      : Show only revoked keys}
        {--limit=100    : Maximum number of rows to display}';

    /** @var string */
    protected $description = 'List API keys with their status, owner, scopes, and expiry details.';

    /**
     * Query and display a paginated, optionally filtered table of Keystone records.
     */
    public function handle(): int
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        $limit = max(1, (int) ($this->option('limit') ?? 100));

        $query = $modelClass::query()->latest()->limit($limit);

        // Owner filter
        if ($this->option('owner-type')) {
            $ownerType = $this->option('owner-type');
            $query->where('keystoneable_type', is_string($ownerType) ? $ownerType : '');
        }

        if ($this->option('owner-id')) {
            $query->where('keystoneable_id', $this->option('owner-id'));
        }

        // Status filter
        if ($this->option('active') && $this->option('revoked')) {
            $this->error('Cannot combine --active and --revoked flags.');

            return self::FAILURE;
        }

        if ($this->option('active')) {
            $query->scoped(fn (Builder $q) => $q->active()); /** @phpstan-ignore-line */
        } elseif ($this->option('revoked')) {
            $query->whereNotNull('revoked_at');
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Keystone> $keys */
        $keys = $query->get();

        if ($keys->isEmpty()) {
            $this->info('No keys found matching the given criteria.');

            return self::SUCCESS;
        }

        $rows = $keys->map(fn (Keystone $k): array => [
            $k->id,
            $k->name,
            mb_substr($k->client, 0, 20).'…',
            "{$k->keystoneable_type}#{$k->keystoneable_id}",
            implode(', ', $k->scopes ?? []) ?: '—',
            $k->expires_at?->toDateString() ?? '∞',
            $this->statusLabel($k),
        ])->all();

        $this->table(
            ['ID', 'Name', 'Client (truncated)', 'Owner', 'Scopes', 'Expires', 'Status'],
            $rows,
        );

        $this->line("<fg=gray>Showing {$keys->count()} key(s) (limit: {$limit}).</>");

        return self::SUCCESS;
    }

    /**
     * Determine a human-readable status label for a Keystone record.
     */
    private function statusLabel(Keystone $key): string
    {
        if ($key->revoked_at !== null) {
            return '<fg=red>Revoked</>';
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return '<fg=yellow>Expired</>';
        }

        if ($key->grace_expires_at !== null && $key->grace_expires_at->isFuture()) {
            return '<fg=cyan>Grace Period</>';
        }

        return '<fg=green>Active</>';
    }
}
