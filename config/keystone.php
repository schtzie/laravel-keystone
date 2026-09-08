<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database Table Name
    |--------------------------------------------------------------------------
    |
    | This option specifies the name of the database table used by Keystone
    | to store API client keys, secrets, scopes, and owner morph relations.
    | You can customize this if it conflicts with existing table names.
    |
    */

    'table' => 'keystoneables',

    /*
    |--------------------------------------------------------------------------
    | API Key Prefix
    |--------------------------------------------------------------------------
    |
    | An optional prefix prepended to all generated API client keys (e.g., "ks_").
    | This helps identify keys visually in client applications, headers, and logs.
    |
    */

    'prefix' => 'ks_',

    /*
    |--------------------------------------------------------------------------
    | Key Byte Length
    |--------------------------------------------------------------------------
    |
    | The byte length of randomly generated API client keys and secrets.
    | The final string length will be (key_length * 2) plus the prefix length.
    | For example, a key_length of 40 yields an 80-character hex string.
    |
    */

    'key_length' => 40,

    /*
    |--------------------------------------------------------------------------
    | Client ID Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header name sent by clients containing their public API client ID
    | (e.g. "X-Client-Id").
    |
    */

    'header' => 'X-Client-Id',

    /*
    |--------------------------------------------------------------------------
    | Fallback Query Parameter
    |--------------------------------------------------------------------------
    |
    | The HTTP query parameter name checked when the Client ID header is absent.
    | Useful for simple GET request authentication or webhooks (e.g., "?client=ks_...").
    |
    */

    'query_param' => 'client',

    /*
    |--------------------------------------------------------------------------
    | API Signature Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header name sent by clients containing the HMAC-SHA256 request
    | signature calculated as hash_hmac('sha256', $clientKey, $clientSecret).
    |
    */

    'signature_header' => 'X-API-Signature',

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard(s)
    |--------------------------------------------------------------------------
    |
    | The Laravel auth guard(s) to log the resolved keystoneable owner into upon
    | successful authentication. Accepts a single guard name ('web'), an array
    | of guard names (['web', 'api']), or a comma-separated string ('web,api').
    | Set to null to skip guard login (the owner is still bound to the request).
    |
    */

    'guard' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Scopes
    |--------------------------------------------------------------------------
    |
    | The scopes assigned to newly created API keys when no explicit scopes
    | are provided during key creation.
    |
    */

    'default_scopes' => [],

    /*
    |--------------------------------------------------------------------------
    | Global Rate Limit
    |--------------------------------------------------------------------------
    |
    | Default requests-per-minute limit applied to all API keys. Set to 0 to
    | disable global rate limiting. Individual keys can override this value.
    |
    */

    'rate_limit' => (int) env('KEYSTONE_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Redis Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure Redis caching options to minimize database lookup overhead:
    |
    | - enabled: Master toggle. Set to false to bypass cache and query DB.
    | - store: Cache store name defined in cache config (must be Redis-backed).
    | - ttl: Time-to-live for cached credentials in seconds (null = no expiry).
    | - prefix: Namespace prefix prepended to all Redis cache keys.
    | - warm_on_miss: Automatically cache records retrieved during a DB miss.
    | - refresh_on_use: Re-warm Redis entry in middleware terminate() post-auth.
    |
    */

    'cache' => [
        'enabled' => true,
        'store' => env('KEYSTONE_CACHE_STORE', 'redis'),
        'ttl' => (int) env('KEYSTONE_CACHE_TTL', 3600),
        'prefix' => 'keystone',
        'warm_on_miss' => true,
        'refresh_on_use' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy (stancl/tenancy v4)
    |--------------------------------------------------------------------------
    |
    | Integration settings for stancl/tenancy v4 multi-tenant applications:
    |
    | - mode: Operating mode:
    |     - 'none'      : Single-tenant application (default).
    |     - 'single_db' : Shared database with tenant_id column + TenantScope.
    |     - 'multi_db'  : Per-tenant database (tenancy manages connections).
    | - tenant_id_column: Column name for tenant identifier (single_db mode only).
    | - auto_register_bootstrapper: Automatically attach KeystoneBootstrapper
    |   to tenancy bootstrapper stack when stancl/tenancy package is loaded.
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
    | Configure retention for revoked client keys. Running `php artisan keystone:prune`
    | will permanently delete revoked keys that have been revoked for longer
    | than the specified number of days.
    |
    */

    'prune_revoked_after_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Key Usage Analytics
    |--------------------------------------------------------------------------
    |
    | Configure the built-in analytics endpoint that surfaces per-key usage
    | metrics (last usage timestamp, last IP, lifecycle dates, scopes, etc.)
    | for authenticated callers.
    |
    | - enabled: Master toggle. Set to false to disable the route entirely.
    | - prefix:  URI prefix for the analytics route. The resolved path will be
    |             "/{prefix}/{client}", e.g. "/keystone/analytics/ks_abc123".
    |
    */

    'analytics' => [
        'enabled' => (bool) env('KEYSTONE_ANALYTICS_ENABLED', false),
        'prefix'  => env('KEYSTONE_ANALYTICS_PREFIX', 'keystone/analytics'),
    ],

];
