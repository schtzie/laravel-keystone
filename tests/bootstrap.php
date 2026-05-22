<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Schatzie\Keystone\Tests\Support\FakeTenant;

/**
 * Global tenant() helper stub for tests.
 *
 * Mirrors the stancl/tenancy tenant() helper signature:
 *   tenant()        → returns the current Tenant model (or null)
 *   tenant('key')   → returns a specific attribute of the current tenant
 *
 * Controlled via FakeTenant::set() and FakeTenant::clear().
 */
if (! function_exists('tenant')) {
    function tenant(?string $attribute = null): mixed
    {
        $fake = FakeTenant::current();

        if ($fake === null) {
            return null;
        }

        if ($attribute !== null) {
            return $fake->getTenantKey(); // simplified — only id is needed in tests
        }

        return $fake;
    }
}
