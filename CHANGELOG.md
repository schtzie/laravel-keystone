# Changelog

All notable changes to **Laravel Keystone** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.3.1] — 2026-09-11

### Fixed
- **Memcached**: Fixed missing driver and service initialization, ensuring full compatibility with the Memcached store.
- **Octane**: Added official testing and support for the Laravel Octane cache driver (`laravel/octane`).
- **DynamoDB**: Configured AWS SDK dependencies and mocked instances to fully test and support DynamoDB caching out of the box.

### Testing
- Updated GitHub Actions CI workflow to properly spool up `amazon/dynamodb-local` and `memcached:alpine` services.
- Expanded Pest coverage so that all advanced caching strategies are verified rather than skipped.

---

## [2.3.0] — 2026-09-10

### Fixed
- **Scope Resolution Fallback**: Fixed a bug where passing an explicit empty array `[]` to `createKeystone` would incorrectly fall back to the `keystone.default_scopes` config. Explicit empty arrays now correctly override defaults, storing zero scopes.

### Security
- **Cross-tenant Object Manipulation**: Hardened `HasKeystones` against cross-tenant vulnerabilities. Added strict ownership validation (`$model->keystoneable_id !== $this->getKey()`) to `revokeKeystone()` and `rotateKeystone()` to prevent users from revoking keys that belong to other tenants.

### Added

#### Multi-DB Tenancy CLI Support
- **Multi-DB Tenancy CLI Support**: Added a `--tenant=` option to all 7 Keystone Artisan commands (`keystone:generate`, `keystone:list`, `keystone:prune`, `keystone:revoke`, `keystone:rotate-expiring`, `keystone:status`, `keystone:warm`).
- **Tenant Context Switching**: Added `Keystone::initializeTenantUsing(Closure)` static registry to allow host applications to define how connection swapping occurs when using the `--tenant` option.

#### Negative Testing Helpers
- **Negative Testing Helpers**: Added `KeystoneFake::assertAuthenticatedTimes` and `KeystoneFake::assertNotAuthenticated` failure exception validations for more robust testing.

#### Security — Replay-Attack Protection
- **Timestamp-based replay detection**: Optional `X-Timestamp` header validation in `KeystoneService`. When `keystone.replay_protection.enabled = true`, every request must include a Unix timestamp; requests where `|now − timestamp|` exceeds the configurable `window_seconds` (default: 30 s) are rejected before any cache or database lookup occurs.
- Configurable timestamp header name (`keystone.replay_protection.timestamp_header`) and window size (`keystone.replay_protection.window_seconds`).

#### Security — HMAC Payload Signing (Request Body Integrity)
- **`VerifyKeystonePayload` middleware** (`api.key.payload` alias): Computes `hash_hmac('sha256', body, secret)` server-side and compares it against a configurable request header (`keystone.payload_signing.header`, default `X-Body-Hash`).
- `require_on_empty` option (default: `true`) — when `false`, requests with an empty body skip the body-hash check (useful for GET/HEAD routes).
- Must be stacked **after** `api.key` (which resolves the secret). Returns `422 Unprocessable Content` on signature mismatch.

#### Rate Limiting — Strategy System
- **`RateLimitStrategy` contract** — uniform interface (`attempt`, `remaining`, `retryAfter`) implemented by all three built-in strategies.
- **`FixedWindowStrategy`**: Delegates to Laravel's built-in `RateLimiter`; zero additional infrastructure.
- **`SlidingWindowStrategy`**: Redis sorted-set (ZSET) based sliding window; eliminates boundary-burst spikes. Gracefully falls back to `FixedWindowStrategy` on non-Redis stores.
- **`TokenBucketStrategy`**: Atomic Lua-scripted token bucket on Redis; allows controlled bursting while guaranteeing average rate. Gracefully falls back to `FixedWindowStrategy` on non-Redis stores.
- Strategy selected via `keystone.rate_limit_strategy` config key (`fixed_window` | `sliding_window` | `token_bucket`).
- `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After` response headers on all strategies.

#### Access Logging
- **`KeystoneAccessLog` Eloquent model** + migration (`keystone_access_logs`): Append-only log of every authenticated request and every rejection; `updated_at` disabled by design.
- **`KeystoneAccessLogger`** event listener: Auto-registered when `keystone.access_log.enabled = true`. Supports three output drivers:
  - `database` — inserts a row via Eloquent.
  - `log` — writes structured JSON to a named log channel.
  - `both` — writes to both simultaneously.
- Events logged: `authenticated`, `rejected_invalid`, `rejected_replay`, `rejected_ip`, `rejected_scope`, `rate_limited`.
- Failure is silently swallowed — a broken log write never interrupts the request lifecycle.
- Configurable table name (`keystone.access_log.table`) and log channel (`keystone.access_log.channel`).

