<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Facades;

use Illuminate\Support\Facades\Facade;
use Schatzie\Keystone\Services\KeystoneService;

/**
 * @method static \Schatzie\Keystone\Models\Keystone|null resolve(\Illuminate\Http\Request $request)
 * @method static \Schatzie\Keystone\Models\Keystone|null findByKeystone(string $rawKey)
 * @method static array{client: string, secret: string, model: \Schatzie\Keystone\Models\Keystone} generate(\Illuminate\Database\Eloquent\Model $owner, string $name, array<string, mixed> $options = [])
 * @method static void invalidate(string $client)
 * @method static void flushResolved()
 *
 * @see KeystoneService
 */
final class Keystone extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return KeystoneService::class;
    }
}
