<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Tenancy\Concerns;

use Schtzie\Keystone\Tenancy\Scopes\TenantScope;

/**
 * Mixed into Keystone to:
 *  1. Register TenantScope as a global scope (filters all queries by tenant).
 *  2. Stamp the tenant_id column on every new record (single_db mode only).
 *
 * The trait is always mixed in, but the scope/stamp logic is a no-op when
 * the tenancy mode is not 'single_db' or tenancy is not initialised.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait TenantAware
{
    /**
     * Boot the tenant aware trait for a model.
     */
    public static function bootTenantAware(): void
    {
        // Always register the scope — it guards itself with a mode check inside.
        static::addGlobalScope(new TenantScope());

        static::creating(static function (self $model): void {
            if (config('keystone.tenancy.mode') !== 'single_db') {
                return;
            }

            if (! function_exists('tenant')) {
                return;
            }

            /** @var mixed $tenant */
            $tenant = tenant();

            if (! is_object($tenant) || ! method_exists($tenant, 'getTenantKey')) {
                return;
            }

            $colConfig = config('keystone.tenancy.tenant_id_column', 'tenant_id');
            $col = is_string($colConfig) ? $colConfig : 'tenant_id';

            // Only set if not already provided explicitly
            if (empty($model->{$col})) {
                $tenantKey = $tenant->getTenantKey();
                if (is_string($tenantKey) || is_numeric($tenantKey)) {
                    $model->{$col} = (string) $tenantKey;
                }
            }
        });
    }
}
