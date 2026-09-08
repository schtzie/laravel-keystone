<?php

declare(strict_types=1);

namespace Schtzie\Keystone;

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

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/keystone.php',
            'keystone',
        );

        $this->app->singleton(KeystoneKeyCacheRepository::class, function ($app): KeystoneKeyCacheRepository {
            return new KeystoneKeyCacheRepository(
                cache: $app['cache']->store(config('keystone.cache.store', 'redis')),
                prefix: config('keystone.cache.prefix', 'keystone'),
                ttl: config('keystone.cache.ttl') !== null
                    ? (int) config('keystone.cache.ttl')
                    : null,
            );
        });

        $this->app->singleton(KeystoneService::class, function ($app): KeystoneService {
            return new KeystoneService(
                cache: $app->make(KeystoneKeyCacheRepository::class),
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
        $this->registerAnalyticsRoute();
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
            __DIR__.'/../database/migrations/create_keystoneables_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_keystoneables_table.php'),
        ], 'keystone-migrations');

        // Single-DB migration (single_db mode)
        $this->publishes([
            __DIR__.'/../database/migrations/create_keystoneables_table_single_db.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_keystoneables_table.php'),
        ], 'keystone-migrations-single-db');
    }

    private function registerMiddleware(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('api.key', AuthenticateWithKeystone::class);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneKeystonesCommand::class]);
        }
    }

    /**
     * Automatically evict Redis cache entries when Keystone records are
     * updated (e.g. revoked) or hard-deleted.
     */
    private function registerModelObservers(): void
    {
        if (! class_exists(Keystone::class)) {
            return;
        }

        Keystone::updated(function (Keystone $key): void {
            app(KeystoneKeyCacheRepository::class)->forget($key->client);
        });

        Keystone::deleted(function (Keystone $key): void {
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

    /**
     * Register the built-in analytics endpoint when enabled.
     *
     * Route: GET /{prefix}/{client}
     *
     * The route is protected by the api.key middleware — so the request must
     * already carry a valid Client + HMAC signature pair. An additional
     * ownership check ensures a key can only read its own analytics data.
     */
    private function registerAnalyticsRoute(): void
    {
        if (! config('keystone.analytics.enabled', true)) {
            return;
        }

        $prefix = ltrim((string) config('keystone.analytics.prefix', 'keystone/analytics'), '/');

        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make('router');

        $router->middleware('api.key')->get(
            $prefix.'/{client}',
            function (\Illuminate\Http\Request $request, string $client): \Illuminate\Http\JsonResponse {
                // Ownership check — the authenticated key must match the requested slug
                $authenticated = $request->attributes->get('_keystone_client');

                if (! $authenticated instanceof Keystone || $authenticated->client !== $client) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }

                $analytics = app(KeystoneService::class)->analytics($client);

                if ($analytics === null) {
                    return response()->json(['message' => 'Not Found.'], 404);
                }

                return response()->json($analytics);
            }
        );
    }
}
