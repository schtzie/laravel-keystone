<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Facades;

use Illuminate\Support\Facades\Facade;
use Schtzie\Keystone\Services\KeystoneService;

/**
 * @method static \Schtzie\Keystone\Models\Keystone|null resolve(\Illuminate\Http\Request $request)
 * @method static \Schtzie\Keystone\Models\Keystone|null findByKeystone(string $rawKey)
 * @method static array{client: string, secret: string, model: \Schtzie\Keystone\Models\Keystone} generate(\Illuminate\Database\Eloquent\Model $owner, string $name, array{scopes?: array<int, string>, expires_at?: \Carbon\CarbonImmutable|null} $options = [])
 * @method static void invalidate(string $client)
 * @method static void flushResolved()
 *
 * @see \Schtzie\Keystone\Services\KeystoneService
 */
final class Keystone extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return KeystoneService::class;
    }
}

