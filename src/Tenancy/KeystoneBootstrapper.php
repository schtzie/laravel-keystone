<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Tenancy;

use Schatzie\Keystone\Services\ApiKeyService;

/**
 * Stancl/Tenancy v4 bootstrapper for Laravel Keystone.
 *
 * Registered automatically when:
 *  - stancl/tenancy is installed
 *  - config('keystone.tenancy.auto_register_bootstrapper') === true
 *
 * Ensures that Keystone's in-memory resolved-key state is flushed whenever
 * the active tenant changes. This is critical for long-lived processes such
 * as Laravel Octane or queue workers that handle requests from multiple
 * tenants in a single PHP process.
 *
 * Implements \Stancl\Tenancy\Contracts\TenancyBootstrapper via duck-typing
 * so the package does not hard-require stancl/tenancy as a composer dependency.
 */
final class KeystoneBootstrapper
{
    public function __construct(private readonly ApiKeyService $service) {}

    /**
     * Called by stancl/tenancy when a new tenant is initialised.
     *
     * @param  object  $tenant  Stancl\Tenancy\Database\Models\Tenant or custom model
     */
    public function bootstrap(object $tenant): void
    {
        $this->service->flushResolved();
    }

    /**
     * Called by stancl/tenancy when tenancy is ended (e.g. back to central context).
     */
    public function revert(): void
    {
        $this->service->flushResolved();
    }
}
