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
    | Keystone Eloquent Model Class
    |--------------------------------------------------------------------------
    |
    | The Eloquent model class used by Keystone for key management, authentication,
    | and caching. You can extend Schtzie\Keystone\Models\Keystone with custom
    | relationships, accessors, or logic and specify your model class here.
    |
    */

    'model' => Schtzie\Keystone\Models\Keystone::class,

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
    | Fallback Signature Query Parameter
    |--------------------------------------------------------------------------
    |
    | The HTTP query parameter name checked when the API Signature header is
    | absent. Useful when you need to authenticate entirely via URL parameters.
    |
    */

    'signature_query_param' => 'signature',

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
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure caching options to minimize database lookup overhead.
    | Keystone supports all Laravel cache drivers out of the box.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    | "redis", "dynamodb", "octane", "null"
    |
    | Note: If using the "redis" driver, you must either install the
    | PhpRedis PHP extension via PECL or install the predis/predis package.
    |
    | - enabled: Master toggle. Set to false to bypass cache and query DB.
    | - store: Cache store name defined in cache config.
    | - ttl: Time-to-live for cached credentials in seconds (null = no expiry).
    | - prefix: Namespace prefix prepended to all cache keys.
    | - warm_on_miss: Automatically cache records retrieved during a DB miss.
    | - refresh_on_use: Re-warm cache entry in middleware terminate() post-auth.
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

];
