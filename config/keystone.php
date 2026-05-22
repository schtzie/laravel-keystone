<?php

declare(strict_types=1);

return [
    // ── Database ───────────────────────────────────────────────────────────
    'table' => 'api_keys',

    // ── Key Generation ─────────────────────────────────────────────────────
    // Optional prefix prepended to generated api_key values (e.g. "ks_abc123...")
    'prefix' => 'ks_',

    // Byte-length of randomly generated api_key and secret_key values.
    // Final string length will be key_length * 2 (hex-encoded) + prefix length.
    'key_length' => 40,

    // ── Request Resolution ─────────────────────────────────────────────────
    // Header name the client sends the plain api_key in.
    'header' => 'X-API-Key',

    // Fallback query parameter when the header is absent.
    'query_param' => 'api_key',

    // Header name the client sends the HMAC-SHA256 signature in.
    // Signature = hash_hmac('sha256', $api_key, $secret_key)
    'signature_header' => 'X-API-Signature',

    // ── Auth ───────────────────────────────────────────────────────────────
    // Laravel auth guard to log the resolved keystoneable owner into.
    // Set to null to skip auth-guard login (owner is still bound in the IoC).
    'guard' => null,

    // Scopes assigned to newly created keys when no scopes are specified.
    'default_scopes' => [],

    // ── Redis Cache ────────────────────────────────────────────────────────
    'cache' => [
        // Master switch — set to false to always hit the database.
        'enabled' => true,

        // Laravel cache store name. Must be a Redis-backed store.
        'store' => env('KEYSTONE_CACHE_STORE', 'redis'),

        // Cache entry TTL in seconds. null = no expiry.
        'ttl' => (int) env('KEYSTONE_CACHE_TTL', 3600),

        // Redis key namespace prefix.
        'prefix' => 'keystone',

        // Write-through: populate Redis automatically on a DB cache-miss.
        'warm_on_miss' => true,

        // Re-warm the Redis entry in middleware terminate() after each auth.
        'refresh_on_use' => true,
    ],

    // ── Multi-Tenancy (stancl/tenancy v4) ──────────────────────────────────
    'tenancy' => [
        // Operating mode:
        //   'none'      — single-tenant (default)
        //   'single_db' — shared database, tenant_id column + TenantScope
        //   'multi_db'  — per-tenant database (stancl switches the connection)
        'mode' => env('KEYSTONE_TENANCY_MODE', 'none'),

        // Column name used to store the tenant identifier (single_db mode only).
        'tenant_id_column' => 'tenant_id',

        // Automatically append KeystoneBootstrapper to stancl/tenancy v4's
        // bootstrapper stack when the package is detected. Set to false if
        // you want to register it manually via your TenancyServiceProvider.
        'auto_register_bootstrapper' => true,
    ],

    // ── Maintenance ────────────────────────────────────────────────────────
    // keystone:prune will delete revoked keys older than this many days.
    'prune_revoked_after_days' => 30,
];
