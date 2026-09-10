# Upgrading Guide

This guide will help you safely upgrade Laravel Keystone between major and minor versions.

---

## Upgrading from v2.2.0 to v2.3.0

Version 2.3.0 introduces a massive suite of enterprise features including Rate Limiting, Replay Protection, Payload Signing, and Access Analytics. While the upgrade is designed to be as seamless as possible, there are a few important steps to take.

### ⚠️ Breaking Changes

**Global Rate Limiting is now enabled by default.**
Out of the box, v2.3.0 enforces a strict global rate limit of **60 requests per minute** on all API keys. 

If your application has existing API consumers that legitimately exceed 60 requests per minute, you will suddenly see `429 Too Many Requests` errors in production after upgrading.

**How to resolve this:**
Before deploying the update, adjust the global rate limit in your `.env` file to a safer ceiling, or disable it entirely by setting it to `0`:

```env
# Disable the global rate limit entirely
KEYSTONE_RATE_LIMIT=0

# OR increase it to a safer ceiling
KEYSTONE_RATE_LIMIT=1000
```

### 1. Update the Config File

Because v2.3.0 introduces several new configuration options, you must update your published `config/keystone.php` file.

You can force-publish the new configuration file (this will overwrite your existing config, so back it up first!):

```bash
php artisan vendor:publish --tag=keystone-config --force
```

Alternatively, manually copy the new configuration blocks (`replay_protection`, `body_signing`, `rate_limit`, `rate_limit_strategy`, and `access_logs`) from the package's base config file into your local `config/keystone.php`.

### 2. Run Database Migrations

Version 2.3.0 introduces new columns to the `keystoneables` table to support metadata, extended descriptions, and zero-downtime rotation grace periods.

Publish and run the new enhancements migration:

```bash
php artisan vendor:publish --tag=keystone-migrations-enhancements
php artisan migrate
```

### 3. Opt-in Features (Optional)

The following security features are disabled by default to ensure backwards compatibility with your existing consumers. Review the documentation to learn how to enable them:

* **Replay Protection**: Enable via `KEYSTONE_REPLAY_PROTECTION=true` in your `.env`. Consumers will be required to send an `X-Timestamp` header.
* **Payload Signing**: Manually attach the new `api.key.payload` middleware to any POST/PUT routes you want to protect. Consumers will be required to send an `X-Body-Hash` header.
* **Access Logging**: Publish the access logs migration (`--tag=keystone-migrations-access-logs`), run `php artisan migrate`, and enable it via `KEYSTONE_ACCESS_LOGS_ENABLED=true`.
* **REST API Routes**: A new REST API is available under `/keystone/keys`. They are gated by the standard Laravel `auth` middleware by default.
