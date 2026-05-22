<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Schatzie\Keystone\Cache\ApiKeyCacheRepository;
use Schatzie\Keystone\Tests\Fixtures\User;

function createPruneUser(): User
{
    return User::create(['name' => 'Prune Test User']);
}

it('prunes revoked keys older than the configured retention period', function (): void {
    $user = createPruneUser();

    // Active key
    $active = $user->createApiKey('Active Key');

    // Revoked recently (within 30 days)
    $recentRevoked = $user->createApiKey('Recent Revoked Key');
    $recentRevoked['model']->update(['revoked_at' => now()->subDays(10)]);

    // Revoked long ago (older than 30 days)
    $oldRevoked = $user->createApiKey('Old Revoked Key');
    $oldRevoked['model']->update(['revoked_at' => now()->subDays(31)]);

    Artisan::call('keystone:prune');

    $this->assertDatabaseHas('api_keys', ['id' => $active['model']->id]);
    $this->assertDatabaseHas('api_keys', ['id' => $recentRevoked['model']->id]);
    $this->assertDatabaseMissing('api_keys', ['id' => $oldRevoked['model']->id]);
});

it('prunes revoked keys based on the --days option', function (): void {
    $user = createPruneUser();

    // Revoked 5 days ago
    $recentRevoked = $user->createApiKey('Recent Revoked Key');
    $recentRevoked['model']->update(['revoked_at' => now()->subDays(5)]);

    // Revoked 10 days ago
    $olderRevoked = $user->createApiKey('Older Revoked Key');
    $olderRevoked['model']->update(['revoked_at' => now()->subDays(10)]);

    // Override days to 7, should delete the 10-days-old key but keep the 5-days-old one
    Artisan::call('keystone:prune', ['--days' => 7]);

    $this->assertDatabaseHas('api_keys', ['id' => $recentRevoked['model']->id]);
    $this->assertDatabaseMissing('api_keys', ['id' => $olderRevoked['model']->id]);
});

it('evicts cache entries for pruned keys', function (): void {
    $user = createPruneUser();

    $oldRevoked = $user->createApiKey('Old Revoked Key');
    $oldRevoked['model']->update(['revoked_at' => now()->subDays(31)]);

    $cache = app(ApiKeyCacheRepository::class);
    $cache->put($oldRevoked['model']);

    // Verify it is in cache
    expect($cache->get($oldRevoked['api_key']))->not->toBeNull();

    Artisan::call('keystone:prune');

    // Verify it is removed from cache
    expect($cache->get($oldRevoked['api_key']))->toBeNull();
});
