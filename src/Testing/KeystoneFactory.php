<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Schtzie\Keystone\Models\Keystone;

/**
 * Fluent test factory for creating Keystone records in a variety of states.
 *
 * KeystoneFactory makes test setup concise and readable by providing a builder
 * API that mirrors the language of the domain: "a key for this user, with these
 * scopes, that has already expired".
 *
 * Unlike Laravel's built-in model factories, KeystoneFactory always creates keys
 * through the `HasKeystones::createKeystone()` method — meaning the generated
 * key goes through the same code path as production, including cache warming and
 * event dispatching.
 *
 * Usage examples:
 *
 *   // An active key
 *   $result = KeystoneFactory::for($user)->create();
 *
 *   // An expired key (created in the past and already past its expiry)
 *   $result = KeystoneFactory::for($user)->expired()->create();
 *
 *   // A revoked key with specific scopes
 *   $result = KeystoneFactory::for($user)->withScopes(['read'])->revoked()->create();
 *
 *   // A key expiring tomorrow
 *   $result = KeystoneFactory::for($user)->expiringAt(now()->addDay())->create('Tomorrow key');
 *
 *   // Access plain credentials
 *   ['client' => $client, 'secret' => $secret, 'model' => $keystone] = KeystoneFactory::for($user)->create();
 */
final class KeystoneFactory
{
    /** @var array<int, string> */
    private array $scopes = [];

    private ?CarbonImmutable $expiresAt = null;

    private bool $revoked = false;

    private ?string $description = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private function __construct(private readonly Model $owner) {}

    /**
     * Begin configuring a new Keystone for the given owner model.
     * The owner must use the {@see \Schtzie\Keystone\Traits\HasKeystones} trait.
     */
    public static function for(Model $owner): static
    {
        return new static($owner);
    }

    /**
     * Assign the given scopes to the key.
     *
     * @param  array<int, string>  $scopes
     */
    public function withScopes(array $scopes): static
    {
        $clone = clone $this;
        $clone->scopes = $scopes;

        return $clone;
    }

    /**
     * Mark the key as already expired by setting `expires_at` to one day in the past.
     * The key will fail `isValid()` immediately after creation.
     */
    public function expired(): static
    {
        $clone = clone $this;
        $clone->expiresAt = CarbonImmutable::now()->subDay();

        return $clone;
    }

    /**
     * Set a specific expiry timestamp on the key.
     * Pass a future timestamp to create a key that is still valid but will expire.
     */
    public function expiringAt(CarbonImmutable|\DateTimeInterface $expiresAt): static
    {
        $clone = clone $this;
        $clone->expiresAt = $expiresAt instanceof CarbonImmutable
            ? $expiresAt
            : CarbonImmutable::instance($expiresAt);

        return $clone;
    }

    /**
     * Mark the key as revoked immediately after creation.
     * The key will fail `isValid()` and be rejected by the middleware.
     */
    public function revoked(): static
    {
        $clone = clone $this;
        $clone->revoked = true;

        return $clone;
    }

    /**
     * Attach an extended description to the key.
     */
    public function withDescription(string $description): static
    {
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    /**
     * Attach arbitrary metadata to the key.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $clone = clone $this;
        $clone->metadata = $metadata;

        return $clone;
    }

    /**
     * Persist the Keystone to the database and return the credential array.
     *
     * Revocation and other state mutations are applied after the initial insert
     * so that the create path (and all its side effects) always runs first.
     *
     * @return array{client: string, secret: string, model: Keystone}
     */
    public function create(string $name = 'Test Key'): array
    {
        $options = [];

        if ($this->description !== null) {
            $options['description'] = $this->description;
        }

        if ($this->metadata !== []) {
            $options['metadata'] = $this->metadata;
        }

        /** @var array{client: string, secret: string, model: Keystone} $result */
        $result = $this->owner->createKeystone( // @phpstan-ignore-line
            name: $name,
            scopes: $this->scopes,
            expiresAt: $this->expiresAt,
            options: $options,
        );

        if ($this->revoked) {
            $result['model']->revoke();
            $result['model']->refresh();
        }

        return $result;
    }
}
