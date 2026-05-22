<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Schatzie\Keystone\KeystoneServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [KeystoneServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Keystone' => \Schatzie\Keystone\Facades\Keystone::class,
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

        // Use the array cache driver so tests never need a real Redis instance
        $app['config']->set('cache.default', 'array');
        $app['config']->set('keystone.cache.store', 'array');

        // Default: no tenancy
        $app['config']->set('keystone.tenancy.mode', 'none');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
