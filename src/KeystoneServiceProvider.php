<?php

declare(strict_types=1);

namespace Schtzie\Keystone;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Schtzie\Keystone\Analytics\KeystoneAnalytics;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Commands\GenerateKeystoneCommand;
use Schtzie\Keystone\Commands\ListKeystonesCommand;
use Schtzie\Keystone\Commands\PruneKeystonesCommand;
use Schtzie\Keystone\Commands\RevokeKeystoneCommand;
use Schtzie\Keystone\Commands\RotateExpiringSoonCommand;
use Schtzie\Keystone\Commands\StatusKeystoneCommand;
use Schtzie\Keystone\Commands\WarmCacheCommand;
use Schtzie\Keystone\Contracts\KeystoneServiceContract;
use Schtzie\Keystone\Events\KeystoneAuthFailed;
use Schtzie\Keystone\Events\KeystoneAuthenticated;
use Schtzie\Keystone\Events\KeystoneRateLimitExceeded;
use Schtzie\Keystone\Http\Middleware\AuthenticateWithKeystone;
use Schtzie\Keystone\Http\Middleware\VerifyKeystonePayload;
use Schtzie\Keystone\Logging\KeystoneAccessLogger;
use Schtzie\Keystone\Models\Keystone;
use Schtzie\Keystone\RateLimiting\Contracts\RateLimitStrategy;
use Schtzie\Keystone\RateLimiting\FixedWindowStrategy;
use Schtzie\Keystone\RateLimiting\SlidingWindowStrategy;
use Schtzie\Keystone\RateLimiting\TokenBucketStrategy;
use Schtzie\Keystone\Services\KeystoneService;
use Schtzie\Keystone\Tenancy\KeystoneBootstrapper;
use Schtzie\Keystone\Testing\KeystoneFake;

final class KeystoneServiceProvider extends ServiceProvider
{
    /**
     * Register all Keystone services into the IoC container.
     *
     * Bindings registered here:
     *   - KeystoneKeyCacheRepository (scoped singleton)
     *   - KeystoneService (scoped singleton, implements KeystoneServiceContract)
     *   - KeystoneServiceContract → KeystoneService (scoped alias)
     *   - RateLimitStrategy → configured strategy class (singleton)
     *   - KeystoneAnalytics (singleton)
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/keystone.php',
            'keystone',
        );

        // ── Cache repository ───────────────────────────────────────────────────
        $this->app->scoped(KeystoneKeyCacheRepository::class, function (Application $app): KeystoneKeyCacheRepository {
            /** @var \Illuminate\Cache\CacheManager $cacheManager */
            $cacheManager = $app->make('cache');
            $storeName    = config('keystone.cache.store', 'redis');

            $prefixConfig = config('keystone.cache.prefix', 'keystone');
            $ttlConfig    = config('keystone.cache.ttl');

