<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Schtzie\Keystone\Commands\Traits\HasTenantOption;
use Throwable;

/**
 * Artisan command to generate a new API key pair for any Eloquent model owner.
 *
 * This command is the quickest way to provision a Keystone from the command
 * line — ideal for CI pipelines, staging environments, onboarding scripts,
 * and developer tooling where a UI is not available.
 *
 * The plain secret is printed once and never stored in a recoverable form.
 * Instruct operators to capture it immediately and store it in a secrets
 * manager or pass it directly to the consuming service.
 *
 * Usage examples:
 *   php artisan keystone:generate "App\Models\User" 1 "CI Bot"
 *   php artisan keystone:generate "App\Models\User" 1 "Read-only" --scope=read
 *   php artisan keystone:generate "App\Models\User" 1 "Expiring" --expires=2026-12-31
 *   php artisan keystone:generate "App\Models\User" 1 "Tagged" --description="Internal pipeline key"
 */
final class GenerateKeystoneCommand extends Command
{
    use HasTenantOption;

    /** @var string */
    protected $signature = 'keystone:generate
        {--tenant= : The ID of the tenant database to execute within}
        {model    : Fully-qualified model class (e.g. "App\\Models\\User")}
        {id       : Primary key of the owner record}
        {name     : Human-readable label for the new API key}
        {--scope=*         : One or more scopes to assign (repeatable: --scope=read --scope=write)}
        {--expires=        : Optional expiry date in Y-m-d or Y-m-d H:i:s format}
        {--description=    : Extended description or notes for this key}';

    /** @var string */
    protected $description = 'Generate a new API key pair (client + secret) for any Eloquent model owner.';

    /**
     * Execute the command — resolve the owner, create the Keystone, and print
     * the credentials in a formatted table with a security warning.
     */
    public function handle(): int
    {
        /** @var string $modelClass */
        $modelClass = $this->argument('model');

        if (! class_exists($modelClass) || ! is_a($modelClass, Model::class, true)) {
            $this->error("Model class [{$modelClass}] does not exist or is not an Eloquent model.");

            return self::FAILURE;
        }

        /** @var Model|null $owner */
        $owner = $modelClass::find($this->argument('id'));

        if ($owner === null) {
            $idArg = $this->argument('id');
            $this->error("No [{$modelClass}] record found with ID [".(is_scalar($idArg) ? (string) $idArg : 'unknown').'].');

            return self::FAILURE;
        }

        if (! method_exists($owner, 'createKeystone')) {
            $this->error("[{$modelClass}] does not use the HasKeystones trait.");

            return self::FAILURE;
        }

        // Parse optional expiry
        $expiresAt = null;
        if ($this->option('expires')) {
            try {
                $expiresOpt = $this->option('expires');
                $expiresAt = CarbonImmutable::parse(is_string($expiresOpt) ? $expiresOpt : null);
            } catch (Throwable) {
                $this->error('Invalid --expires format. Use Y-m-d or "Y-m-d H:i:s".');

                return self::FAILURE;
            }
        }

        /** @var array<int, string> $scopes */
        $scopes = (array) ($this->option('scope') ?? []);

        $options = [];
        if ($this->option('description')) {
            $desc = $this->option('description');
            $options['description'] = is_string($desc) ? $desc : '';
        }

        $nameArg = $this->argument('name');
        /** @var array{client: string, secret: string, model: \Schtzie\Keystone\Models\Keystone} $result */
        $result = $owner->createKeystone(

            name: is_string($nameArg) ? $nameArg : '',
            scopes: $scopes,
            expiresAt: $expiresAt,
            options: $options,
        );

        $this->newLine();
        $this->line('<fg=green;options=bold>✓ API key created successfully.</>');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Key ID',       $result['model']->id],
                ['Name',         $result['model']->name],
                ['Client ID',    $result['client']],
                ['Secret',       $result['secret']],
                ['Scopes',       implode(', ', $result['model']->scopes ?? []) ?: '(none)'],
                ['Expires At',   $result['model']->expires_at?->toDateTimeString() ?? 'Never'],
            ],
        );

        $this->newLine();
        $this->warn('⚠  Store the secret now — it cannot be retrieved again.');
        $this->newLine();

        return self::SUCCESS;
    }
}
