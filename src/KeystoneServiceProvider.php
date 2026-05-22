<?php

declare(strict_types=1);

namespace Schatzie\Keystone;

use Illuminate\Support\ServiceProvider;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Commands\PruneApiKeysCommand;
use Schatzie\Keystone\Http\Middleware\AuthenticateWithApiKey;
use Schatzie\Keystone\Models\ApiKey;
use Schatzie\Keystone\Services\ApiKeyService;
use Schatzie\Keystone\Tenancy\KeystoneBootstrapper;

final class KeystoneServiceProvider extends ServiceProvider
{
    // ── Registration ───────────────────────────────────────────────────────

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/keystone.php',
            'keystone',
        );

        $this->app->singleton(ApiKeyCacheRepository::class, function ($app): ApiKeyCacheRepository {
            return new ApiKeyCacheRepository(
                cache: $app['cache']->store(config('keystone.cache.store', 'redis')),
                prefix: config('keystone.cache.prefix', 'keystone'),
                ttl: config('keystone.cache.ttl') !== null
                    ? (int) config('keystone.cache.ttl')
                    : null,
            );
        });

        $this->app->singleton(ApiKeyService::class, function ($app): ApiKeyService {
            return new ApiKeyService(
                cache: $app->make(ApiKeyCacheRepository::class),
            );
        });
    }

    // ── Boot ───────────────────────────────────────────────────────────────

    public function boot(): void
    {
        $this->registerPublishables();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerModelObservers();
        $this->registerTenancyBootstrapper();
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    private function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__.'/../config/keystone.php' => config_path('keystone.php'),
        ], 'keystone-config');

        // Base migration (none / multi_db modes)
        $this->publishes([
            __DIR__.'/../database/migrations/create_api_keys_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_api_keys_table.php'),
        ], 'keystone-migrations');

        // Single-DB migration (single_db mode)
        $this->publishes([
            __DIR__.'/../database/migrations/create_api_keys_table_single_db.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_api_keys_table.php'),
        ], 'keystone-migrations-single-db');
    }

    private function registerMiddleware(): void
    {
        $this->app['router']->aliasMiddleware('api.key', AuthenticateWithApiKey::class);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneApiKeysCommand::class]);
        }
    }

    /**
     * Automatically evict Redis cache entries when ApiKey records are
     * updated (e.g. revoked) or hard-deleted.
     */
    private function registerModelObservers(): void
    {
        ApiKey::updated(function (ApiKey $key): void {
            app(ApiKeyCacheRepository::class)->forget($key->api_key);
        });

        ApiKey::deleted(function (ApiKey $key): void {
            app(ApiKeyCacheRepository::class)->forget($key->api_key);
        });
    }

    /**
     * Append KeystoneBootstrapper to stancl/tenancy v4's bootstrapper stack
     * so that Keystone's in-memory state is flushed on every tenant switch.
     *
     * Guards:
     *  - auto_register_bootstrapper config must be true
     *  - stancl/tenancy must be installed (class_exists check)
     */
    private function registerTenancyBootstrapper(): void
    {
        if (! config('keystone.tenancy.auto_register_bootstrapper', true)) {
            return;
        }

        if (! class_exists(\Stancl\Tenancy\Tenancy::class)) {
            return;
        }

        $this->app->resolving(\Stancl\Tenancy\Tenancy::class, function ($tenancy): void {
            if (! in_array(KeystoneBootstrapper::class, $tenancy->bootstrappers, true)) {
                $tenancy->bootstrappers[] = KeystoneBootstrapper::class;
            }
        });
    }
}
