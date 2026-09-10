<?php

declare(strict_types=1);

if (! class_exists(\Stancl\Tenancy\Tenancy::class)) { return; }

use Schtzie\Keystone\KeystoneServiceProvider;
use Schtzie\Keystone\Tenancy\KeystoneBootstrapper;
use Stancl\Tenancy\Tenancy;

it('registers the KeystoneBootstrapper on the Tenancy instance when auto_register_bootstrapper is true', function (): void {
    // Config should be true by default, but let's be explicit
    config(['keystone.tenancy.auto_register_bootstrapper' => true]);

    // Force re-resolution of Tenancy to trigger the resolving callback
    app()->forgetInstance(Tenancy::class);
    $tenancy = app(Tenancy::class);

    // Call the closure that returns bootstrappers
    $bootstrappers = call_user_func($tenancy->getBootstrappersUsing, null);

    expect($bootstrappers)->toContain(KeystoneBootstrapper::class);
});

it('does not register the KeystoneBootstrapper when auto_register_bootstrapper is false', function (): void {
    $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
    $config = new \Illuminate\Config\Repository([
        'keystone.tenancy.auto_register_bootstrapper' => false,
    ]);
    $app->instance('config', $config);

    $provider = new KeystoneServiceProvider($app);
    $provider->register();

    $app->bind(Tenancy::class, function () use ($app) {
        // Just mock the class since we only care about the resolving callback
        return new Tenancy($app, null, null, null);
    });

    $tenancy = $app->make(Tenancy::class);

    expect(isset($tenancy->getBootstrappersUsing))->toBeFalse();
});
