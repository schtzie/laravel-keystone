<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Facades;

use Illuminate\Support\Facades\Facade;
use Schtzie\Keystone\Services\KeystoneService;

/**
 * @method static \Schtzie\Keystone\Models\Keystone|null resolve(\Illuminate\Http\Request $request)
 * @method static \Schtzie\Keystone\Models\Keystone|null findByKeystone(string $rawKey)
 * @method static array{client: string, secret: string, model: \Schtzie\Keystone\Models\Keystone} generate(\Illuminate\Database\Eloquent\Model $owner, string $name, array<string, mixed> $options = [])
 * @method static void invalidate(string $client)
 * @method static void flushResolved()
 * @method static array{client: string, name: string, scopes: array<string>|null, active: bool, rate_limit: int|null, created_at: string, expires_at: string|null, revoked_at: string|null, last_used_at: string|null, last_used_ip: string|null}|null analytics(string $client)
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
