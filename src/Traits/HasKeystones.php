<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Traits;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Models\Keystone;

/**
 * Add this trait to any Eloquent model to give it Client management.
 *
 * Usage:
 *   class User extends Model {
 *       use HasKeystones;
 *   }
 *
 *   $result = $user->createKeystone('My App');
 *   // $result['client']    — plain key to give the client
 *   // $result['secret'] — plain secret for HMAC signing (show once)
 *   // $result['model']      — the persisted Keystone model
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasKeystones
{
    // ── Relationship ───────────────────────────────────────────────────────

    /**
     * All Clients belonging to this model.
     * In single_db mode the query is automatically scoped to the current tenant
     * via Keystone's TenantScope global scope.
     *
     * @return MorphMany<Keystone, $this>
     */
    public function keystones(): MorphMany
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        return $this->morphMany($modelClass, 'keystoneable');
    }

    // ── Key Management ─────────────────────────────────────────────────────

    /**
     * Generate and persist a new Client pair for this model.
     *
     * @param string $name
     * @param array<int, string> $scopes
     * @param CarbonImmutable|null $expiresAt
     * @param array<string, mixed> $options
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function createKeystone(
        string $name,
        array $scopes = [],
        ?CarbonImmutable $expiresAt = null,
        array $options = [],
    ): array {
        $prefixConfig = config('keystone.prefix', 'ks_');
        $prefix = is_string($prefixConfig) ? $prefixConfig : 'ks_';

        $keyLengthConfig = config('keystone.key_length', 40);
        $keyLength = is_numeric($keyLengthConfig) ? (int) $keyLengthConfig : 40;

        $plain = $prefix.bin2hex(random_bytes($keyLength));
        $secret = bin2hex(random_bytes($keyLength));

        $defaultScopesConfig = config('keystone.default_scopes', []);
        $defaultScopes = is_array($defaultScopesConfig) ? $defaultScopesConfig : [];

        /** @var Keystone $model */
        $model = $this->keystones()->create(array_merge([
            'name' => $name,
            'client' => $plain,
            'secret' => $secret,
            'scopes' => $scopes !== [] ? $scopes : $defaultScopes,
            'expires_at' => $expiresAt,
        ], $options));

        return [
            'client' => $plain,
            'secret' => $secret,
            'model' => $model,
        ];
    }

    /**
     * Revoke a specific Client by its ID or model instance.
     *
     * @param int|string|Keystone $key
     * @return bool
     */
    public function revokeKeystone(int|string|Keystone $key): bool
    {
        $model = $key instanceof Keystone
            ? $key
            : $this->keystones()->findOrFail($key);

        return $model->revoke(); // fires Eloquent updated event → cache invalidation
    }

    /**
     * Revoke all active Clients for this model.
     * Also purges the owner's Redis index.
     *
     * @return int
     */
    public function revokeAllKeystones(): int
    {
        // Evict every cached key for this owner before updating the DB
        app(KeystoneKeyCacheRepository::class)->forgetOwner(
            static::class,
            $this->getKey(),
        );

        return $this->keystones()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Revoke an existing key and create a new one atomically.
     *
     * @param int|string|Keystone $old
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function rotateKeystone(int|string|Keystone $old): array
    {
        return DB::transaction(function () use ($old): array {
            $oldModel = $old instanceof Keystone
                ? $old
                : $this->keystones()->findOrFail($old);

            $name = $oldModel->name;

            $this->revokeKeystone($oldModel);

            return $this->createKeystone(
                $name,
                $oldModel->scopes ?? [],
                $oldModel->expires_at,
                [
                    'ip_allowlist' => $oldModel->ip_allowlist,
                    'ip_blocklist' => $oldModel->ip_blocklist,
                    'rate_limit' => $oldModel->rate_limit,
                ]
            );
        });
    }
}