#### Analytics
- **`KeystoneAnalytics` service** + **`KeystoneAnalytics` facade**:
  - `dailyUsage(Keystone $key, int $days = 7)` — day-by-day breakdown grouped by event type.
  - `topKeys(int $limit = 10)` — ranked list of most-used keys (authenticated events only).
  - `ownerUsage(Model $owner, int $days = 30)` — daily totals across all keys for a given owner.
  - `rejectionSummary(Keystone $key, int $days = 7)` — rejection counts grouped by reason.
  - `totalRequests(int $days = 30)` — high-level authenticated request count metric.

#### Events
Seven new events dispatched at key lifecycle moments:
- `KeystoneAuthenticated` — successful authentication
- `KeystoneAuthFailed` — any rejection (carries `$reason` string)
- `KeystoneRateLimitExceeded` — rate limit hit
- `KeystoneCreated` — key pair created
- `KeystoneRevoked` — key soft-revoked
- `KeystoneRotated` — key rotated (carries both old and new `Keystone` instances)
- `KeystoneExpired` — key expired on access attempt

#### REST API Routes
- **`keystones.index`** (`GET /keystones`) — list active keys for the authenticated user.
- **`keystones.store`** (`POST /keystones`) — create a new key pair; returns `{ client, secret, id, name, scopes, expires_at, created_at }`.
- **`keystones.destroy`** (`DELETE /keystones/{id}`) — revoke a specific key.
- **`keystones.rotate`** (`POST /keystones/{id}/rotate`) — rotate a key, returning fresh credentials.
- Routes registered under `keystone.routes.prefix` (default: `keystones`) and protected by `keystone.routes.middleware` (default: `['auth']`).
- Route names follow plural convention: `keystones.index`, `keystones.store`, `keystones.destroy`, `keystones.rotate`.

#### Artisan Commands (6 new, 1 updated)

| Command | Description |
|---|---|
| `keystone:generate` | Generate a new API key pair for any Eloquent model owner |
| `keystone:revoke` | Immediately revoke a key by client ID (with confirmation prompt) |
| `keystone:list` | List keys with status, owner, scopes, expiry; filterable by owner / status |
| `keystone:status` | Display key counts, cache config, and access-log stats at a glance |
| `keystone:rotate-expiring` | Auto-rotate keys expiring within N days (default: 7); supports `--dry-run` |
| `keystone:prune` *(updated)* | Added `--access-logs` flag to prune old `keystone_access_logs` rows |

#### Testing Helpers
- **`KeystoneFake`** — drop-in test double implementing `KeystoneServiceContract`:
  - `Keystone::fake(?Keystone $key)` — swap the real service for a fake (can force `null` to simulate all auth failures).
  - `assertAuthenticatedTimes(int $times)` — assert number of successful auth calls.
  - `assertNotAuthenticated()` — assert no requests were authenticated.
- **`actingWithKeystone(Keystone $key)`** — `TestCase` macro; sets correct `X-Client-Id` and `X-API-Signature` headers automatically.
- **`actingWithKeystoneScopes(Keystone $key, array $scopes)`** — same as above but temporarily overrides scopes.
- **`KeystoneFactory`** — fluent test factory with `expired()`, `revoked()`, `withScopes()`, `withMetadata()` helpers.

#### Test Suite Expansion
- 218 tests across 17 feature test classes (7 skipped — Redis-specific strategies; graceful array-driver fallback).
- New test classes: `EventsTest`, `GracePeriodTest`, `PayloadSigningTest`, `PerformanceRefactorTest`, `RestRoutesTest`, `ReplayProtectionTest`, `TestingHelpersTest`.

### Changed
- **`HasKeystones::createKeystone`**: Extended with an `options` array parameter accepting `description`, `metadata`, `ip_allowlist`, `ip_blocklist`, `rate_limit` — all applied to the model on creation.
- **`config/keystone.php`**: Extended with `replay_protection.*`, `payload_signing.*`, `rate_limit_strategy`, `access_log.*`, `routes.*`, and `rotation_grace_seconds` sections.

### Fixed
- **PHPStan level max — zero errors**: Resolved all 57 original static analysis errors across the codebase. Config `mixed` returns guarded with `is_numeric`/`is_string`/`is_scalar`; Redis `->command()` results guarded with `is_array`; all `EloquentCollection` generics correctly typed; `phpstan.neon` `ignoreErrors` patterns used only where PHPStan cannot infer trait-injected method signatures (`HasKeystones`).

---

## [2.2.0] — 2026-09-09


