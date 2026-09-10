<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Traits;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;
use Schtzie\Keystone\Events\KeystoneCreated;
use Schtzie\Keystone\Events\KeystoneRotated;
use Schtzie\Keystone\Models\Keystone;

/**
 * Attach this trait to any Eloquent model to give it full API key management.
 *
 * The trait provides the complete key lifecycle for the owner model:
 *   createKeystone()    — generate a new key pair and persist it
 *   revokeKeystone()    — soft-revoke a specific key
 *   revokeAllKeystones() — revoke every active key in one batch
 *   rotateKeystone()    — atomically replace a key with a fresh one
 *   keystones()         — Eloquent relationship to all owned keys
 *
 * Usage:
 *   class User extends Model {
 *       use HasKeystones;
 *   }
 *
 *   $result = $user->createKeystone('My App', ['read', 'write']);
 *   // $result['client'] — plain key to hand to the consumer (e.g. "ks_abc…")
 *   // $result['secret'] — plain HMAC-signing secret (show once, then gone)
 *   // $result['model']  — the persisted Keystone Eloquent model
 *
 * Owner deletion behaviour:
 *   Soft-delete of the owner  → all active keystones are soft-revoked (history preserved).
 *   Hard-delete of the owner  → all keystones are permanently deleted and cache is evicted.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasKeystones
{
    /**
     * Boot the trait, registering a `deleting` listener that cascades to keystones.
     *
     * This runs automatically when the model class is booted by Eloquent. No
     * manual registration is needed in the host model.
     */
    public static function bootHasKeystones(): void
    {
        static::deleting(function (\Illuminate\Database\Eloquent\Model $model): void {
            $isSoftDelete = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();

            if ($isSoftDelete) {
                // Soft-delete: revoke (not delete) all active keys so history is preserved
                /** @phpstan-ignore-next-line */
                $model->revokeAllKeystones();
            } else {
                // Hard-delete: permanently remove all keys and clear the cache index
                app(KeystoneKeyCacheRepository::class)->forgetOwner(
                    $model::class,
                    $model->getKey(),
                );

                /** @phpstan-ignore-next-line */
                $model->keystones()->delete();
            }
        });
    }

    /**
     * All Keystones belonging to this owner.
     *
     * In `single_db` tenancy mode the query is automatically scoped to the
     * current tenant via the TenantScope global scope registered on the
     * Keystone model — no additional filtering is needed at the call site.
     *
     * @return MorphMany<Keystone, $this>
     */
    public function keystones(): MorphMany
    {
        /** @var class-string<Keystone> $modelClass */
        $modelClass = config('keystone.model', Keystone::class);

        return $this->morphMany($modelClass, 'keystoneable');
    }

    /**
     * Generate and persist a new API key pair for this owner.
     *
     * The `$options` array supports any additional column values recognised by
     * the Keystone model (e.g. `description`, `metadata`, `ip_allowlist`,
     * `ip_blocklist`, `rate_limit`). Keys unknown to the model are ignored.
     *
     * A `KeystoneCreated` event is dispatched after the record is persisted so
     * listeners can send notifications or trigger integrations.
     *
     * If `keystone.max_keys_per_owner` is configured and the owner already holds
     * that many active keys, a RuntimeException is thrown before any DB write.
     *
     * @param  array<int, string>   $scopes   Allowed scopes for this key (e.g. ['read', 'write']).
     * @param  array<string, mixed> $options  Extra columns to pass through to the create call.
     * @return array{client: string, secret: string, model: Keystone}
     *
     * @throws \RuntimeException When the owner has reached the max-keys-per-owner limit.
     */
    public function createKeystone(
        string $name,
        array $scopes = [],
        ?CarbonImmutable $expiresAt = null,
        array $options = [],
    ): array {
        // Enforce the maximum-keys-per-owner limit before touching the database
        $maxKeys = config('keystone.max_keys_per_owner');

        if (is_numeric($maxKeys) && (int) $maxKeys > 0) {
            $activeCount = $this->keystones()->whereNull('revoked_at')->count(); // @phpstan-ignore-line

            if ($activeCount >= (int) $maxKeys) {
                throw new \RuntimeException(
                    "This owner already has {$activeCount} active API key(s), which equals the ".
                    "configured maximum of {$maxKeys}. Revoke an existing key before creating a new one."
                );
            }
        }

        $prefixConfig   = config('keystone.prefix', 'ks_');
        $prefix         = is_string($prefixConfig) ? $prefixConfig : 'ks_';
        $keyLengthConfig = config('keystone.key_length', 40);
        $keyLength      = is_numeric($keyLengthConfig) ? (int) $keyLengthConfig : 40;

        $plain  = $prefix . bin2hex(random_bytes($keyLength));
        $secret = bin2hex(random_bytes($keyLength));

        $defaultScopesConfig = config('keystone.default_scopes', []);
        $defaultScopes       = is_array($defaultScopesConfig) ? $defaultScopesConfig : [];

        /** @var Keystone $model */
        $model = $this->keystones()->create(array_merge([ // @phpstan-ignore-line
            'name'       => $name,
            'client'     => $plain,
            'secret'     => $secret,
            'scopes'     => $scopes !== [] ? $scopes : $defaultScopes,
            'expires_at' => $expiresAt,
        ], $options));

        event(new KeystoneCreated($model, $plain, $secret));

        return [
            'client' => $plain,
            'secret' => $secret,
            'model'  => $model,
        ];
    }

    /**
     * Revoke a specific Keystone by its ID or model instance.
     *
     * Delegates to `Keystone::revoke()`, which stamps `revoked_at`, fires the
     * model observer that evicts the Redis cache, and dispatches `KeystoneRevoked`.
     *
     * @param  int|string|Keystone  $key  The key's primary key or an already-loaded Keystone model.
     */
    public function revokeKeystone(int|string|Keystone $key): bool
    {
        $model = $key instanceof Keystone
            ? $key
            : $this->keystones()->findOrFail($key); // @phpstan-ignore-line

        return $model->revoke();
    }

    /**
     * Revoke all currently active Keystones for this owner in a single batch update.
     *
     * Proactively evicts the entire owner index from Redis before updating the DB
     * so there is no window in which a cache-warm key can still authenticate after
     * revocation. Returns the number of rows updated.
     */
    public function revokeAllKeystones(): int
    {
        // Evict every cached key for this owner before the DB update
        app(KeystoneKeyCacheRepository::class)->forgetOwner(
            static::class,
            $this->getKey(),
        );

        return $this->keystones() // @phpstan-ignore-line
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Atomically replace an existing key with a freshly generated one.
     *
     * The replacement key inherits the predecessor's name, scopes, IP lists, and
     * rate limit. If `keystone.rotation_grace_seconds` is greater than zero, the
     * old key's `grace_expires_at` is set to `now() + grace_seconds` so that
     * consumers who have not yet updated their credentials continue to work during
     * the transition window.
     *
     * A `KeystoneRotated` event is dispatched after the transaction completes so
     * listeners can notify the owner or propagate the new key to integrations.
     *
     * @param  int|string|Keystone  $old  The key to replace.
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function rotateKeystone(int|string|Keystone $old): array
    {
        return DB::transaction(function () use ($old): array {
            $oldModel = $old instanceof Keystone
                ? $old
                : $this->keystones()->findOrFail($old); // @phpstan-ignore-line

            $graceSeconds = (int) config('keystone.rotation_grace_seconds', 0);

            if ($graceSeconds > 0) {
                // Set a grace window instead of immediately revoking — the old key
                // remains valid until grace_expires_at, giving consumers time to rotate
                $oldModel->update([
                    'revoked_at'       => now(),
                    'grace_expires_at' => now()->addSeconds($graceSeconds),
                ]);

                // Evict cache so the warm copy is refreshed with the updated model
                app(KeystoneKeyCacheRepository::class)->forget($oldModel->client);
            } else {
                // No grace period — revoke immediately
                $this->revokeKeystone($oldModel);
            }

            $newResult = $this->createKeystone(
                name: $oldModel->name,
                scopes: $oldModel->scopes ?? [],
                expiresAt: $oldModel->expires_at,
                options: [
                    'ip_allowlist' => $oldModel->ip_allowlist,
                    'ip_blocklist' => $oldModel->ip_blocklist,
                    'rate_limit'   => $oldModel->rate_limit,
                    'description'  => $oldModel->description, // @phpstan-ignore-line
                    'metadata'     => $oldModel->metadata,    // @phpstan-ignore-line
                ],
            );

            event(new KeystoneRotated($oldModel, $newResult['model']));

            return $newResult;
        });
    }
}
