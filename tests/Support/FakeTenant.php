<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Tests\Support;

/**
 * Test double that simulates the stancl/tenancy tenant() global helper.
 *
 * Usage in tests:
 *   FakeTenant::set('tenant-abc');
 *   // ... code that calls tenant()->getTenantKey()
 *   FakeTenant::clear();
 */
final class FakeTenant
{
    private static ?string $currentId = null;

    public static function set(string $tenantId): void
    {
        self::$currentId = $tenantId;
    }

    public static function clear(): void
    {
        self::$currentId = null;
    }

    public static function current(): ?self
    {
        if (self::$currentId === null) {
            return null;
        }

        return new self();
    }

    public function getTenantKey(): string
    {
        return (string) self::$currentId;
    }
}
