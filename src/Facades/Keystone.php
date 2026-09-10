<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Schtzie\Keystone\Contracts\KeystoneServiceContract;
use Schtzie\Keystone\Models\Keystone as KeystoneModel;
use Schtzie\Keystone\Services\KeystoneService;
use Schtzie\Keystone\Testing\KeystoneFake;

/**
 * Static facade for the Keystone authentication service.
 *
 * Proxies static method calls to the underlying {@see KeystoneService} singleton
 * (registered under the {@see KeystoneServiceContract} contract in the container).
 *
 * Standard usage in application code:
 *   \Keystone::generate($user, 'My App', ['scopes' => ['read', 'write']]);
 *   \Keystone::invalidate($client);
 *   \Keystone::findByKeystone($rawKey);
 *
 * Test usage — swap the real service for a controllable fake:
 *   $fake = \Keystone::fake();            // Auth always succeeds (returns the first active key)
 *   $fake = \Keystone::fake(null);        // Auth always fails (returns null → 401)
 *   $fake = \Keystone::fake($myKeystone); // Auth returns the specified model
 *   $fake->assertAuthenticated();
 *   $fake->assertNotAuthenticated();
 *
 * @method static KeystoneModel|null resolve(\Illuminate\Http\Request $request)
 * @method static KeystoneModel|null findByKeystone(string $rawKey)
 * @method static array{client: string, secret: string, model: KeystoneModel} generate(Model $owner, string $name, array<string, mixed> $options = [])
 * @method static void invalidate(string $client)
 * @method static void flushResolved()
 *
 * @see KeystoneService
 */
final class Keystone extends Facade
{
    /**
     * Return the container binding key for the underlying authentication service.
     *
     * We resolve through the contract interface rather than the concrete class so
     * that `Keystone::fake()` can swap in a test double — the container returns
     * the fake, and the facade (and middleware) both pick it up transparently.
     */
    protected static function getFacadeAccessor(): string
    {
        return KeystoneServiceContract::class;
    }

    /**
     * Replace the real Keystone service with a controllable test double.
     *
     * Installs a {@see KeystoneFake} instance into the container for both the
     * concrete class and the contract interface, ensuring that the middleware
     * (which injects the contract) and the facade (which resolves the contract)
     * both see the same fake instance during tests.
     *
     * Pass a specific Keystone model to have every `resolve()` call return that
     * key (simulating a successful authentication). Pass null to have every
     * `resolve()` call return null (simulating a failed authentication → 401).
     *
     * @param  KeystoneModel|null  $stubbedKey  The key the fake will return from resolve().
     *                                     null = all auth attempts fail.
     */
    public static function fake(?KeystoneModel $stubbedKey = null): KeystoneFake
    {
        $fake = new KeystoneFake($stubbedKey);

        // Replace both the contract and concrete bindings so every injection
        // path (middleware, direct container resolution, facade) uses the fake
        static::swap($fake);
        app()->instance(KeystoneService::class,         $fake);
        app()->instance(KeystoneServiceContract::class, $fake);

        return $fake;
    }
}
