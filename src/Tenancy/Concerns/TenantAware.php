<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Tenancy\Concerns;

use Schatzie\Keystone\Tenancy\Scopes\TenantScope;

/**
 * Mixed into Keystone to:
 *  1. Register TenantScope as a global scope (filters all queries by tenant).
 *  2. Stamp the tenant_id column on every new record (single_db mode only).
 *
 * The trait is always mixed in, but the scope/stamp logic is a no-op when
 * the tenancy mode is not 'single_db' or tenancy is not initialised.
 */
trait TenantAware
{
    public static function bootTenantAware(): void
    {
        // Always register the scope — it guards itself with a mode check inside.
        static::addGlobalScope(new TenantScope());

        static::creating(static function (self $model): void {
            if (config('keystone.tenancy.mode') !== 'single_db') {
                return;
            }

            if (! function_exists('tenant') || tenant() === null) {
                return;
            }

            $col = (string) config('keystone.tenancy.tenant_id_column', 'tenant_id');

            // Only set if not already provided explicitly
            if (empty($model->{$col})) {
                $model->{$col} = tenant()->getTenantKey();
            }
        });
    }
}
