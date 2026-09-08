<?php

declare(strict_types=1);

namespace Schtzie\Keystone;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Commands\PruneKeystonesCommand;
use Schtzie\Keystone\Http\Middleware\AuthenticateWithKeystone;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\Services\KeystoneService;
use Schtzie\Keystone\Tenancy\KeystoneBootstrapper;

final class KeystoneServiceProvider extends ServiceProvider
{
    // ── Registration ───────────────────────────────────────────────────────

    /**
     * Register services in the container.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/keystone.php',
            'keystone',
        );

        $this->app->singleton(KeystoneKeyCacheRepository::class, function (Application $app): KeystoneKeyCacheRepository {
            /** @var \Illuminate\Cache\CacheManager $cacheManager */
            $cacheManager = $app->make('cache');
            $storeName = config('keystone.cache.store', 'redis');

            $prefixConfig = config('keystone.cache.prefix', 'keystone');
            $ttlConfig = config('keystone.cache.ttl');

            return new KeystoneKeyCacheRepository(
                cache: $cacheManager->store(is_string($storeName) ? $storeName : 'redis'),
                prefix: is_string($prefixConfig) ? $prefixConfig : 'keystone',
                ttl: is_numeric($ttlConfig) ? (int) $ttlConfig : null,
            );
        });

        $this->app->singleton(KeystoneService::class, function (Application $app): KeystoneService {
            /** @var KeystoneKeyCacheRepository $cacheRepo */
            $cacheRepo = $app->make(KeystoneKeyCacheRepository::class);

            return new KeystoneService(
                cache: $cacheRepo,
            );
        });
    }

    // ── Boot ───────────────────────────────────────────────────────────────

    /**
     * Bootstrap application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerPublishables();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerModelObservers();
        $this->registerTenancyBootstrapper();
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    /**
     * Register publishable assets.
     *
     * @return void
     */
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
            __DIR__.'/../database/migrations/create_keystoneables_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_keystoneables_table.php'),
        ], 'keystone-migrations');

        // Single-DB migration (single_db mode)
        $this->publishes([
            __DIR__.'/../database/migrations/create_keystoneables_table_single_db.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_keystoneables_table.php'),
        ], 'keystone-migrations-single-db');
    }

    /**
     * Register route middleware.
     *
     * @return void
     */
    private function registerMiddleware(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('api.key', AuthenticateWithKeystone::class);
    }

    /**
     * Register Artisan commands.
     *
     * @return void
     */
    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneKeystonesCommand::class]);
        }
    }

    /**
     * Automatically evict Redis cache entries when Keystone records are
     * updated (e.g. revoked) or hard-deleted.
     *
     * @return void
     */
    private function registerModelObservers(): void
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        if (! class_exists($modelClass)) {
            return;
        }

        $modelClass::updated(function (Keystone $key): void {
            app(KeystoneKeyCacheRepository::class)->forget($key->client);
        });

        $modelClass::deleted(function (Keystone $key): void {
            app(KeystoneKeyCacheRepository::class)->forget($key->client);
        });
    }

    /**
     * Append KeystoneBootstrapper to stancl/tenancy v4's bootstrapper stack
     * so that Keystone's in-memory state is flushed on every tenant switch.
     *
     * Guards:
     *  - auto_register_bootstrapper config must be true
     *  - stancl/tenancy must be installed (class_exists check)
     *
     * @return void
     */
    private function registerTenancyBootstrapper(): void
    {
        if (! config('keystone.tenancy.auto_register_bootstrapper', true)) {
            return;
        }

        if (! class_exists(\Stancl\Tenancy\Tenancy::class)) {
            return;
        }

        $this->app->resolving(\Stancl\Tenancy\Tenancy::class, function (object $tenancy): void {
            $previous = $tenancy->getBootstrappersUsing ?? null; // @phpstan-ignore-line

            $tenancy->getBootstrappersUsing = function ($tenant) use ($previous): array { // @phpstan-ignore-line
                /** @var array<int, string> $bootstrappers */
                $bootstrappers = is_callable($previous)
                    ? (array) $previous($tenant)
                    : (array) config('tenancy.bootstrappers', []);

                if (! in_array(KeystoneBootstrapper::class, $bootstrappers, true)) {
                    $bootstrappers[] = KeystoneBootstrapper::class;
                }

                return $bootstrappers;
            };
        });
    }
}

