# Changelog

All notable changes to **Laravel Keystone** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — 2026-05-23

Initial release of Laravel Keystone.

### Added

#### Core — Client Management

- **`HasKeystones` trait** — add to any Eloquent model to give it full Client management:
  - `createKeystone(string $name, array $scopes = [], ?CarbonImmutable $expiresAt = null): array` — generates a cryptographically random `client` + `secret` pair, persists it, and returns both plain values
  - `revokeKeystone(int|string|Keystone $key): bool` — soft-revokes a specific key by setting `revoked_at`
  - `revokeAllKeystones(): int` — revokes every active key owned by the model and evicts all their Redis entries
  - `rotateKeystone(int|string|Keystone $old): array` — atomically revokes the old key and creates a new one in a single DB transaction

- **`Keystone` Eloquent model** — polymorphic `keystoneable` morph-to relation, with:
  - `keystoneable()` — MorphTo relationship to the owning model
  - `scopeActive()`, `scopeNotRevoked()`, `scopeNotExpired()` — composable query scopes
  - `isValid(): bool` — checks revocation status and expiry
  - `revoke(): bool` — soft-revokes the key (fires `updated` event → cache invalidation)
  - `markUsed(Request $request): void` — records `last_used_at` + `last_used_ip` (called from middleware `terminate()`, never adds request latency)
  - `verifySignature(string $signature): bool` — constant-time HMAC-SHA256 comparison via `hash_equals()`

- **Database schema (`keystoneables` table)**:
  - Polymorphic columns: `keystoneable_type`, `keystoneable_id`
  - Authentication: `client` (plain, unique), `secret` (plain)
  - Authorization: `scopes` (JSON array)
  - Lifecycle: `expires_at`, `revoked_at`, `last_used_at`, `last_used_ip`
  - Standard timestamps: `created_at`, `updated_at`

#### Security — HMAC SHA-256 Signature Verification

- Every authenticated request must supply a signature computed as:
  ```
  hash_hmac('sha256', client, secret)
  ```
- The middleware verifies the signature server-side using `hash_equals()` to prevent timing attacks
- Possessing the Client alone is never sufficient to authenticate — the secret is required

#### Middleware — `AuthenticateWithKeystone`

- Registered automatically under the `api.key` alias
- Reads the Client from a configurable **header** (`X-Client-Id`) or **query parameter** (`client`)
- Reads the HMAC signature from a configurable **header** (`X-API-Signature`)
- Enforces **optional scope** parameters: `Route::middleware('api.key:read,write')`
- Binds the resolved owner to the IoC container and `$request->attributes->get('keystoneable')`
- Optionally logs the owner into a configured Laravel auth guard
- Runs `markUsed()` + Redis re-warm in **`terminate()`** (after response is sent — zero latency impact)

#### Caching — Redis-First Architecture

- **`KeystoneKeyCacheRepository`** — all Redis I/O in one place:
  - `get(string $client): ?Keystone` — deserialise cached entry
  - `put(Keystone $client): void` — serialise and write with configurable TTL; maintains per-owner index for bulk invalidation
  - `forget(string $client): void` — evict a single entry
  - `forgetOwner(string $type, int|string $id): void` — bulk-evict all entries belonging to an owner (used by `revokeAllKeystones()`)

- **Resolution pipeline per request** (in-memory → Redis → DB → HMAC):
  1. Check the request-scoped in-memory `$resolved` map
  2. Check Redis (`KeystoneKeyCacheRepository::get`)
  3. Fall back to database; write-through to Redis if `warm_on_miss = true`
  4. Verify HMAC signature

- **Automatic cache invalidation** via Eloquent model event observers registered in `KeystoneServiceProvider`:
  - `Keystone::updated` → `KeystoneKeyCacheRepository::forget()`
  - `Keystone::deleted` → `KeystoneKeyCacheRepository::forget()`

- **Configurable cache behaviour**:
  - `cache.enabled` — master switch; set to `false` to always hit the DB
  - `cache.store` — any Laravel cache store (must be Redis-backed in production)
  - `cache.ttl` — TTL in seconds (`null` = no expiry)
  - `cache.warm_on_miss` — populate Redis automatically on a DB hit
  - `cache.refresh_on_use` — re-warm Redis entry after each successful authentication

#### Multi-Tenancy — stancl/tenancy v4 Integration

Three operating modes controlled by `KEYSTONE_TENANCY_MODE`:

- **`none` (default)** — standard single-tenant; flat Redis keys (`keystone:key:{client}`)