            return new KeystoneKeyCacheRepository(
                cache: $cacheManager->store(is_string($storeName) ? $storeName : 'redis'),
                prefix: is_string($prefixConfig) ? $prefixConfig : 'keystone',
                ttl: is_numeric($ttlConfig) ? (int) $ttlConfig : null,
            );
        });

        // ── Authentication service ─────────────────────────────────────────────
        $this->app->scoped(KeystoneService::class, function (Application $app): KeystoneService {
            /** @var KeystoneKeyCacheRepository $cacheRepo */
            $cacheRepo = $app->make(KeystoneKeyCacheRepository::class);

            return new KeystoneService(cache: $cacheRepo);
        });

        // Bind the contract to the concrete service so the middleware and tests
        // can both resolve through the same interface (enables fake injection)
        $this->app->scoped(KeystoneServiceContract::class, function (Application $app): KeystoneService {
            return $app->make(KeystoneService::class);
        });

        // ── Rate-limit strategy ────────────────────────────────────────────────
        $this->app->singleton(RateLimitStrategy::class, function (): RateLimitStrategy {
            return match (config('keystone.rate_limit_strategy', 'fixed_window')) {
                'sliding_window' => new SlidingWindowStrategy(),
                'token_bucket'   => new TokenBucketStrategy(),
                default          => new FixedWindowStrategy(),
            };
        });

        // ── Analytics singleton ────────────────────────────────────────────────
        $this->app->singleton(KeystoneAnalytics::class);

        // ── KeystoneFake helper — bind fake() to the facade ───────────────────
        // KeystoneFake::install() and Keystone::fake() are handled in the Facade
    }

    /**
     * Bootstrap all Keystone application services.
     *
     * This method runs after all service providers have been registered, so it
     * is safe to resolve services from the container and interact with the router.
     */
    public function boot(): void
    {
        $this->registerPublishables();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerModelObservers();
        $this->registerTenancyBootstrapper();
        $this->registerEventListeners();
        $this->registerResponseMacro();
        $this->registerRoutes();
    }

    // ── Private boot methods ──────────────────────────────────────────────────

    /**
     * Register all publishable assets so host applications can customise them
     * via `php artisan vendor:publish --tag=keystone-*`.
     *
     * Published tags:
     *   keystone-config              — Main configuration file
     *   keystone-migrations          — Base table migration (none/multi_db modes)
     *   keystone-migrations-single-db — Single-DB tenancy migration
     *   keystone-migrations-enhancements — Addendum migration for v2 columns
     *   keystone-migrations-access-logs  — Access log table migration
     *   keystone-routes              — Publishable REST routes file
     */
    private function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/keystone.php' => config_path('keystone.php'),
        ], 'keystone-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_keystoneables_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_keystoneables_table.php'),
        ], 'keystone-migrations');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_keystoneables_table_single_db.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_keystoneables_table.php'),
        ], 'keystone-migrations-single-db');

        $this->publishes([
            __DIR__ . '/../database/migrations/add_keystone_enhancements.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_add_keystone_enhancements.php'),
        ], 'keystone-migrations-enhancements');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_keystone_access_logs_table.php.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_keystone_access_logs_table.php'),
        ], 'keystone-migrations-access-logs');

        $this->publishes([
            __DIR__ . '/../routes/keystone.php' => base_path('routes/keystone.php'),
        ], 'keystone-routes');
    }

    /**
     * Register middleware aliases on the router.
     *
     * Aliases registered:
     *   api.key         → AuthenticateWithKeystone (client ID + HMAC auth)
     *   api.key.payload → VerifyKeystonePayload    (request body integrity check)
     */
    private function registerMiddleware(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('api.key', AuthenticateWithKeystone::class);
        $router->aliasMiddleware('api.key.payload', VerifyKeystonePayload::class);
    }

    /**
     * Register all Keystone Artisan commands when running in the console.
     *
     * Commands registered:
     *   keystone:generate       — Create a new API key pair for an owner
     *   keystone:revoke         — Immediately revoke a key by client ID
     *   keystone:list           — Display a filterable table of all keys
     *   keystone:status         — Show health metrics and configuration summary
     *   keystone:warm           — Pre-warm the Redis cache with all active keys
     *   keystone:rotate-expiring — Auto-rotate keys approaching their expiry date
     *   keystone:prune          — Delete old revoked keys and prune access logs
     */
    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateKeystoneCommand::class,
                RevokeKeystoneCommand::class,
                ListKeystonesCommand::class,
                StatusKeystoneCommand::class,
                WarmCacheCommand::class,
                RotateExpiringSoonCommand::class,
                PruneKeystonesCommand::class,
            ]);
        }
    }

    /**
     * Register Eloquent model observers that automatically evict the Redis cache
     * when a Keystone record is updated (e.g. revoked) or hard-deleted.
     *
     * Using model events rather than inline cache.forget() calls in the service
     * ensures cache eviction fires even when the model is updated from outside
     * the Keystone API (e.g. raw queries, admin panels, or queue jobs).
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
     * Append KeystoneBootstrapper to stancl/tenancy v4's bootstrapper stack so
     * that Keystone's in-memory resolved-key state is flushed on every tenant
     * switch — preventing cross-tenant key leakage in long-lived Octane processes.
     *
     * Guards:
     *   - auto_register_bootstrapper config must be true (default)
     *   - stancl/tenancy package must be installed (class_exists guard)
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
            $previous = $tenancy->getBootstrappersUsing ?? null;

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

    /**
     * Register event listeners for access logging and analytics.
     *
     * The KeystoneAccessLogger subscribes to three events and writes a structured
     * log entry to the configured driver(s) when `keystone.access_log.enabled`
     * is true. Listeners are always registered regardless of the enabled flag —
     * the logger itself short-circuits when disabled, so there is no overhead to
     * registering the listeners unconditionally.
     */
    private function registerEventListeners(): void
    {
        $logger = KeystoneAccessLogger::class;

        Event::listen(KeystoneAuthenticated::class,     [$logger, 'handleAuthenticated']);
        Event::listen(KeystoneAuthFailed::class,        [$logger, 'handleAuthFailed']);
        Event::listen(KeystoneRateLimitExceeded::class, [$logger, 'handleRateLimitExceeded']);
    }

    /**
     * Register the `keystoneChallenge()` response macro for customised 401 responses.
     *
     * Usage in a controller or exception handler:
     *   return response()->keystoneChallenge('Your API key has been revoked.');
     *   return response()->keystoneChallenge('Invalid signature.', 403);
     */
    private function registerResponseMacro(): void
    {
        \Illuminate\Support\Facades\Response::macro(
            'keystoneChallenge',
            function (string $message = 'Unauthorized.', int $status = 401): \Illuminate\Http\JsonResponse {
                /** @var \Illuminate\Routing\ResponseFactory $this */
                return response()->json(['message' => $message], $status); // @phpstan-ignore-line
            }
        );
    }

    /**
     * Load the built-in REST routes when `keystone.routes.enabled` is true.
     *
     * Routes are not loaded by default to avoid adding unexpected URL patterns
     * to applications that prefer to build their own key-management interface.
     * Enable via `KEYSTONE_ROUTES_ENABLED=true` in your environment file.
     */
    private function registerRoutes(): void
    {
        if (! config('keystone.routes.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/keystone.php');
    }
}
