<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Facades;

use Illuminate\Support\Facades\Facade;
use Schatzie\Keystone\Services\ApiKeyService;

/**
 * @method static \Schatzie\Keystone\Models\ApiKey|null resolve(\Illuminate\Http\Request $request)
 * @method static \Schatzie\Keystone\Models\ApiKey|null findByApiKey(string $rawKey)
 * @method static array{api_key: string, secret_key: string, model: \Schatzie\Keystone\Models\ApiKey} generate(\Illuminate\Database\Eloquent\Model $owner, string $name, array $options = [])
 * @method static void invalidate(string $apiKey)
 * @method static void flushResolved()
 *
 * @see \Schatzie\Keystone\Services\ApiKeyService
 */
final class Keystone extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApiKeyService::class;
    }
}
