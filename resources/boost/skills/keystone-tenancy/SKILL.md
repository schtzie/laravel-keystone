---
name: keystone-tenancy
description: Configure and manage multi-tenant API clients with Laravel Keystone and stancl/tenancy v4 across single-database and multi-database architectures.
---

# 🏢 Keystone Multi-Tenancy

> Modular AI Skill for **Laravel Boost** — Guide for integrating `schtzie/laravel-keystone` with `stancl/tenancy` (v4) across single-database and multi-database multi-tenant applications.

---

## 🧭 When to Use This Skill

Activate this skill whenever you need to:
- Configure Keystone for multi-tenant applications using `stancl/tenancy` (v4).
- Decide between and configure `single_db` (shared schema) or `multi_db` (database-per-tenant) modes.
- Publish and run tenant-scoped database migrations.
- Ensure Redis cache keys are properly isolated between tenants to prevent credential collision.
- Prevent cross-tenant state leakage in persistent PHP environments (e.g. **Laravel Octane**, FrankenPHP, Swoole, RoadRunner, or `queue:work`).
- Execute Keystone Artisan commands scoped to specific tenants using the `--tenant=` flag.

---

## 🏛️ 1. Tenancy Modes Comparison

Configure your tenancy strategy in `config/keystone.php` or `.env`:

```env
KEYSTONE_TENANCY_MODE=single_db # Options: none | single_db | multi_db
```

| Feature | `none` (Default) | `single_db` (Shared Database) | `multi_db` (Database per Tenant) |
|---|---|---|---|
| **Architecture** | Single-tenant standard app | All tenants share one database schema | Each tenant has an isolated database |
| **Data Separation** | None | `tenant_id` column + automatic `TenantScope` | Separate physical DB connections |
| **Migration Tag** | `keystone-migrations` | `keystone-migrations-single-db` | `keystone-migrations` (run per tenant) |
| **Redis Cache Key** | `keystone:key:{client}` | `keystone:{tenant_id}:key:{client}` | `keystone:{tenant_id}:key:{client}` |
| **Complexity** | Minimal | Low (Foreign key indexing) | Medium (Connection switching) |

---

## 📦 2. Migrations by Architecture

### Option A: Single-Database Tenancy (`single_db`)
In `single_db` mode, the `keystoneables` table includes a `tenant_id` foreign key column and appropriate composite indices. Keystone's `TenantScope` global scope automatically filters all Eloquent queries.

```bash
# 1. Publish the single-db migration
php artisan vendor:publish --tag=keystone-migrations-single-db

# 2. Run the migration
php artisan migrate
```

### Option B: Multi-Database Tenancy (`multi_db`)
In `multi_db` mode, the standard migration runs on each individual tenant database connection.

```bash
# 1. Publish the standard migration to your tenant migrations directory
php artisan vendor:publish --tag=keystone-migrations

# 2. Run migrations across tenant databases via stancl/tenancy
php artisan tenants:migrate
```

---

## 🔒 3. Redis Cache Isolation

Keystone transparently namespaces cache keys to ensure no two tenants can ever collide, even if client prefixes or IDs overlap:

```text
Without Tenancy:
  keystone:key:ks_9a8b7c6d...
  keystone:owner:App\Models\Team:1

With Tenancy (single_db or multi_db):
  keystone:tenant_alpha:key:ks_9a8b7c6d...
  keystone:tenant_alpha:owner:App\Models\Team:1
```

> [!IMPORTANT]
> **Tenant-Scoped Eviction**: Calling `$owner->revokeAllKeystones()` or `Keystone::invalidate($client)` only evicts cache records under the active tenant's namespace. Central application keys or keys belonging to other tenants remain warm and untouched.

---

## ⚡ 4. Octane & Queue Worker Isolation

In long-running process runtimes (**Laravel Octane**, FrankenPHP, Swoole, RoadRunner, queue workers), Keystone maintains an in-memory resolution cache for the lifecycle of a request.

When a single worker processes back-to-back requests for different tenants, that memory state must be flushed on every context switch.

### Automatic Bootstrapper Integration
Keystone includes `Schtzie\Keystone\Tenancy\KeystoneBootstrapper`, which automatically hooks into `stancl/tenancy`:

```php
// config/keystone.php
'tenancy' => [
    'mode'                       => env('KEYSTONE_TENANCY_MODE', 'single_db'),
    'tenant_id_column'           => 'tenant_id',
    'auto_register_bootstrapper' => true, // Auto-registers with stancl/tenancy
],
```

The bootstrapper flushes Keystone's in-memory key cache whenever a tenant is initialized (`bootstrap()`) and whenever tenancy reverts (`revert()`).

### Manual Context Reset
If you use custom middleware or a proprietary tenancy solution, flush Keystone's in-memory store manually:

```php
use Schtzie\Keystone\Facades\Keystone;

// Call upon switching or terminating tenant context
Keystone::flushResolved();
```

---

## ⌨️ 5. Artisan Commands with `--tenant`

To run Keystone CLI commands against a specific tenant, register the `tenantResolver` callback in `app/Providers/AppServiceProvider.php`:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Schtzie\Keystone\Facades\Keystone;
use Stancl\Tenancy\Facades\Tenancy;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Tell Keystone how to initialize tenant context during CLI execution
        Keystone::initializeTenantUsing(function (string $tenantId): void {
            Tenancy::initialize($tenantId);
        });
    }
}
```

### CLI Command Examples:

```bash
# List all active keys for a specific tenant
php artisan keystone:list --tenant=tenant_acme

# Generate a key for a model inside a specific tenant
php artisan keystone:generate "App\Models\Team" 1 "Acme Production Key" --tenant=tenant_acme

# Rotate keys approaching expiration within a specific tenant
php artisan keystone:rotate-expiring --days=7 --tenant=tenant_acme

# Prune old revoked keys and access logs for a specific tenant
php artisan keystone:prune --days=30 --access-logs --tenant=tenant_acme

# Display Keystone status for a specific tenant database
php artisan keystone:status --tenant=tenant_acme
```

---

## ⚠️ Anti-Patterns & Best Practices

| ❌ Anti-Pattern | ✅ Idiomatic Keystone Pattern |
|---|---|
| Sharing a single Redis cache namespace across all tenants without tenant prefixing. | Set `KEYSTONE_TENANCY_MODE=single_db` or `multi_db` so Keystone automatically namespaces all Redis keys by `{tenant_id}`. |
| Using standard `keystone-migrations` in a single-database tenancy project. | Publish `keystone-migrations-single-db` to ensure `tenant_id` and indexing are added. |
| Forgetting to register `Keystone::initializeTenantUsing()` in `AppServiceProvider`. | Always register the callback so `--tenant=` commands can switch tenant connections safely. |
| Relying on static in-memory state across multiple tenant requests in Octane. | Ensure `auto_register_bootstrapper => true` or invoke `Keystone::flushResolved()` on every switch. |
