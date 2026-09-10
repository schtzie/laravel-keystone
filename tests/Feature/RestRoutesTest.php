<?php

declare(strict_types=1);

use Schtzie\Keystone\Tests\Fixtures\User;

// ── REST routes ────────────────────────────────────────────────────────────
// These tests use actingAs() to simulate an authenticated session user.
// The controller reads $request->user() which actingAs() populates via the guard.

beforeEach(function (): void {
    // Register the REST routes directly (bypassing keystone.routes.enabled toggle)
    $prefix = 'keystone';

    \Illuminate\Support\Facades\Route::prefix($prefix)
        ->name('keystones.')
        ->group(function (): void {
            \Illuminate\Support\Facades\Route::get('keys', [\Schtzie\Keystone\Http\Controllers\KeystoneController::class, 'index'])->name('index');
            \Illuminate\Support\Facades\Route::post('keys', [\Schtzie\Keystone\Http\Controllers\KeystoneController::class, 'store'])->name('store');
            \Illuminate\Support\Facades\Route::delete('keys/{keystone}', [\Schtzie\Keystone\Http\Controllers\KeystoneController::class, 'destroy'])->name('destroy');
            \Illuminate\Support\Facades\Route::post('keys/{keystone}/rotate', [\Schtzie\Keystone\Http\Controllers\KeystoneController::class, 'rotate'])->name('rotate');
        });
});

it('keystones.index lists active keys for the authenticated user', function (): void {
    $user = User::create(['name' => 'Test']);
    $user->createKeystone('Key 1');
    $user->createKeystone('Key 2');

    $this->actingAs($user)->getJson('/keystone/keys')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('keystones.store creates a new key and returns credentials', function (): void {
    $user = User::create(['name' => 'Test']);

    $response = $this->actingAs($user)->postJson('/keystone/keys', [
        'name'   => 'New Key',
        'scopes' => ['read'],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'name', 'client', 'secret', 'scopes', 'expires_at', 'created_at']]);

    expect($user->keystones()->count())->toBe(1);
});

it('keystones.store rejects missing name', function (): void {
    $user = User::create(['name' => 'Test']);

    $this->actingAs($user)->postJson('/keystone/keys', [])->assertStatus(422);
});

it('keystones.destroy revokes the specified key', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My Key');

    $this->actingAs($user)->deleteJson("/keystone/keys/{$result['model']->id}")
        ->assertOk();

    expect($result['model']->fresh()->revoked_at)->not->toBeNull();
});

it('keystones.destroy returns 404 for a key that does not belong to the user', function (): void {
    $user1  = User::create(['name' => 'User 1']);
    $user2  = User::create(['name' => 'User 2']);
    $result = $user1->createKeystone('User1 Key');

    $this->actingAs($user2)->deleteJson("/keystone/keys/{$result['model']->id}")
        ->assertNotFound();
});

it('keystones.rotate rotates the key and returns new credentials', function (): void {
    $user   = User::create(['name' => 'Test']);
    $result = $user->createKeystone('My Key');

    $response = $this->actingAs($user)->postJson("/keystone/keys/{$result['model']->id}/rotate");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'client', 'secret']]);

    $data = $response->json('data');
    expect($data['client'])->not->toBe($result['client']);
});

it('keystones.index returns 401 when unauthenticated', function (): void {
    $this->getJson('/keystone/keys')->assertStatus(401);
});
