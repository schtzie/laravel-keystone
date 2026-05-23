<?php

declare(strict_types=1);

namespace Schatzie\Keystone\Traits;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Schatzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schatzie\Keystone\Models\Keystone;

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
 */
trait HasKeystones
{
    // ── Relationship ───────────────────────────────────────────────────────

    /**
     * All Clients belonging to this model.
     * In single_db mode the query is automatically scoped to the current tenant
     * via Keystone's TenantScope global scope.
     *
     * @return MorphMany<Keystone>
     */
    public function keystones(): MorphMany
    {
        return $this->morphMany(Keystone::class, 'keystoneable');
    }

    // ── Key Management ─────────────────────────────────────────────────────

    /**
     * Generate and persist a new Client pair for this model.
     *
     * @param  array<int, string>  $scopes
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function createKeystone(
        string $name,
        array $scopes = [],
        ?CarbonImmutable $expiresAt = null,
    ): array {
        $plain = config('keystone.prefix', 'ks_')
                .bin2hex(random_bytes((int) config('keystone.key_length', 40)));

        $secret = bin2hex(random_bytes((int) config('keystone.key_length', 40)));

        /** @var Keystone $model */
        $model = $this->keystones()->create([
            'name' => $name,
            'client' => $plain,
            'secret' => $secret,
            'scopes' => $scopes ?: config('keystone.default_scopes', []),
            'expires_at' => $expiresAt,
        ]);

        return [
            'client' => $plain,
            'secret' => $secret,
            'model' => $model,
        ];
    }

    /**
     * Revoke a specific Client by its ID or model instance.
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

            return $this->createKeystone($name);
        });
    }
}
