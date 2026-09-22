---
name: laravel-keystone-development
description: Manage API client credentials, Eloquent key ownership with HasKeystones, key generation, rotation with grace periods, scopes, custom rate limits, and Artisan commands in Laravel Keystone.
---

# 🔑 Laravel Keystone Development

> Modular AI Skill for **Laravel Boost** — Guide for managing API client credentials, Eloquent model integration, key lifecycles, and Artisan CLI operations in `schtzie/laravel-keystone`.

---

## 🧭 When to Use This Skill

Activate this skill whenever you need to:
- Attach API key / client management to an Eloquent model (`User`, `Team`, `Organization`, `Application`, etc.).
- Generate new credentials with customized scopes, expiration, IP filtering, or per-key rate limits.
- Manage key lifecycles: rotate keys with zero-downtime grace periods, revoke active keys, or prune expired credentials.
- Run or schedule Keystone Artisan commands (`keystone:generate`, `keystone:list`, `keystone:rotate-expiring`, `keystone:revoke`, `keystone:prune`, `keystone:status`, `keystone:warm`).
- Configure or extend the built-in REST API management endpoints.

---

## 🏗️ 1. Eloquent Model Integration

Keystone uses a **polymorphic architecture** (`keystoneables` table), allowing multiple distinct model classes to own API keys simultaneously.

### Adding the Trait
Attach `Schtzie\Keystone\Traits\HasKeystones` to any model:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Schtzie\Keystone\Traits\HasKeystones;

class Team extends Model
{
    use HasFactory;
    use HasKeystones;
    use SoftDeletes; // Fully supported by Keystone
}
```

### Querying Owned Keys
```php
// Retrieve all keystones (Eloquent relationship)
$allKeys = $team->keystones;

// Query active, non-expired keys
$activeKeys = $team->keystones()
    ->whereNull('revoked_at')
    ->where(function ($query) {
        $query->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
    })
    ->get();
```

---

## ⚡ 2. Generating API Keys

Call `createKeystone()` directly on the owner model instance.

```php
$result = $team->createKeystone(
    name: 'Production Payment Worker',
    scopes: ['orders:read', 'orders:write'],
    expiresAt: now()->addMonths(6)->toImmutable(),
    options: [
        'description'  => 'Ingestion pipeline for payment webhooks',
        'rate_limit'   => 120, // 120 req/window (overrides global fallback)
        'ip_allowlist' => ['192.168.1.0/24', '10.0.0.5'], // Exact IPs or CIDR masks
        'ip_blocklist' => ['192.168.1.50'],
        'metadata'     => [
            'environment' => 'production',
            'team_lead'   => 'ops@example.com',
        ],
    ]
);
```

> [!IMPORTANT]
> **Secret Visibility Notice**
> The plain signing `secret` is displayed **exactly once** in the returned array. It is never stored in plain text and cannot be retrieved again. Ensure consumers record it immediately.

### Return Structure

| Key | Type | Description | Example |
|---|---|---|---|
| `client` | `string` | Public client identifier (stored in DB as-is) | `ks_9a8b7c6d5e4f3a2b1c0d...` |
| `secret` | `string` | HMAC-SHA256 signing secret (**show once**) | `4f3a2b1c0d9e8f7a6b5c...` |
| `model` | `Keystone` | The persisted Eloquent model instance | `\Schtzie\Keystone\Models\Keystone` |

### Supported `$options` Parameters

| Option | Type | Default | Description |
|---|---|---|---|
| `description` | `string` | `null` | Human-readable notes for administrative audits. |
| `rate_limit` | `int` | `null` | Per-minute request limit overriding the global `config('keystone.rate_limit')`. |
| `ip_allowlist` | `array<string>` | `[]` | Allowed client IPs or CIDR blocks (e.g. `['10.0.0.0/8']`). |
| `ip_blocklist` | `array<string>` | `[]` | Blocked client IPs or CIDR blocks. |
| `metadata` | `array<string, mixed>` | `[]` | Arbitrary JSON key/value tags for custom application filtering. |

---

## 🔄 3. Key Lifecycle Management

```
┌─────────────────┐       rotateKeystone()       ┌────────────────────────┐
│   Active Key    │ ───────────────────────────► │  Old Key (Grace Period)│
│  (Normal Use)   │                              │     revoked_at = now   │
└─────────────────┘                              │ grace_expires_at = +5m │
         │                                       └────────────────────────┘
         │ revokeKeystone()                                   │
         ▼                                                    ▼
┌─────────────────┐                               ┌────────────────────────┐
│   Revoked Key   │ ◄─────────────────────────────│     Grace Expired      │
│ (Cache Evicted) │                               │  (401 Unauthorized)    │
└─────────────────┘                               └────────────────────────┘
         │
         │ php artisan keystone:prune --days=30
         ▼