### Added
- **Laravel Octane & FrankenPHP Support**: Transitioned the core `KeystoneService` from a `singleton` to a `scoped` binding. This guarantees the in-memory `$resolved` key map is automatically flushed between requests, eliminating memory leaks and stale authentication state in long-running PHP processes.
- **Automated Keystone Deletion on Owner Deletion**: The `HasKeystones` trait now automatically handles deleting keystones when the owner model is deleted. If the owner model is soft-deleted, its keystones are safely revoked (preserving history) and the cache is evicted. If the owner model is hard-deleted, its keystones are permanently deleted from the database and the cache is evicted.
- **Extensive Edge Case Test Suite**: Added a comprehensive suite of edge case tests covering orphaned keys, corrupted JSON cache fallbacks, invalid tenancy objects, null IP addresses, malformed array payload injections, and ModelNotFound exceptions during unauthorized rotation/revocation attempts.
- **High-Performance Redis Owner Indexing (`sAdd` / `sMembers`)**: Refactored `put()` and `forgetOwner()` in `KeystoneKeyCacheRepository` to utilize Redis sets (`sAdd`, `sMembers`, `del`) for atomic owner key tracking, eliminating get-decode-encode-put race conditions and lock contention under high concurrency.
- **Bulk Cache Eviction (`forgetMany`)**: Added `forgetMany(array $clients)` method to  `KeystoneKeyCacheRepository` and refactored `PruneKeystonesCommand` to evict cached keys in bulk per chunk rather than issuing sequential single-key delete commands.
- **Multi-Tenant Rate Limiting Key Isolation**: Updated rate limiter key generation in `AuthenticateWithKeystone` to incorporate tenant namespace segments (`keystone:rate_limit:{tenant}:{client}`), preventing key collisions across multi-database tenants.
- **Cache Driver Agnosticism**: Expanded documentation and type definitions to officially support all native Laravel cache drivers (array, database, file, memcached, redis, dynamodb, octane, null). Replaced isolated 'Redis' terminology with general 'Cache' terminology throughout the core and test suites.
- **Comprehensive Cache Testing Suite**: Implemented extensive Pest coverage for all cache configuration permutations (`enabled`, `store`, `ttl`, `warm_on_miss`, `refresh_on_use`, and `tenancy.mode`).
- **Redis Client Compatibility Tests**: Added parameterized tests validating successful cache repository resolution and execution using both `phpredis` PECL extension and the `predis/predis` package.
- **`revoked_at` Migration Indexes**: Added database indexes on `revoked_at` in base and `single_db` migration stubs to prevent full table scans during key pruning commands.
- **Strict PHPStan Level Max & Octane Compatibility Config**: Enhanced `phpstan.neon` with `level: max`, `checkOctaneCompatibility: true`, `checkModelProperties: true`, `checkMissingVarTagTypehint: true`, and `checkUninitializedProperties: true`.

### Fixed
- **Invalid Auth Guard Exception**: Fixed a fatal `InvalidArgumentException` bug in the `AuthenticateWithKeystone` middleware that crashed the application when an invalid or non-existent guard name was passed via the `keystone.guard` config array.

### Removed
- **`last_used_at` & `last_used_ip` DB Tracking**: Completely removed `last_used_at` and `last_used_ip` columns, model properties, docblocks, `$casts`, and `markUsed()` method to eliminate database `UPDATE` query overhead on every authenticated request.

---

## [2.1.3] — 2026-09-08

### Fixed
- **Cache Model Hydration (`newFromBuilder`)**: Replaced `setRawAttributes($attributes)` with `newFromBuilder($attributes)` in `KeystoneKeyCacheRepository::get()`. Models retrieved from Redis now correctly have `$exists = true` and synced `$original` attributes, preventing `markUsed()` in `AuthenticateWithKeystone::terminate()` from considering all attributes dirty and issuing unnecessary DB update queries.
- **`CacheTest` Subsecond Query Test Isolation**: Added `$this->freezeTime()` to `it('serves the key from Redis on subsequent requests without hitting the DB')` to prevent microsecond clock shifts on PHP 8.5 / Laravel 13 from triggering dirty attribute updates in `markUsed()`.

---

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
- [x] Replay-attack protection
- [x] HMAC payload / body-integrity signing
- [x] Advanced rate-limit strategies (sliding window, token bucket)
- [x] Access logging & analytics
- [x] Artisan key management commands
- [x] REST API key management routes
- [x] Testing helpers (fake, factory, macros)
- [ ] Webhook signing support (outbound HMAC signing)
- [ ] Automatic key expiry notifications
- [ ] Dashboard UI via Filament / Livewire

---

[2.3.1]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.3.1
[2.3.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.3.0
[2.2.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.2.0
[2.1.3]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.1.3
[2.1.2]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.1.2
[2.1.1]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.1.1
[2.1.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.1.0
[2.0.3]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.3
[2.0.2]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.2
[2.0.1]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.1
[2.0.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v2.0.0
[1.0.0]: https://github.com/schtzie/laravel-keystone/releases/tag/v1.0.0
[Unreleased]: https://github.com/schtzie/laravel-keystone/compare/v2.3.1...HEAD
