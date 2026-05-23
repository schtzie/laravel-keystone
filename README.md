# Laravel Keystone

> Client management for Laravel — attach Clients to any Eloquent model, authenticate requests via HMAC SHA-256, cache keys in Redis for zero-database-per-request throughput, and run natively in single-database or multi-database multi-tenant architectures.

[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Core Concepts](#core-concepts)
  - [HasKeystones Trait](#hasapikeys-trait)
  - [Creating Clients](#creating-api-keys)
  - [HMAC SHA-256 Authentication](#hmac-sha-256-authentication)
  - [Middleware](#middleware)
  - [Scope Enforcement](#scope-enforcement)
  - [Accessing the Authenticated Owner](#accessing-the-authenticated-owner)
- [Redis Caching](#redis-caching)
  - [How It Works](#how-it-works)
  - [Cache Configuration](#cache-configuration)
  - [Manual Invalidation](#manual-invalidation)
- [Key Lifecycle](#key-lifecycle)
  - [Revoking Keys](#revoking-keys)
  - [Rotating Keys](#rotating-keys)
  - [Pruning Old Keys](#pruning-old-keys)
- [Multi-Tenancy (stancl/tenancy v4)](#multi-tenancy-stancltenancy-v4)
  - [Mode: none (default)](#mode-none-default)
  - [Mode: single\_db](#mode-single_db)
  - [Mode: multi\_db](#mode-multi_db)
- [Facade Reference](#facade-reference)
- [Artisan Commands](#artisan-commands)
- [Events & Observers](#events--observers)
- [Testing Your Application](#testing-your-application)

---

## Overview

Laravel Keystone lets any Eloquent model (User, Team, Application, etc.) own one or more Clients. Incoming HTTP requests are authenticated by:

1. Reading a **plain Client** from a header or query parameter
2. Verifying an **HMAC-SHA256 signature** (signed with the secret key)
3. Optionally enforcing **scopes** on the resolved key

Authorized keys are stored in **Redis** to eliminate database round-trips on hot paths. The package integrates transparently with **stancl/tenancy v4** for both single-database and multi-database multi-tenant setups.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `11.x`, `12.x`, or `13.x` |
| Redis (recommended) | Any version supported by `illuminate/redis` |
| stancl/tenancy (optional) | `^4.0` |

---

## Installation

```bash
composer require schatzie/laravel-keystone
```

The service provider and `Keystone` facade are auto-discovered via `composer.json`.

### Publish configuration

```bash
php artisan vendor:publish --tag=keystone-config
```

### Publish and run migrations

**For standard (no tenancy) or multi-database tenancy:**
```bash
php artisan vendor:publish --tag=keystone-migrations
php artisan migrate
```

**For single-database tenancy** (adds `tenant_id` column):
```bash
php artisan vendor:publish --tag=keystone-migrations-single-db
php artisan migrate
```

---

## Configuration

After publishing, edit `config/keystone.php`:

```php
return [
    // Database table name
    'table'  => 'keystoneables',

    // Prefix prepended to every generated client value
    'prefix' => 'ks_',

    // Byte length of randomly generated key / secret (hex output = length * 2)
    'key_length' => 40,

    // Header the client sends the plain Client in
    'header' => 'X-Client-Id',

    // Fallback query parameter (used when header is absent)
    'query_param' => 'client',

    // Header the client sends the HMAC-SHA256 signature in
    'signature_header' => 'X-API-Signature',

    // Laravel auth guard to log the key owner into (null = skip)
    'guard' => null,

    // Default scopes assigned to new keys when none are specified
    'default_scopes' => [],

    'cache' => [
        'enabled'        => true,
        'store'          => env('KEYSTONE_CACHE_STORE', 'redis'),
        'ttl'            => 3600,   // seconds (null = no expiry)
        'prefix'         => 'keystone',
        'warm_on_miss'   => true,   // populate Redis on DB hit
        'refresh_on_use' => true,   // re-warm Redis after each auth
    ],

    'tenancy' => [
        // 'none' | 'single_db' | 'multi_db'
        'mode'                       => env('KEYSTONE_TENANCY_MODE', 'none'),
        'tenant_id_column'           => 'tenant_id',
        'auto_register_bootstrapper' => true,
    ],

    'prune_revoked_after_days' => 30,
];
```

---

## Quick Start

### 1. Add the trait to your model

```php
use Schatzie\Keystone\Traits\HasKeystones;

class User extends Model
{
    use HasKeystones;
}
```

### 2. Generate an Client pair

```php
$user = User::find(1);

$result = $user->createKeystone('My Mobile App');

// Show these to the client ONCE — never store the secret in cleartext again
echo $result['client'];    // ks_a1b2c3d4...  (plain key)
echo $result['secret']; // f9e8d7c6...     (signing secret)
```

### 3. Protect routes

```php
Route::middleware('api.key')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
});
```

### 4. Client sends requests

The client must:
- Send the plain `client` in the `X-Client-Id` header
- Compute `hash_hmac('sha256', $client, $secret)` and send it in `X-API-Signature`

```
GET /profile HTTP/1.1
X-Client-Id: ks_a1b2c3d4...
X-API-Signature: 9f86d081...
```

---

## Core Concepts

### HasKeystones Trait

Add this trait to any Eloquent model to give it Client management:

```php
use Schatzie\Keystone\Traits\HasKeystones;

class Team extends Model
{
    use HasKeystones;
}

class Application extends Model
{
    use HasKeystones;
}
```

The trait is **polymorphic** — any number of model types can own keys, and they all share the same `keystoneables` table via the `keystoneable_type` / `keystoneable_id` columns.

---

### Creating Clients

```php
// Basic — no expiry, no scopes
$result = $user->createKeystone('Production Key');

// With scopes
$result = $user->createKeystone('Read-Only Key', ['read']);

// With expiry
$result = $user->createKeystone(
    'Temporary Key',
    ['read', 'write'],
    now()->addDays(30)->toImmutable()
);

// Return value
$result['client'];    // plain key  — give to client, stored in DB as-is
$result['secret']; // plain secret — show once, stored in DB as-is
$result['model'];      // the persisted Keystone Eloquent model
```

> **Security note:** Both the plain Client and the plain secret are stored in the database. The authentication security comes from the HMAC-SHA256 signature requirement — possessing only the Client is never sufficient to authenticate.

---

### HMAC SHA-256 Authentication

Every authenticated request must include a **signature**:

```
signature = hash_hmac('sha256', client, secret)
```

The middleware recomputes this on the server side and rejects requests where the signatures don't match. `hash_equals()` is used to prevent timing attacks.

**Example client code (PHP):**
```php
$client    = 'ks_a1b2c3d4...';
$secret = 'f9e8d7c6...';
$signature = hash_hmac('sha256', $client, $secret);

Http::withHeaders([
    'X-Client-Id'       => $client,
    'X-API-Signature' => $signature,
])->get('https://your-app.com/api/profile');
```

**Example client code (JavaScript):**
```js
const crypto  = require('crypto');
const client  = 'ks_a1b2c3d4...';
const secret  = 'f9e8d7c6...';
const sig     = crypto.createHmac('sha256', secret).update(client).digest('hex');

fetch('/api/profile', {
    headers: {
        'X-Client-Id':       client,
        'X-API-Signature': sig,
    },
});
```

---

### Middleware

Register the middleware on any route or group:

```php
// Using the alias (registered automatically)
Route::middleware('api.key')->group(fn () => ...);

// In bootstrap/app.php (global)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Schatzie\Keystone\Http\Middleware\AuthenticateWithKeystone::class);
})
```

**401 response format:**
```json
{ "message": "Unauthorized." }
```

---

### Scope Enforcement

Pass scope names as middleware parameters. The client's key must have **all** listed scopes:

```php
// Key must have 'read' scope
Route::middleware('api.key:read')->get('/items', ...);

// Key must have both 'read' AND 'write'
Route::middleware('api.key:read,write')->post('/items', ...);
```

Assign scopes when creating a key:

```php
$result = $user->createKeystone('Admin Key', ['read', 'write', 'delete']);
```

Missing scope returns:
```json
{ "message": "Insufficient scope." }
```

---

### Accessing the Authenticated Owner

After successful authentication, the resolved **keystoneable owner** is available in several ways:

```php
// 1. From the request attributes
$owner = $request->attributes->get('keystoneable');

// 2. Resolved out of the IoC container by class name
$user = app(User::class);

// 3. Via the Keystone facade
$client = Keystone::resolve($request);  // returns Keystone model
$owner  = $client->keystoneable;         // the polymorphic owner

// 4. Via a route model binding helper in the controller
public function show(Request $request): JsonResponse
{
    $user = $request->attributes->get('keystoneable');
    return response()->json(['name' => $user->name]);
}
```

---

## Redis Caching

### How It Works

The resolution pipeline on every authenticated request:

```
1. Read X-Client-Id header / client query param
2. Read X-API-Signature header
        │
        ▼
3. In-memory map (per-request, cleared on tenant switch)
        │ miss
        ▼
4. Redis lookup  ─── hit ──► verify HMAC → authorize
        │ miss
        ▼
5. Database query
        │ found
        ▼
6. Write to Redis (warm_on_miss=true)
        │
        ▼
7. Verify HMAC → authorize
        │
        ▼
8. terminate(): write last_used_at + IP to DB, re-warm Redis
```

The `markUsed()` database write happens in `terminate()` — **after** the response is already sent to the client, so it adds zero latency to API responses.

---

### Cache Configuration

```php
// config/keystone.php
'cache' => [
    'enabled'        => true,           // false = always hit the DB
    'store'          => 'redis',        // any Laravel cache store
    'ttl'            => 3600,           // entry lifetime in seconds
    'warm_on_miss'   => true,           // write to Redis on DB hit
    'refresh_on_use' => true,           // re-warm after each successful auth
],
```

**Redis key format (no tenancy):**
```
keystone:key:{client}
keystone:owner:{ModelClass}:{id}
```

**Redis key format (with tenancy):**
```
keystone:{tenant_id}:key:{client}
keystone:{tenant_id}:owner:{ModelClass}:{id}
```

---

### Manual Invalidation

```php
use Schatzie\Keystone\Cache\KeystoneKeyCacheRepository;

$cache = app(KeystoneKeyCacheRepository::class);

// Evict a single key
$cache->forget($client->client);

// Evict all keys owned by a model
$cache->forgetOwner(User::class, $user->id);
```

Cache entries are **automatically evicted** on:
- `Keystone::updated` (e.g. revocation) → the `KeystoneServiceProvider` Eloquent observer handles this
- `Keystone::deleted`
- `$owner->revokeAllKeystones()`

---

## Key Lifecycle

### Revoking Keys

```php
// Revoke a specific key by model instance
$user->revokeKeystone($client);

// Revoke by primary key ID
$user->revokeKeystone(42);

// Revoke all keys for this owner
$user->revokeAllKeystones();
```

Revocation is a **soft operation** — it sets `revoked_at` to the current timestamp. The key remains in the database until pruned. Revoked keys are immediately evicted from Redis via the `Keystone::updated` observer.

---

### Rotating Keys

Creates a new key pair and revokes the old one atomically in a database transaction:

```php
$old = $user->keystones()->first();

$new = $user->rotateKeystone($old);

// Old key is revoked, evicted from Redis
// New key is returned with fresh client + secret
echo $new['client'];
echo $new['secret'];
```

---

### Pruning Old Keys

The `keystone:prune` command permanently deletes revoked keys older than the configured retention period and evicts their Redis entries:

```bash
# Uses prune_revoked_after_days from config (default: 30)
php artisan keystone:prune

# Override retention period
php artisan keystone:prune --days=7
```

Schedule it in your console kernel:

```php
// routes/console.php
Schedule::command('keystone:prune')->daily();
```

---

## Multi-Tenancy (stancl/tenancy v4)

Keystone supports three tenancy modes, configured via the `KEYSTONE_TENANCY_MODE` environment variable.

### Mode: `none` (default)

Standard single-tenant setup. No tenant awareness.

```env
KEYSTONE_TENANCY_MODE=none
```

```php
// Migration: vendor:publish --tag=keystone-migrations
// No changes to your routes or middleware order
Route::middleware('api.key')->group(fn () => ...);
```

---

### Mode: `single_db`

All tenants share one database. A `tenant_id` column on `keystoneables` isolates records. A global scope (`TenantScope`) automatically appends `WHERE tenant_id = ?` to every query based on the active tenant context.

```env
KEYSTONE_TENANCY_MODE=single_db
```

```bash
# Use the single_db migration (includes tenant_id column + composite index)
php artisan vendor:publish --tag=keystone-migrations-single-db
php artisan migrate
```

**Route setup** — the tenancy identification middleware must run before `api.key`:

```php
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

Route::middleware([
    InitializeTenancyByRequestData::class,  // sets tenant() context
    'api.key',                              // then Keystone filters by tenant_id
])->group(fn () => ...);
```

**Creating keys** — `tenant_id` is stamped automatically:

```php
// Tenant context is already initialized by stancl
$team = Team::find(1);
$result = $team->createKeystone('Team Key');

// The stored client row will have tenant_id = tenant()->getTenantKey()
```

**How isolation works:**

| Layer | Mechanism |
|---|---|
| Database | `TenantScope` global scope → `WHERE tenant_id = ?` on all Keystone queries |
| Redis | Cache keys are namespaced as `keystone:{tenant_id}:key:...` |
| In-memory | `KeystoneBootstrapper::bootstrap()` clears the in-memory resolved map on tenant switch |

---

### Mode: `multi_db`

Each tenant has its own separate database. stancl/tenancy switches the Eloquent connection automatically. Keystone queries just pick up the active connection — no `tenant_id` column needed.

```env
KEYSTONE_TENANCY_MODE=multi_db
```

```bash
# Use the standard migration — run it in each tenant's database via stancl
php artisan vendor:publish --tag=keystone-migrations
php artisan tenants:migrate  # stancl/tenancy command
```

**Route setup:**

```php
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

Route::middleware([
    InitializeTenancyByDomain::class,  // switches DB connection + Redis prefix
    'api.key',
])->group(fn () => ...);
```

**How isolation works:**

| Layer | Mechanism |
|---|---|
| Database | stancl switches the Eloquent DB connection before your routes run |
| Redis | stancl's `RedisTenancyBootstrapper` switches the Redis connection prefix; Keystone adds `keystone:{tenant_id}:` on top |
| In-memory | `KeystoneBootstrapper` flushes the resolved map on every tenant switch (critical for Octane / queue workers) |

---

### KeystoneBootstrapper

`KeystoneBootstrapper` is registered automatically when `stancl/tenancy` is installed and `tenancy.auto_register_bootstrapper = true` (default). It implements `Stancl\Tenancy\Contracts\TenancyBootstrapper` and is appended to stancl's bootstrapper stack:

```php
// Auto-registered — no manual config needed
// You can disable it and register manually:

// config/keystone.php
'tenancy' => [
    'auto_register_bootstrapper' => false,
],

// config/tenancy.php
'bootstrappers' => [
    ...
    \Schatzie\Keystone\Tenancy\KeystoneBootstrapper::class,
],
```

It calls `KeystoneService::flushResolved()` on both `bootstrap()` and `revert()`, ensuring in-memory key state never leaks between tenants in long-lived PHP processes (Octane, queue workers, etc.).

---

## Facade Reference

```php
use Schatzie\Keystone\Facades\Keystone;

// Resolve an Keystone from a request (full pipeline: Redis → DB → HMAC verify)
$client = Keystone::resolve($request);   // Keystone|null

// Look up a key by its plain value (no HMAC check)
$client = Keystone::findByKeystone('ks_abc...'); // Keystone|null

// Generate a key for a model (delegates to $owner->createKeystone())
$result = Keystone::generate($user, 'My Key', [
    'scopes'     => ['read'],
    'expires_at' => now()->addYear()->toImmutable(),
]);

// Evict from both in-memory map and Redis
Keystone::invalidate('ks_abc...');

// Clear the in-memory resolved map (called automatically on tenant switch)
Keystone::flushResolved();
```

---

## Artisan Commands

| Command | Description |
|---|---|
| `keystone:prune` | Delete revoked keys older than `prune_revoked_after_days` and evict their cache entries |
| `keystone:prune --days=7` | Override the retention period |

---

## Events & Observers

Keystone hooks into Eloquent model events to keep Redis in sync automatically:

| Event | Action |
|---|---|
| `Keystone::updated` | Evicts the key from Redis (fires on `revoke()`) |
| `Keystone::deleted` | Evicts the key from Redis (fires on hard-delete / pruning) |

These are registered in `KeystoneServiceProvider::boot()` without requiring you to publish or configure anything.

---

## Testing Your Application

### Asserting a key was created

```php
$result = $user->createKeystone('Test Key');

$this->assertDatabaseHas('keystoneables', [
    'client' => $result['client'],
    'name'    => 'Test Key',
]);
```

### Asserting authenticated requests

```php
$result = $user->createKeystone('Test Key');

$sig = hash_hmac('sha256', $result['client'], $result['secret']);

$this->getJson('/api/protected', [
    'X-Client-Id'       => $result['client'],
    'X-API-Signature' => $sig,
])->assertOk();
```

### Testing with scopes

```php
$result = $user->createKeystone('Read-Only', ['read']);

$sig = hash_hmac('sha256', $result['client'], $result['secret']);

// Route requires 'write' — should fail
$this->getJson('/api/write-resource', [
    'X-Client-Id'       => $result['client'],
    'X-API-Signature' => $sig,
])->assertUnauthorized();
```

### Disabling the cache in tests

Add this to your test's `defineEnvironment()` or in `phpunit.xml`:

```php
config(['keystone.cache.enabled' => false]);
```

Or use the `array` cache store (set by default in the test `TestCase`):

```php
config(['keystone.cache.store' => 'array']);
```

---

## License

MIT — see [LICENSE](LICENSE).
