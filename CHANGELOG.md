# Changelog

All notable changes to **Laravel Keystone** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.2] — 2026-09-08

### Fixed
- **Tenancy Bootstrapper Registration — `getBootstrappersUsing` Closure**: Replaced the intermediate `getBootstrappers()` / `setBootstrappers()` approach (v2.1.1) with stancl/tenancy v4's public `getBootstrappersUsing` callable property in `KeystoneServiceProvider::registerTenancyBootstrapper()`. The new implementation wraps any previously registered callable, falls back to `config('tenancy.bootstrappers')`, and appends `KeystoneBootstrapper` — making it fully composable with other packages that also register bootstrappers via the same hook.

---

## [2.1.1] — 2026-09-08

### Fixed
- **Tenancy Bootstrapper Registration**: Replaced direct array property access (`$tenancy->bootstrappers`) with `getBootstrappers()` / `setBootstrappers()` in `KeystoneServiceProvider::registerTenancyBootstrapper()`. The direct property access caused an `undefined property` error on `stancl/tenancy` v4 instances where `bootstrappers` is not a public property.

---

## [2.1.0] — 2026-09-07

### Added
- **Multiple Auth Guard Support**: `config('keystone.guard')` now supports passing a single guard string (`'web'`), an array of guard names (`['web', 'api']`), or a comma-separated string (`'web,api'`). Upon authentication, Keystone logs the owner model into all specified guards simultaneously.
- **`EdgeCaseTest` — Multi-Guard Tests**: Added Pest feature tests verifying authentication into single, multiple array, and comma-separated auth guards.

### Changed
- **Config Documentation & Readability**: Beautified `config/keystone.php` with standard Laravel section header blocks and clean option descriptions for database tables, key length/prefixes, resolution headers, auth guards, default scopes, rate limits, Redis caching, multi-tenancy, and maintenance pruning.

---

## [2.0.3] — 2026-09-07

### Fixed
- **`EdgeCaseTest` — Revoke Idempotency Tests (Time-Travel Hardened)**: Replaced the single flaky `revoking the same key twice is idempotent` test with 4 deterministic time-travel-based tests using `$this->travelTo()`. The previous test passed by coincidence on SQLite (1-second datetime precision meant two calls within the same second produced identical timestamps even without the guard). The new tests advance the clock 1–5 seconds between calls, making the bug detectable on any database driver.

  New test cases:
  - `revoke() preserves the original revoked_at timestamp when called a second time (time-travel safe)`
  - `revoke() called five times never updates revoked_at after the first call`
  - `revoke() returns true on both the first and subsequent idempotent calls`
  - `revoke() does not fire the Eloquent updated event when called a second time`

---

## [2.0.2] — 2026-09-07

### Fixed
- **`Keystone::revoke()` Idempotency**: Added an early-return guard so calling `revoke()` on an already-revoked key is a no-op. Previously, the unconditional `update(['revoked_at' => now()])` would silently overwrite the original revocation timestamp and fire an unnecessary Eloquent `updated` event (triggering a spurious Redis cache eviction).

### Added
- **`EdgeCaseTest` — 48 regression & edge-case scenarios**: New `tests/Feature/EdgeCaseTest.php` covering authentication & signature edge cases, IP filtering conflict resolution, rate limiting header correctness, cache layer resilience (corrupted JSON fallback, stale revoked entries), key lifecycle idempotency, key generation entropy, middleware execution ordering, and config/environment overrides.

---

## [2.0.1] — 2026-09-07

### Fixed
- **PHPStan Static Analysis**: Removed redundant `isset($rateLimit)` check in `AuthenticateWithKeystone` middleware response header block to satisfy PHPStan 2.2 strict type checking.

### Added
- **Rate Limiting Test Suite**: Added 5 edge-case scenarios in `RateLimitingTest.php` covering disabled rate limits, per-key precedence over global config, zero/negative limits, key isolation, and string numeric limit values.

### Changed
- **Development Dependencies**: Removed unused development packages (`captainhook/captainhook-phar`, `ramsey/conventional-commits`) and associated configuration entries from `composer.json`.

---

## [2.0.0] — 2026-09-07

### Added

#### Security — Per-Key IP Filtering
- **IP Allowlisting & Blocklisting**: Restrict API key access per-client via `ip_allowlist` and `ip_blocklist` arrays stored on the `Keystone` model:
  - **`ip_allowlist`**: Only accept requests originating from specified IPs or subnet ranges; rejects non-matching IPs with `403 Forbidden` (`IP address not allowed.`).
  - **`ip_blocklist`**: Explicitly deny access to blacklisted IPs; rejects matching IPs with `403 Forbidden` (`IP address blocked.`).
  - **CIDR Subnet Notation Support**: Full support for exact IPs (e.g., `127.0.0.1`) as well as subnet ranges (e.g., `192.168.1.0/24`).
- Integrated directly into the `AuthenticateWithKeystone` middleware validation pipeline.

#### Security & Quality — Rate Limiting & Testing
- **Per-Key & Fallback Rate Limiting**: Enforce custom rate limits per API key, falling back to global limits in `config/keystone.php`.
- **Feature Tests**: Added comprehensive Pest test suites in `IpFilteringTest` and `RateLimitingTest`.

### Changed
- **Namespace Migration**: Rebranded vendor namespace from `Schatzie` to `Schtzie` across all source files, test fixtures, and documentation.
- **Composer Metadata**: Updated package name to `schtzie/laravel-keystone`, author name to `Schtzie`, repository URLs, PSR-4 autoload mappings, and Laravel auto-discovery service provider / facade registrations.

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

- [x] IP allowlist / blocklist per Client
- [x] Per-key rate limiting
- [ ] Webhook signing support (outbound HMAC signing)
- [ ] Key usage analytics endpoint
- [ ] Automatic key expiry notifications
- [ ] Dashboard UI via Filament / Livewire

---

[2.1.2]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.1.2
[2.1.1]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.1.1
[2.1.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.1.0
[2.0.3]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.3
[2.0.2]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.2
[2.0.1]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.1
[2.0.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.0
[1.0.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v1.0.0
[Unreleased]: https://github.com/schtzie/laravel-keystone/compare/v2.1.2...HEAD
