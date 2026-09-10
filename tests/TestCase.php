<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Schtzie\Keystone\KeystoneServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            \Stancl\Tenancy\TenancyServiceProvider::class,
            KeystoneServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Keystone'          => \Schtzie\Keystone\Facades\Keystone::class,
            'KeystoneAnalytics' => \Schtzie\Keystone\Facades\KeystoneAnalytics::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Use SQLite in-memory for all tests
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Tenancy SQLite physical connection for multi_db
        $app['config']->set('database.connections.tenant', [
            'driver'   => 'sqlite',
            'database' => ':memory:', // We will replace this dynamically in tests that need it
            'prefix'   => '',
        ]);

        // Use the array cache driver so tests never need a real Redis instance
        $app['config']->set('cache.default', 'array');
        $app['config']->set('keystone.cache.store', 'array');

        // Default: no tenancy
        $app['config']->set('keystone.tenancy.mode', 'none');

        // Configure stancl/tenancy
        $app['config']->set('tenancy.tenant_model', \Schtzie\Keystone\Tests\Fixtures\Tenant::class);
        $app['config']->set('tenancy.id_generator', \Stancl\Tenancy\UUIDGenerator::class);
        $app['config']->set('tenancy.domain_model', \Stancl\Tenancy\Database\Models\Domain::class);
        $app['config']->set('tenancy.database.central_connection', 'testing');
        $app['config']->set('tenancy.database.template_tenant_connection', null);
        $app['config']->set('tenancy.database.prefix', 'tenant');
        $app['config']->set('tenancy.database.suffix', '.sqlite');
        $app['config']->set('tenancy.database.managers', [
            'sqlite' => \Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager::class,
        ]);
        $app['config']->set('tenancy.bootstrappers', [
            \Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        ]);
        $app['config']->set('tenancy.database.models', [
            'Tenant' => \Schtzie\Keystone\Tests\Fixtures\Tenant::class,
        ]);

        // App key required for session-based tests (e.g. REST routes with actingAs)
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }


    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        
        // Create tenants table
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        if (! $schema->hasTable('tenants')) {
            $schema->create('tenants', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->string('id')->primary();
                $table->timestamps();
                $table->json('data')->nullable();
            });
        }
        if (! $schema->hasTable('domains')) {
            $schema->create('domains', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->increments('id');
                $table->string('domain', 255)->unique();
                $table->string('tenant_id');
                $table->timestamps();
                $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            });
        }
    }
}
