<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database Table Name
    |--------------------------------------------------------------------------
    |
    | The name of the database table Keystone uses to store API client keys,
    | secrets, scopes, and owner morph relations. Override this value if the
    | default 'keystoneables' name conflicts with an existing table in your
    | application schema.
    |
    */

    'table' => 'keystoneables',

    /*
    |--------------------------------------------------------------------------
    | Keystone Eloquent Model Class
    |--------------------------------------------------------------------------
    |
    | The Eloquent model class Keystone uses internally for all key management,
    | authentication, and caching operations. Extend the default model to add
    | custom relationships, accessors, or validation logic and point to your
    | extended class here — all package internals will use it automatically.
    |
    */

    'model' => Schtzie\Keystone\Models\Keystone::class,

    /*
    |--------------------------------------------------------------------------
    | API Key Prefix
    |--------------------------------------------------------------------------
    |
    | A short string prepended to every generated API client key (e.g. "ks_").
    | The prefix makes keys visually identifiable in logs, headers, and client
    | dashboards, and helps prevent accidental use of a key from the wrong
    | environment (e.g. "live_" vs "test_").
    |
    */

    'prefix' => 'ks_',

    /*
    |--------------------------------------------------------------------------
    | Key Byte Length
    |--------------------------------------------------------------------------
    |
    | The number of random bytes used to generate each API client key and secret.
    | The final hex string length is key_length × 2 plus the prefix length, so
    | a key_length of 40 produces an 80-character hex string (e.g. "ks_" + 80 chars).
    | Increase this value for higher entropy in security-sensitive environments.
    |
    */

    'key_length' => 40,

    /*
    |--------------------------------------------------------------------------
    | Maximum Keys Per Owner (optional)
    |--------------------------------------------------------------------------
    |
    | The maximum number of active (non-revoked) API keys one owner may hold at
    | any time. Set to null to allow unlimited keys. When the limit is reached,
    | createKeystone() throws a RuntimeException — the caller should revoke an
    | existing key before creating a new one.
    |
    */

    'max_keys_per_owner' => null,

    /*
    |--------------------------------------------------------------------------
    | Rotation Grace Period
    |--------------------------------------------------------------------------
    |
    | When rotating a key via rotateKeystone(), the predecessor key normally
    | becomes invalid the moment the new key is issued. Set this to a positive
    | integer (seconds) to keep the old key valid for that long after rotation,
    | giving consumers a zero-downtime window to update their credentials.
    |
    | Example: 300 = old key stays valid for 5 minutes after rotation.
    |          0   = old key is invalidated immediately (default).
    |
    */

    'rotation_grace_seconds' => 0,

    /*
    |--------------------------------------------------------------------------
    | Client ID Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header name that API consumers send containing their public client
    | identifier (e.g. "ks_abc…"). Can be overridden to match your API's existing
    | header convention (e.g. 'Authorization' with a custom Bearer format).
    |
    */

    'header' => 'X-Client-Id',

    /*
    |--------------------------------------------------------------------------
    | Fallback Client ID Query Parameter
    |--------------------------------------------------------------------------
    |
    | The query-string parameter name checked when the client ID header is absent.
    | Useful for authenticating simple GET requests, webhooks, or tools that
    | cannot send custom headers (e.g. "?client=ks_abc…").
    |
    */

    'query_param' => 'client',

    /*
    |--------------------------------------------------------------------------
    | API Signature Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header name for the HMAC-SHA256 request signature, computed as
    | hash_hmac('sha256', $clientKey, $clientSecret). The signature proves that
    | the caller knows the secret without transmitting it in plaintext.
    |
    */

    'signature_header' => 'X-API-Signature',

    /*
    |--------------------------------------------------------------------------
    | Fallback Signature Query Parameter
    |--------------------------------------------------------------------------
    |
    | The query-string parameter name checked when the signature header is absent.
    | Useful for URL-based authentication flows where custom headers are impractical.
    |
    */

    'signature_query_param' => 'signature',

    /*
    |--------------------------------------------------------------------------
    | Replay-Attack Protection (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, every authenticated request must include an X-Timestamp header
    | containing the current Unix timestamp in seconds. Requests where the absolute
    | difference between the server time and the provided timestamp exceeds
    | `window_seconds` are rejected as potential captured-request replays.
    |
    | IMPORTANT: Enabling this is a BREAKING CHANGE for existing API consumers —
    | they must update their client code to include the timestamp header.
    |
    | - enabled         : false (opt-in — disabled by default)
    | - timestamp_header: The header name clients must send (default: X-Timestamp)
    | - window_seconds  : Max allowable clock skew / replay window (default: 30s)
    |
    */

    'replay_protection' => [
        'enabled' => (bool) env('KEYSTONE_REPLAY_PROTECTION', false),
        'timestamp_header' => 'X-Timestamp',
        'window_seconds' => (int) env('KEYSTONE_REPLAY_WINDOW', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Body Signing (opt-in)
    |--------------------------------------------------------------------------
    |
    | When the `api.key.payload` middleware is applied to a route, it verifies
    | an HMAC-SHA256 hash of the raw request body sent in the `X-Body-Hash` header.
    | This ensures the request body has not been tampered with in transit.
    |
    | - header           : Header name for the body hash (default: X-Body-Hash)
    | - require_on_empty : Whether to enforce the hash on requests with no body
    |
    */

    'body_signing' => [
        'header' => 'X-Body-Hash',
        'require_on_empty' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard(s)
    |--------------------------------------------------------------------------
    |
    | The Laravel auth guard(s) to log the resolved owner into upon successful
    | authentication, enabling Auth::user() and $request->user() in controllers.
    | Accepts a single guard name ('web'), an array (['web', 'api']), a
    | comma-separated string ('web,api'), or null to skip guard login entirely
    | (the owner is still bound to the request and IoC container).
    |
    */

    'guard' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Scopes
    |--------------------------------------------------------------------------
    |
    | The scopes automatically assigned to a new API key when createKeystone()
    | is called without an explicit scopes argument. Leave as an empty array for
    | unlimited (unscoped) keys by default.
    |
    */

    'default_scopes' => [],

    /*
    |--------------------------------------------------------------------------
    | Global Rate Limit (per minute)
    |--------------------------------------------------------------------------
    |
    | The default maximum number of authenticated requests per minute allowed
    | for all API keys. Individual keys may override this value via their own
    | `rate_limit` column. Set to 0 to disable global rate limiting entirely.
    |
    */

    'rate_limit' => (int) env('KEYSTONE_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Window (seconds)
    |--------------------------------------------------------------------------
    |
    | The duration of the rate-limit window in seconds (default: 60 = 1 minute).
    | This applies to both the global limit and per-key overrides.
    |
    */

    'rate_limit_window_seconds' => (int) env('KEYSTONE_RATE_LIMIT_WINDOW', 60),

    /*
    |--------------------------------------------------------------------------
    | Per-Day Rate Limit (optional)
    |--------------------------------------------------------------------------
    |
    | An additional daily cap applied on top of the per-minute limit. Set to 0
    | or null to disable. Useful for preventing sustained high-volume abuse that
    | stays just under the per-minute ceiling throughout the day.
    |
    */

    'rate_limit_daily' => (int) env('KEYSTONE_RATE_LIMIT_DAILY', 0),

    /*
    |--------------------------------------------------------------------------
    | Scope-Based Rate Limits
    |--------------------------------------------------------------------------
    |
    | Assign different per-minute rate limits to specific scopes. When a key
    | holds multiple scopes with different limits, the most restrictive (lowest)
    | limit is applied. Scope limits narrow — never widen — the effective limit.
    |
    | Example:
    |   'scopes_rate_limits' => ['read' => 1000, 'write' => 100],
    |
    */

    'scopes_rate_limits' => [],

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Strategy
    |--------------------------------------------------------------------------
    |
    | The algorithm used to enforce rate limits. Three strategies ship with Keystone:
    |
    |   'fixed_window'   — Standard Laravel RateLimiter. Simplest and lowest overhead.
    |                      Resets the entire bucket at the end of each fixed interval.
    |                      Compatible with all Laravel cache drivers.
    |
    |   'sliding_window' — Redis sorted set-based rolling window. Prevents boundary
    |                      bursts that fixed windows allow at interval edges.
    |                      Requires a Redis cache store; falls back to fixed_window otherwise.
    |
    |   'token_bucket'   — Lua-atomic Redis token bucket. Allows short bursts up to
    |                      the bucket capacity while smoothing average throughput.
    |                      Requires a Redis cache store; falls back to fixed_window otherwise.
    |
    */

    'rate_limit_strategy' => env('KEYSTONE_RATE_LIMIT_STRATEGY', 'fixed_window'),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure the cache layer that sits between the middleware and the database.
    | The cache dramatically reduces DB load by storing resolved key records in
    | Redis (or any other Laravel cache driver).
    |
    | - enabled        : Master toggle. false = always query the database directly.
    | - store          : The cache store defined in config/cache.php to use.
    | - ttl            : Time-to-live in seconds for each cached key (null = forever).
    | - prefix         : Namespace prefix applied to all Keystone cache keys to avoid
    |                    collisions with other application cache entries.
    | - warm_on_miss   : Automatically cache a key retrieved from the DB on a cache miss.
    | - refresh_on_use : Re-warm the cache entry in middleware terminate() after each
    |                    authenticated request, effectively implementing a sliding TTL.
    |
    */

    'cache' => [
        'enabled' => (bool) env('KEYSTONE_CACHE_ENABLED', true),
        'store' => env('KEYSTONE_CACHE_STORE', 'redis'),
        'ttl' => (int) env('KEYSTONE_CACHE_TTL', 3600),
        'prefix' => 'keystone',
        'warm_on_miss' => true,
        'refresh_on_use' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Access Log (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, Keystone writes one row to the `keystone_access_logs` table
    | (or the configured log channel) for every authenticated request and every
    | rejected authentication attempt. The log is the data source for all usage
    | analytics available through the KeystoneAnalytics facade.
    |
    | - enabled        : Master toggle. false = no logging (default).
    | - driver         : 'database' | 'log' | 'both'
    | - table          : Database table name for the access log.
    | - channel        : Named log channel (null = application default).
    | - prune_after_days: Access log entries older than this are removed by
    |                     `php artisan keystone:prune --access-logs`.
    |
    */

    'access_log' => [
        'enabled' => (bool) env('KEYSTONE_ACCESS_LOG', false),
        'driver' => env('KEYSTONE_ACCESS_LOG_DRIVER', 'database'),
        'table' => 'keystone_access_logs',
        'channel' => null,
        'prune_after_days' => (int) env('KEYSTONE_ACCESS_LOG_PRUNE_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in REST Routes (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, Keystone registers a set of CRUD routes for managing API
    | keys through HTTP — useful for SPA dashboards, admin panels, or any
    | front-end that needs to list, create, revoke, or rotate keys without
    | writing a custom controller.
    |
    | Routes registered (all named under the "keystones." prefix):
    |   GET    /{prefix}/keys                   — keystones.index   (list owner's keys)
    |   POST   /{prefix}/keys                   — keystones.store   (create a new key)
    |   DELETE /{prefix}/keys/{keystone}        — keystones.destroy (revoke a key)
    |   POST   /{prefix}/keys/{keystone}/rotate — keystones.rotate  (rotate a key)
    |
    | - enabled    : false (opt-in — disabled by default)
    | - prefix     : URL prefix for the route group (default: 'keystone')
    | - middleware  : Guard middleware applied to all management routes
    |
    | Publish the routes file to customise further:
    |   php artisan vendor:publish --tag=keystone-routes
    |
    */

    'routes' => [
        'enabled' => (bool) env('KEYSTONE_ROUTES_ENABLED', false),
        'prefix' => env('KEYSTONE_ROUTES_PREFIX', 'keystone'),
        'middleware' => ['auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy (stancl/tenancy v4)
    |--------------------------------------------------------------------------
    |
    | Integration settings for stancl/tenancy v4 multi-tenant applications.
    |
    | - mode: Operating mode for tenant isolation:
    |     'none'      — Single-tenant application (default). No tenant scoping.
    |     'single_db' — Shared database with a tenant_id column and TenantScope.
    |     'multi_db'  — Per-tenant databases; tenancy manages connection switching.
    |
    | - tenant_id_column: Column name for the tenant identifier (single_db only).
    |
    | - auto_register_bootstrapper: When true, KeystoneBootstrapper is automatically
    |   appended to the tenancy bootstrapper stack so that Keystone's in-memory
    |   state is flushed on every tenant switch — critical for Octane and queues.
    |
    */

    'tenancy' => [
        'mode' => env('KEYSTONE_TENANCY_MODE', 'none'),
        'tenant_id_column' => 'tenant_id',
        'auto_register_bootstrapper' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning & Maintenance
    |--------------------------------------------------------------------------
    |
    | Configure the data-retention window for revoked API keys. Running
    | `php artisan keystone:prune` permanently deletes revoked keys older than
    | the specified number of days and evicts their cache entries.
    |
    | Add `--access-logs` to also prune old access log entries:
    |   php artisan keystone:prune --access-logs
    |
    */

    'prune_revoked_after_days' => 30,

];
