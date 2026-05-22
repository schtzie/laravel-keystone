<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Traits;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Models\ApiKey;

/**
 * Add this trait to any Eloquent model to give it API key management.
 *
 * Usage:
 *   class User extends Model {
 *       use HasApiKeys;
 *   }
 *
 *   $result = $user->createApiKey('My App');
 *   // $result['api_key']    — plain key to give the client
 *   // $result['secret_key'] — plain secret for HMAC signing (show once)
 *   // $result['model']      — the persisted ApiKey model
 */
trait HasApiKeys
{
    // ── Relationship ───────────────────────────────────────────────────────

    /**
     * All API keys belonging to this model.
     * In single_db mode the query is automatically scoped to the current tenant
     * via ApiKey's TenantScope global scope.
     *
     * @return MorphMany<ApiKey>
     */
    public function apiKeys(): MorphMany
    {
        return $this->morphMany(ApiKey::class, 'keystoneable');
    }

    // ── Key Management ─────────────────────────────────────────────────────

    /**
     * Generate and persist a new API key pair for this model.
     *
     * @param  array<int, string>  $scopes
     * @return array{api_key: string, secret_key: string, model: ApiKey}
     */
    public function createApiKey(
        string $name,
        array $scopes = [],
        ?CarbonImmutable $expiresAt = null,
    ): array {
        $plain = config('keystone.prefix', 'ks_')
                .bin2hex(random_bytes((int) config('keystone.key_length', 40)));

        $secret = bin2hex(random_bytes((int) config('keystone.key_length', 40)));

        /** @var ApiKey $model */
        $model = $this->apiKeys()->create([
            'name' => $name,
            'api_key' => $plain,
            'secret_key' => $secret,
            'scopes' => $scopes ?: config('keystone.default_scopes', []),
            'expires_at' => $expiresAt,
        ]);

        return [
            'api_key' => $plain,
            'secret_key' => $secret,
            'model' => $model,
        ];
    }

    /**
     * Revoke a specific API key by its ID or model instance.
     */
    public function revokeApiKey(int|string|ApiKey $key): bool
    {
        $model = $key instanceof ApiKey
            ? $key
            : $this->apiKeys()->findOrFail($key);

        return $model->revoke(); // fires Eloquent updated event → cache invalidation
    }

    /**
     * Revoke all active API keys for this model.
     * Also purges the owner's Redis index.
     */
    public function revokeAllApiKeys(): int
    {
        // Evict every cached key for this owner before updating the DB
        app(ApiKeyCacheRepository::class)->forgetOwner(
            static::class,
            $this->getKey(),
        );

        return $this->apiKeys()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Revoke an existing key and create a new one atomically.
     *
     * @return array{api_key: string, secret_key: string, model: ApiKey}
     */
    public function rotateApiKey(int|string|ApiKey $old): array
    {
        return DB::transaction(function () use ($old): array {
            $oldModel = $old instanceof ApiKey
                ? $old
                : $this->apiKeys()->findOrFail($old);

            $name = $oldModel->name;

            $this->revokeApiKey($oldModel);

            return $this->createApiKey($name);
        });
    }
}