- **`single_db`** — shared database with `tenant_id` isolation:
  - **`TenantScope`** global scope — automatically appends `WHERE tenant_id = ?` to every `Keystone` query based on the active `tenant()` context
  - **`TenantAware` trait** — boots `TenantScope` and auto-stamps `tenant_id` on every `creating` event; no manual column assignment needed
  - Dedicated migration stub with `tenant_id` column + composite index on `(tenant_id, client)`, published via `--tag=keystone-migrations-single-db`
  - Redis keys namespaced as `keystone:{tenant_id}:key:{client}`

- **`multi_db`** — per-tenant database:
  - stancl/tenancy switches the Eloquent connection before routes run; Keystone queries use the active connection transparently — no `tenant_id` column needed
  - Base migration stub (no `tenant_id`) published via `--tag=keystone-migrations`
  - Redis keys namespaced as `keystone:{tenant_id}:key:{client}` (stancl's `RedisTenancyBootstrapper` sets the connection prefix; Keystone adds a sub-namespace on top)

- **`KeystoneBootstrapper`** — implements `Stancl\Tenancy\Contracts\TenancyBootstrapper`:
  - Calls `KeystoneService::flushResolved()` on both `bootstrap()` and `revert()`
  - Prevents in-memory key state from leaking between tenants in long-lived PHP processes (Octane, queue workers)
  - Auto-registered when `stancl/tenancy` is installed and `tenancy.auto_register_bootstrapper = true`

#### Service Layer

- **`KeystoneService`** (singleton):
  - `resolve(Request $request): ?Keystone` — full resolution + HMAC verification pipeline
  - `findByKeystone(string $rawKey): ?Keystone` — cache-aware lookup (no HMAC check)
  - `generate(Model $owner, string $name, array $options): array` — convenience wrapper
  - `invalidate(string $client): void` — evicts from in-memory map + Redis
  - `flushResolved(): void` — clears the in-memory map (called by `KeystoneBootstrapper` on tenant switch)

- **`Keystone` facade** — static proxy to `KeystoneService` with full IDE `@method` docblock

#### Artisan Commands

- **`keystone:prune`** — deletes revoked `Keystone` records older than `prune_revoked_after_days` (default: 30) and evicts their Redis entries before deletion
  - `--days=N` option to override the retention period
  - Processes records in chunks of 200 to avoid memory pressure

#### Configuration

- Full `config/keystone.php` with documented options:
  - `table`, `prefix`, `key_length`
  - `header`, `query_param`, `signature_header`
  - `guard`, `default_scopes`
  - `cache.*` (enabled, store, ttl, prefix, warm_on_miss, refresh_on_use)
  - `tenancy.*` (mode, tenant_id_column, auto_register_bootstrapper)
  - `prune_revoked_after_days`

#### Publishing

| Tag | Contents |
|---|---|
| `keystone-config` | `config/keystone.php` |
| `keystone-migrations` | Base migration (none / multi_db modes) |
| `keystone-migrations-single-db` | Migration with `tenant_id` column + composite index |

#### Tests

- Full PestPHP test suite covering:
  - **`KeystoneTest`** — key generation, plain value storage, HMAC verification, middleware happy/rejection paths, scope enforcement, owner binding, and key lifecycle (revoke / rotate)
  - **`CacheTest`** — write-through on cache miss, Redis hit bypasses DB, automatic eviction on revoke / revokeAll / rotate, cache-disabled mode, Redis key naming format
  - **`SingleDbTenancyTest`** — `tenant_id` auto-stamping, cross-tenant isolation via `TenantScope`, tenant-namespaced Redis keys, middleware rejecting cross-tenant keys, bulk revocation scoped to current tenant
  - **`MultiDbTenancyTest`** — `KeystoneBootstrapper` flush on `bootstrap()` / `revert()`, tenant-namespaced Redis keys, cross-tenant cache miss, middleware auth under `multi_db` mode
- **`FakeTenant`** test double — simulates `stancl/tenancy`'s global `tenant()` helper without requiring the real package in tests
- **`tests/bootstrap.php`** — defines the `tenant()` global function stub loaded by PHPUnit before any tests run

---

## [Unreleased]

Features under consideration for future releases:

- [ ] IP allowlist / blocklist per Client
- [ ] Per-key rate limiting
- [ ] Webhook signing support (outbound HMAC signing)
- [ ] Key usage analytics endpoint
- [ ] Automatic key expiry notifications
- [ ] Dashboard UI via Filament / Livewire

---

[1.0.0]: https://github.com/schatzie/laravel-keystone/releases/tag/v1.0.0
[Unreleased]: https://github.com/schatzie/laravel-keystone/compare/v1.0.0...HEAD
