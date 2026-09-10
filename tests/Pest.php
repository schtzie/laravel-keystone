<?php

declare(strict_types=1);

if (! interface_exists(\Stancl\Tenancy\Database\Contracts\TenantWithDatabase::class) && interface_exists(\Stancl\Tenancy\Contracts\TenantWithDatabase::class)) {
    class_alias(\Stancl\Tenancy\Contracts\TenantWithDatabase::class, \Stancl\Tenancy\Database\Contracts\TenantWithDatabase::class);
}

use Schtzie\Keystone\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

pest()->beforeEach(function (): void {
    config(['keystone.cache.enabled' => true]);
});