┌─────────────────┐
│ Deleted from DB │
└─────────────────┘
```

### Rotating Keys with Zero Downtime
Key rotation creates a fresh key pair and sets a grace period on the old key so active services don't fail during deployment:

```php
// config('keystone.rotation_grace_seconds') => 300 (5 minutes default)
$newKey = $team->rotateKeystone($existingKey);

// Old key:
// - revoked_at = now()
// - grace_expires_at = now() + 300 seconds (still accepted until expiry)
// - Automatically tagged as "Grace Period" in keystone:list

// New key:
echo $newKey['client']; // Provide to client application
echo $newKey['secret']; // New HMAC secret
```

> [!TIP]
> **Grace Periods**: If consumers request an endpoint with the old key during the grace period, authentication still succeeds. Once `grace_expires_at` passes, the old key returns `401 Unauthorized`.

### Revoking Keys
```php
// Revoke a single key by Model instance or ID
$team->revokeKeystone($key);
$team->revokeKeystone($keyId);

// Bulk revoke ALL active keys for this owner
$team->revokeAllKeystones();
```

### Cascading Deletion Behavior
| Action on Owner Model | Keystone Behavior | History Preserved? |
|---|---|---|
| **Soft Delete** (`$owner->delete()`) | All active keys are automatically soft-revoked and Redis cache is cleared. | ✅ Yes |
| **Hard Delete** (`$owner->forceDelete()`) | All keys and owner cache indices are permanently purged from DB and Redis. | ❌ No (Purged) |

---

## 💻 4. Artisan CLI Reference

| Command | Arguments / Flags | Description |
|---|---|---|
| `keystone:generate` | `{owner-type} {owner-id} {name}`<br>`--scopes=read,write`<br>`--rate-limit=120`<br>`--ip-allow=10.0.0.0/8`<br>`--expires-in-days=90`<br>`--tenant={id}` | Generate a new key pair for any owner. |
| `keystone:list` | `--owner-type=Team`<br>`--owner-id=1`<br>`--revoked`<br>`--expired`<br>`--tenant={id}` | Display a tabular summary of keys with statuses (Active, Grace Period, Revoked, Expired). |
| `keystone:revoke` | `{client}`<br>`--force`<br>`--tenant={id}` | Immediately revoke a key by its public client ID. |
| `keystone:rotate-expiring`| `--days=7`<br>`--dry-run`<br>`--tenant={id}` | Automatically rotate keys that expire within the given timeframe. |
| `keystone:prune` | `--days=30`<br>`--access-logs`<br>`--log-days=90`<br>`--tenant={id}` | Permanently delete old revoked keys and access logs from the database. |
| `keystone:status` | `--tenant={id}` | Health report: cache driver, tenancy mode, active/revoked totals, and config summary. |
| `keystone:warm` | `--tenant={id}` | Bulk pre-warm Redis cache with all active keys from the database. |

### Scheduled Maintenance Example
Add maintenance routines to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// Automatically rotate keys expiring within 7 days
Schedule::command('keystone:rotate-expiring --days=7')->daily();

// Prune keys revoked > 30 days ago and access logs > 90 days ago
Schedule::command('keystone:prune --days=30 --access-logs --log-days=90')->daily();
```

---

## 🌐 5. REST API Management Endpoints

Keystone provides optional built-in routes under `routes/keystone.php` (enabled via `config('keystone.routes.enabled')`):

```bash
php artisan vendor:publish --tag=keystone-routes
```

| HTTP Method | Route URI | Route Name | Action |
|---|---|---|---|
| `GET` | `/keystone/keys` | `keystones.index` | List all keys for the authenticated user. |
| `POST` | `/keystone/keys` | `keystones.store` | Create a new key pair (`name`, `scopes`, etc.). |
| `DELETE` | `/keystone/keys/{id}` | `keystones.destroy` | Soft-revoke a key by its ID. |
| `POST` | `/keystone/keys/{id}/rotate` | `keystones.rotate` | Rotate an existing key and return a replacement. |

---

## ⚠️ Anti-Patterns & Best Practices

| ❌ Anti-Pattern | ✅ Idiomatic Keystone Pattern |
|---|---|
| Querying `keystoneables` directly in controllers to check authentication. | Use route middleware `Route::middleware('api.key')` or `Keystone::resolve($request)`. |
| Storing cleartext secrets in application logs or additional DB columns. | Only transmit the client ID; compute the signature with the secret locally using HMAC-SHA256. |
| Manually updating `revoked_at` with raw SQL without evicting Redis. | Use `$owner->revokeKeystone($key)` or `$owner->revokeAllKeystones()` to trigger cache invalidation observers. |
| Passing raw passwords or cleartext tokens in the header. | Send the public ID in `X-Client-Id` and `hash_hmac('sha256', $client, $secret)` in `X-API-Signature`. |
