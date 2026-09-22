---
name: keystone-testing
description: Write automated tests in Pest and PHPUnit for Keystone-protected routes using InteractsWithKeystone, actingWithKeystone, Keystone::fake(), and testing status codes.
---

# 🧪 Keystone Testing

> Modular AI Skill for **Laravel Boost** — Guide for writing clean, ergonomic tests for Keystone-protected routes in Pest and PHPUnit using `InteractsWithKeystone`, `Keystone::fake()`, and factory helpers.

---

## 🧭 When to Use This Skill

Activate this skill whenever you need to:
- Test HTTP endpoints protected by `api.key` or `api.key.payload` middleware.
- Authenticate test requests as an Eloquent owner model (`User`, `Team`, `App`, etc.) without manually managing HMAC signatures or headers.
- Test and assert route scope authorization (`200 OK` vs `403 Forbidden`).
- Mock Keystone authentication with `Keystone::fake()` to isolate controller unit tests from database and cache queries.
- Test rejection states: expired keys, revoked keys, invalid signatures, replay timestamps, and rate limiting (`429 Too Many Requests`).

---

## 🛠️ 1. Test Setup: `InteractsWithKeystone`

Include `Schtzie\Keystone\Testing\InteractsWithKeystone` to gain access to test authentication helpers:

### Pest Setup (`tests/Pest.php`)
```php
use Schtzie\Keystone\Testing\InteractsWithKeystone;

uses(
    Tests\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
    InteractsWithKeystone::class,
)->in('Feature');
```

### PHPUnit Setup (`tests/TestCase.php`)
```php
namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Schtzie\Keystone\Testing\InteractsWithKeystone;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
    use InteractsWithKeystone;
}
```

---

## 🚀 2. Pest Testing Patterns

### Standard Authenticated Request
`actingWithKeystone()` automatically generates an ephemeral key pair for `$owner`, computes the HMAC signature, and registers `X-Client-Id` and `X-API-Signature` on the test request:

```php
use App\Models\Team;

it('returns orders for the authenticated team', function () {
    $team = Team::factory()->create();

    $this->actingWithKeystone($team)
        ->getJson('/api/orders')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'total']]]);
});
```

### Scope Enforcement Testing
Use `actingWithKeystoneScopes()` to test route permission boundaries:

```php
use App\Models\Team;

describe('Order Scopes', function () {
    it('allows read-scoped keys to view orders', function () {
        $team = Team::factory()->create();

        $this->actingWithKeystoneScopes($team, ['orders:read'])
            ->getJson('/api/orders')
            ->assertOk();
    });

    it('blocks read-only keys from creating orders with 403', function () {
        $team = Team::factory()->create();

        $this->actingWithKeystoneScopes($team, ['orders:read'])
            ->postJson('/api/orders', ['total' => 150])
            ->assertForbidden()
            ->assertJson(['message' => 'Insufficient scope.']);
    });

    it('allows keys with both scopes to create orders', function () {
        $team = Team::factory()->create();

        $this->actingWithKeystoneScopes($team, ['orders:read', 'orders:write'])
            ->postJson('/api/orders', ['total' => 150])
            ->assertCreated();
    });
});
```

---

## 🎭 3. Mocking & Isolation with `Keystone::fake()`

When unit testing business logic in controllers or actions, avoid touching the database or Redis by substituting the Keystone service with `Keystone::fake()`.

```php
use App\Models\Team;
use Schtzie\Keystone\Facades\Keystone;
use Schtzie\Keystone\Models\Keystone as KeystoneModel;

describe('Keystone Mocking', function () {
    it('simulates successful authentication without database records', function () {
        $fake = Keystone::fake();

        $this->getJson('/api/orders')->assertOk();

        // Assertions available on the fake instance:
        $fake->assertAuthenticated();
        $fake->assertAuthenticatedTimes(1);
    });

    it('simulates 401 unauthorized rejection', function () {
        $fake = Keystone::fake(null); // Passing null forces resolve() to return null

        $this->getJson('/api/orders')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthorized.']);

        $fake->assertNotAuthenticated();
    });

    it('stubs authentication for a specific model instance', function () {
        $team = Team::factory()->create(['name' => 'Acme Corp']);
        $key  = KeystoneModel::factory()->for($team, 'keystoneable')->create([
            'scopes' => ['admin'],
        ]);

        $fake = Keystone::fake($key);

        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('team', 'Acme Corp');

        $fake->assertAuthenticated();
    });
});
```

---

## 🎯 4. Testing Edge Cases & Security Checks

### 1. Revoked Key Check (`401 Unauthorized`)
```php
it('rejects revoked keys', function () {
    $team = Team::factory()->create();
    $key  = $team->createKeystone('Temporary Key');

    // Revoke the key
    $team->revokeKeystone($key['model']);

    $sig = hash_hmac('sha256', $key['client'], $key['secret']);

    $this->withHeaders([
        'X-Client-Id'     => $key['client'],
        'X-API-Signature' => $sig,
    ])->getJson('/api/orders')
      ->assertUnauthorized();
});
```

### 2. IP Restriction Check (`403 Forbidden`)
```php
it('rejects unauthorized IPs', function () {
    $team = Team::factory()->create();
    $key  = $team->createKeystone('Restricted Key', [], null, [
        'ip_allowlist' => ['10.0.0.1'],
    ]);

    $sig = hash_hmac('sha256', $key['client'], $key['secret']);

    // Attempt request from forbidden IP
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.99'])
        ->withHeaders([
            'X-Client-Id'     => $key['client'],
            'X-API-Signature' => $sig,
        ])
        ->getJson('/api/orders')
        ->assertForbidden()
        ->assertJson(['message' => 'IP address not allowed.']);
});
```

### 3. Payload Tampering Check (`422 Unprocessable`)
```php
it('detects tampered payload bodies', function () {
    $team = Team::factory()->create();
    $key  = $team->createKeystone('Worker Key');

    $originalPayload = json_encode(['amount' => 50]);
    $tamperedPayload = json_encode(['amount' => 5000]);

    $sig      = hash_hmac('sha256', $key['client'], $key['secret']);
    $bodyHash = hash_hmac('sha256', $originalPayload, $key['secret']);

    // Send the hash for $originalPayload, but transmit $tamperedPayload
    $this->withHeaders([
        'X-Client-Id'     => $key['client'],
        'X-API-Signature' => $sig,
        'X-Body-Hash'     => $bodyHash,
        'Content-Type'    => 'application/json',
    ])->call('POST', '/api/charge', [], [], [], [], $tamperedPayload)
      ->assertStatus(422)
      ->assertJson(['message' => 'Payload hash mismatch.']);
});
```

---

## ⚠️ Anti-Patterns & Best Practices

| ❌ Anti-Pattern | ✅ Idiomatic Keystone Pattern |
|---|---|
| Manually hashing `hash_hmac()` and setting headers in every single test method. | Use `$this->actingWithKeystone($owner)` or `$this->actingWithKeystoneScopes($owner, $scopes)`. |
| Running tests without `RefreshDatabase` when creating real keys. | Always use `RefreshDatabase` to ensure temporary keys do not persist across test boundaries. |
| Using generic `$this->actingAs($user)` on routes protected by `api.key`. | `$this->actingAs()` does NOT pass `api.key` middleware; use `$this->actingWithKeystone($owner)`. |
| Mocking the `Keystone` facade with Mockery (`Keystone::shouldReceive()`). | Use the official `Keystone::fake()` helper which swaps both concrete and contract container bindings. |
