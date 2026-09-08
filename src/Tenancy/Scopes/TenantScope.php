<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Automatically restricts Keystone queries to the current tenant when
 * KEYSTONE_TENANCY_MODE=single_db. Applied as a global scope via TenantAware.
 *
 * Has no effect when:
 *  - tenancy.mode is not 'single_db'
 *  - the tenant() helper is unavailable (tenancy not installed)
 *  - tenancy has not yet been initialized for the current request
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
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
        $column = $model->getTable().'.'.(is_string($colConfig) ? $colConfig : 'tenant_id');

        $tenantKey = $tenant->getTenantKey();

        if (is_string($tenantKey) || is_numeric($tenantKey)) {
            $builder->where($column, (string) $tenantKey);
        }
    }
}

