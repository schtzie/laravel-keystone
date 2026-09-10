# Laravel Keystone

> Client management for Laravel — attach Clients to any Eloquent model, authenticate requests via HMAC SHA-256 with replay-attack protection and payload signing, cache keys in Redis for zero-database-per-request throughput, enforce per-key rate limits using fixed, sliding-window, or token-bucket strategies, and run natively in single-database or multi-database multi-tenant architectures.


[![Latest Version on Packagist](https://img.shields.io/packagist/v/schtzie/laravel-keystone.svg?style=flat-square)](https://packagist.org/packages/schtzie/laravel-keystone)
[![Total Downloads](https://img.shields.io/packagist/dt/schtzie/laravel-keystone.svg?style=flat-square)](https://packagist.org/packages/schtzie/laravel-keystone)
[![Tests](https://img.shields.io/github/actions/workflow/status/schtzie/laravel-keystone/tests.yml?style=flat-square&label=tests)](https://github.com/schtzie/laravel-keystone/actions/workflows/tests.yml)
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
  - [HasKeystones Trait](#haskeystones-trait)
  - [Creating Clients](#creating-clients)
  - [HMAC SHA-256 Authentication](#hmac-sha-256-authentication)
  - [Middleware](#middleware)
  - [Scope Enforcement](#scope-enforcement)
  - [IP Filtering](#ip-filtering-allowlist--blocklist)
  - [Replay-Attack Protection](#replay-attack-protection)
  - [Payload Signing](#payload-signing-body-integrity)
  - [Accessing the Authenticated Owner](#accessing-the-authenticated-owner)
- [Rate Limiting](#rate-limiting)
  - [Rate Limit Strategies](#rate-limit-strategies)
  - [Per-Key Rate Limits](#per-key-rate-limits)
  - [Rate Limit Headers](#rate-limit-headers)
- [Caching](#caching)
  - [How It Works](#how-it-works)
  - [Cache Configuration](#cache-configuration)
  - [Manual Invalidation](#manual-invalidation)
- [Key Lifecycle](#key-lifecycle)
  - [Revoking Keys](#revoking-keys)
  - [Rotating Keys](#rotating-keys)
  - [Grace Periods](#grace-periods)
  - [Pruning Old Keys](#pruning-old-keys)
- [Access Logging](#access-logging)
- [Analytics](#analytics)
- [Events](#events)
- [REST API Routes](#rest-api-routes)
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

Authorized keys are cached (e.g. in **Redis**) to eliminate database round-trips on hot paths. The package integrates transparently with **stancl/tenancy v4** for both single-database and multi-database multi-tenant setups.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `11.x`, `12.x`, or `13.x` |
| Redis (recommended) | Any version supported by `illuminate/redis` |
| stancl/tenancy (optional) | `^4.0` |

> **Note:** If you are using the `redis` cache driver, you must either install the **PhpRedis** PHP extension via PECL or install the **predis/predis** package (`composer require predis/predis`).

---

## Installation

```bash
composer require schtzie/laravel-keystone
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
        'warm_on_miss'   => true,   // populate cache on DB hit
        'refresh_on_use' => true,   // re-warm cache after each auth
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
use Schtzie\Keystone\Traits\HasKeystones;

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
use Schtzie\Keystone\Traits\HasKeystones;

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

// With IP allowlist / blocklist & custom rate limit
$result = $user->createKeystone(
    'Production Key',
    ['read', 'write'],
    null,
    [
        'ip_allowlist' => ['192.168.1.0/24', '10.0.0.5'],
        'ip_blocklist' => ['192.168.1.100'],
        'rate_limit'   => 120, // max 120 requests/min
    ]
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
    $middleware->append(\Schtzie\Keystone\Http\Middleware\AuthenticateWithKeystone::class);
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

### IP Filtering (Allowlist & Blocklist)

Restrict API key access by client IP addresses. You can pass exact IPs or CIDR subnet notation in `ip_allowlist` and `ip_blocklist`:

```php
// Create a key restricted to a specific IP or subnet range
$result = $user->createKeystone(
    name: 'Internal Webhook Key',
    scopes: ['read', 'write'],
    options: [
        'ip_allowlist' => ['192.168.1.0/24', '203.0.113.50'],
        'ip_blocklist' => ['192.168.1.99'],
    ]
);
```

- **Allowlist (`ip_allowlist`)**: Only requests originating from matching IP addresses or CIDR ranges are allowed. If the client IP is not in the allowlist, the middleware returns `403 Forbidden`:
  ```json
  { "message": "IP address not allowed." }
  ```
- **Blocklist (`ip_blocklist`)**: Requests originating from blacklisted IPs or subnets are blocked, returning `403 Forbidden`:
  ```json
  { "message": "IP address blocked." }
  ```
- **CIDR Subnet Support**: Both allowlists and blocklists support CIDR mask notation (e.g. `10.0.0.0/8`, `192.168.1.0/24`, `172.16.0.0/12`) powered by Symfony's `IpUtils`.

---

### Replay-Attack Protection

Enable timestamp-based replay detection to reject requests that are captured and replayed after the fact:

```php
// config/keystone.php
'replay_protection' => [
    'enabled'          => true,
    'timestamp_header' => 'X-Timestamp',  // header the client sends
    'window_seconds'   => 30,             // reject if |now - timestamp| > window
],
```

The client must include the current Unix timestamp (seconds) in the `X-Timestamp` header. Requests outside the window are rejected **before** any cache or database lookup:

```php
Http::withHeaders([
    'X-Client-Id'     => $client,
    'X-API-Signature' => hash_hmac('sha256', $client, $secret),
    'X-Timestamp'     => time(),
])->get('/api/resource');
```

---

### Payload Signing (Body Integrity)

The `api.key.payload` middleware verifies the request body has not been tampered with in transit. Stack it **after** `api.key` (which resolves the secret):

```php
Route::middleware(['api.key', 'api.key.payload'])->group(function () {
    Route::post('/webhooks/inbound', [WebhookController::class, 'handle']);
});
```

The client computes `hash_hmac('sha256', $body, $secret)` and sends it in the `X-Body-Hash` header:

```php
$body = json_encode($payload);

Http::withHeaders([
    'X-Client-Id'     => $client,
    'X-API-Signature' => hash_hmac('sha256', $client, $secret),
    'X-Body-Hash'     => hash_hmac('sha256', $body, $secret),
    'Content-Type'    => 'application/json',
])->withBody($body, 'application/json')->post('/api/webhooks/inbound');
```

A mismatch returns `422 Unprocessable Content`. Configure via `keystone.payload_signing.*`:

```php
'payload_signing' => [
    'header'           => 'X-Body-Hash',
    'require_on_empty' => false,  // skip check for requests with no body (GET/HEAD)
],
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

## Caching

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
4. Cache lookup   ─── hit ──► verify HMAC → authorize
        │ miss
        ▼
5. Database query
        │ found
        ▼
6. Write to Cache (warm_on_miss=true)
        │
        ▼
7. Verify HMAC → authorize
        │
        ▼
8. terminate(): re-warm Cache entry (zero-database-write architecture)
```

Key lookups are cached (using your configured Laravel cache store) to achieve zero-database-per-request throughput. Optional cache re-warming happens in `terminate()` — **after** the response is already sent to the client, adding zero latency to API responses.

---

### Cache Configuration

```php
// config/keystone.php
'cache' => [
    'enabled'        => true,           // false = always hit the DB
    'store'          => 'redis',        // any Laravel cache store
    'ttl'            => 3600,           // entry lifetime in seconds
    'warm_on_miss'   => true,           // write to cache on DB hit
    'refresh_on_use' => true,           // re-warm after each successful auth
],
```

**Cache key format (no tenancy):**
```
keystone:key:{client}
keystone:owner:{ModelClass}:{id}
```

**Cache key format (with tenancy):**
```
keystone:{tenant_id}:key:{client}
keystone:{tenant_id}:owner:{ModelClass}:{id}
```

---

### Manual Invalidation

```php
use Schtzie\Keystone\Cache\KeystoneKeyCacheRepository;

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

## Rate Limiting

### Rate Limit Strategies

Keystone supports three pluggable rate-limiting strategies, configured via `keystone.rate_limit_strategy`:

| Strategy | Config value | Description |
|---|---|---|
| **Fixed Window** | `fixed_window` (default) | Standard counter per window; delegates to Laravel's `RateLimiter`. Zero Redis dependency. |
| **Sliding Window** | `sliding_window` | Redis sorted-set approach; eliminates boundary burst spikes. Falls back to fixed window on non-Redis stores. |
| **Token Bucket** | `token_bucket` | Atomic Lua-scripted bucket on Redis; allows bursting up to capacity while guaranteeing average rate. Falls back to fixed window on non-Redis stores. |

```php
// config/keystone.php
'rate_limit_strategy' => env('KEYSTONE_RATE_LIMIT_STRATEGY', 'fixed_window'),
```

### Per-Key Rate Limits

Set a global fallback limit in config, and override per key when creating:

```php
// config/keystone.php — global fallback
'rate_limit'                 => 60,   // requests per window
'rate_limit_window_seconds'  => 60,   // window size in seconds

// Per-key override at creation time
$result = $user->createKeystone('High-Volume Key', [], null, [
    'rate_limit' => 1000,  // overrides the global config
]);
```

### Rate Limit Headers

All authenticated responses include standard rate-limit headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
Retry-After: 15   (only present when rate limit is exceeded — 429 response)
```

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

// Old key is revoked, evicted from cache
// New key is returned with fresh client + secret
echo $new['client'];
echo $new['secret'];
```

---

### Grace Periods

When rotating keys, configure a grace period so consumers can pick up the new credentials without a service interruption:

```php
// config/keystone.php
'rotation_grace_seconds' => 300,  // old key valid for 5 more minutes after rotation
```

During the grace period, the old key remains authenticated but a `grace_expires_at` timestamp is stamped on its record. `keystone:list` displays keys in grace period with a **Grace Period** status label.

---

### Automated Deletion

When an owner model (e.g. `User`) is deleted, its keystones are automatically managed by the trait:
- **Soft Deletes**: If your model uses Laravel's `SoftDeletes`, all active keystones are automatically revoked (which preserves history) and evicted from the cache.
- **Hard Deletes**: If the model is permanently deleted, all related keystones are completely deleted from the database to prevent orphaned rows, and the cache is immediately evicted.

---

### Pruning Old Keys

The `keystone:prune` command permanently deletes revoked keys older than the configured retention period and evicts their Redis entries:

```bash
# Uses prune_revoked_after_days from config (default: 30)
php artisan keystone:prune

# Override retention period
php artisan keystone:prune --days=7

# Also prune old access log rows
php artisan keystone:prune --access-logs --log-days=90
```

Schedule it in your console kernel:

```php
// routes/console.php
Schedule::command('keystone:prune')->daily();
```

---

## Access Logging

Enable structured access logging to record every authentication event:

```php
// config/keystone.php
'access_log' => [
    'enabled' => true,
    'driver'  => 'database',  // 'database' | 'log' | 'both'
    'table'   => 'keystone_access_logs',
    'channel' => null,        // Laravel log channel name (null = default)
],
```

Publish and run the access log migration:

```bash
php artisan vendor:publish --tag=keystone-migrations-access-log
php artisan migrate
```

Events recorded:

| Event | Trigger |
|---|---|
| `authenticated` | Request passed all checks |
| `rejected_invalid` | Key not found, revoked, expired, or HMAC mismatch |
| `rejected_replay` | Timestamp outside the replay-protection window |
| `rejected_ip` | IP address failed allowlist or blocklist |
| `rejected_scope` | Key lacked a required scope |
| `rate_limited` | Key exceeded its rate limit |

Log writes are wrapped in a `try/catch` — a broken storage backend never interrupts the request lifecycle.

---

## Analytics

Query key usage statistics via the `KeystoneAnalytics` facade (requires access logging to be enabled):

```php
use Schtzie\Keystone\Facades\KeystoneAnalytics;

$key = Keystone::findByKeystone('ks_abc...');

// Day-by-day breakdown grouped by event type (last 7 days)
$daily = KeystoneAnalytics::dailyUsage($key, days: 7);

// Top 10 most-used keys (authenticated events only)
$topKeys = KeystoneAnalytics::topKeys(limit: 10);

// Daily totals for all keys owned by a user (last 30 days)
$ownerStats = KeystoneAnalytics::ownerUsage($user, days: 30);

// Rejection summary grouped by reason (last 7 days)
$rejections = KeystoneAnalytics::rejectionSummary($key, days: 7);

// Total authenticated requests in the last 30 days
$total = KeystoneAnalytics::totalRequests(days: 30);
```

---

## Events

Keystone dispatches events at every significant lifecycle moment. Listen to them in your `EventServiceProvider`:

```php
use Schtzie\Keystone\Events\KeystoneAuthenticated;
use Schtzie\Keystone\Events\KeystoneAuthFailed;
use Schtzie\Keystone\Events\KeystoneCreated;
use Schtzie\Keystone\Events\KeystoneRevoked;
use Schtzie\Keystone\Events\KeystoneRotated;
use Schtzie\Keystone\Events\KeystoneRateLimitExceeded;
use Schtzie\Keystone\Events\KeystoneExpired;

protected $listen = [
    KeystoneAuthenticated::class      => [SendUsageMetric::class],
    KeystoneAuthFailed::class         => [AlertOnRepeatedFailures::class],
    KeystoneRateLimitExceeded::class  => [NotifyRateLimitBreach::class],
    KeystoneCreated::class            => [SendWelcomeEmail::class],
    KeystoneRevoked::class            => [AuditKeyRevocation::class],
    KeystoneRotated::class            => [NotifyKeyRotation::class],
    KeystoneExpired::class            => [NotifyKeyExpiry::class],
];
```

| Event class | Payload |
|---|---|
| `KeystoneAuthenticated` | `$keystone`, `$request` |
| `KeystoneAuthFailed` | `$reason` (string), `$request` |
| `KeystoneRateLimitExceeded` | `$keystone`, `$request` |
| `KeystoneCreated` | `$keystone` |
| `KeystoneRevoked` | `$keystone` |
| `KeystoneRotated` | `$oldKeystone`, `$newKeystone` |
| `KeystoneExpired` | `$keystone`, `$request` |

---

## REST API Routes

Keystone ships optional ready-made routes for client-side key management. Enable them in config:

```php
// config/keystone.php
'routes' => [
    'enabled'    => true,
    'prefix'     => 'keystones',    // accessible at /keystones
    'middleware' => ['auth'],       // standard Laravel auth guard
],
```

| Method | Path | Route name | Description |
|---|---|---|---|
| `GET` | `/keystones` | `keystones.index` | List active keys for the authenticated user |
| `POST` | `/keystones` | `keystones.store` | Create a new key pair |
| `DELETE` | `/keystones/{id}` | `keystones.destroy` | Revoke a key |
| `POST` | `/keystones/{id}/rotate` | `keystones.rotate` | Rotate a key |

**Create key request body:**
```json
{
    "name": "My Mobile App",
    "scopes": ["read", "write"],
    "expires_at": "2027-01-01",
    "description": "Used by the iOS app"
}
```

**Create key response:**
```json
{
    "id": 42,
    "name": "My Mobile App",
    "client": "ks_a1b2c3...",
    "secret": "f9e8d7...",
    "scopes": ["read", "write"],
    "expires_at": "2027-01-01T00:00:00.000000Z",
    "created_at": "2026-09-10T09:00:00.000000Z"
}
```

> The `secret` is only returned **once** on creation. Store it immediately.

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
| Cache | Cache keys are namespaced as `keystone:{tenant_id}:key:...` |
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
| Cache | stancl's `RedisTenancyBootstrapper` (if using Redis) or cache manager handles prefixing; Keystone adds `keystone:{tenant_id}:` on top |
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
    \Schtzie\Keystone\Tenancy\KeystoneBootstrapper::class,
],
```

It calls `KeystoneService::flushResolved()` on both `bootstrap()` and `revert()`, ensuring in-memory key state never leaks between tenants in long-lived PHP processes (Octane, queue workers, etc.).

---

## Facade Reference

```php
use Schtzie\Keystone\Facades\Keystone;

// Resolve an Keystone from a request (full pipeline: Redis → DB → HMAC verify)
$client = Keystone::resolve($request);   // Keystone|null

// Look up a key by its plain value (no HMAC check)
$client = Keystone::findByKeystone('ks_abc...'); // Keystone|null

// Generate a key for a model (delegates to $owner->createKeystone())
$result = Keystone::generate($user, 'My Key', [
    'scopes'     => ['read'],
    'expires_at' => now()->addYear()->toImmutable(),
]);

// Evict from both in-memory map and cache
Keystone::invalidate('ks_abc...');

// Clear the in-memory resolved map (called automatically on tenant switch)
Keystone::flushResolved();
```

---

## Artisan Commands

| Command | Description |
|---|---|
| `keystone:generate {model} {id} {name}` | Generate a new key pair for any Eloquent model owner |
| `keystone:revoke {client}` | Immediately revoke a key by its client ID (with confirmation; `--force` to skip) |
| `keystone:list` | List keys with status, owner, scopes, expiry (filterable by `--active`, `--revoked`, `--owner-type`, `--owner-id`) |
| `keystone:status` | Display key counts, cache configuration, and access-log statistics |
| `keystone:rotate-expiring` | Auto-rotate keys expiring within N days (`--days=7`, `--dry-run`) |
| `keystone:prune` | Delete revoked keys older than `prune_revoked_after_days` and evict their cache entries |
| `keystone:prune --days=7` | Override the retention period |

**Examples:**

```bash
# Generate a key for User ID 1 with scopes
php artisan keystone:generate "App\Models\User" 1 "CI Bot" --scope=read --scope=write

# Revoke a specific key without confirmation prompt
php artisan keystone:revoke ks_abc123... --force

# List all active keys for a specific owner
php artisan keystone:list --owner-type="App\Models\User" --owner-id=1 --active

# Rotate all keys expiring in the next 14 days (preview, then run)
php artisan keystone:rotate-expiring --days=14 --dry-run
php artisan keystone:rotate-expiring --days=14

# Prune old keys and old access logs
php artisan keystone:prune --access-logs --log-days=90

# Display system status dashboard
php artisan keystone:status
```

---


## Events & Observers

Keystone hooks into Eloquent model events to keep the cache in sync automatically:

| Event | Action |
|---|---|
| `Keystone::updated` | Evicts the key from cache (fires on `revoke()`) |
| `Keystone::deleted` | Evicts the key from cache (fires on hard-delete / pruning) |

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

### Testing Helpers

Keystone provides several testing utilities to simplify your test suites:

**1. KeystoneFake** — bypass the real service and mock authentication:

```php
use Schtzie\Keystone\Facades\Keystone;

// Swap the real service for a fake that approves everything
Keystone::fake();

// Swap for a fake that rejects everything
Keystone::fake(null);

// Provide a specific Keystone model for the fake to resolve to
Keystone::fake($specificKey);

// Assertions
Keystone::assertAuthenticatedTimes(2);
Keystone::assertNotAuthenticated();
```

**2. Request Macros** — easily attach headers to a test request:

```php
use Schtzie\Keystone\Testing\KeystoneFactory;

$key = KeystoneFactory::new()->create();

// Attaches X-Client-Id and X-API-Signature headers for this key
$this->actingWithKeystone($key)->getJson('/api/protected')->assertOk();

// Attaches headers and forces specific scopes on the request
$this->actingWithKeystoneScopes($key, ['write'])->postJson('/api/data')->assertOk();
```

**3. KeystoneFactory** — fluent factory for test keys:

```php
use Schtzie\Keystone\Testing\KeystoneFactory;

$expiredKey = KeystoneFactory::new()->expired()->create();
$revokedKey = KeystoneFactory::new()->revoked()->create();
$scopedKey  = KeystoneFactory::new()->withScopes(['admin'])->create();
$metaKey    = KeystoneFactory::new()->withMetadata(['version' => '1.0'])->create();
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
