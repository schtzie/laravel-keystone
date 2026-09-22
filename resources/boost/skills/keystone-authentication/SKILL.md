---
name: keystone-authentication
description: Authenticate API requests using Keystone middleware (api.key, api.key.payload), HMAC-SHA256 signature verification, replay protection, payload body signing, rate limiting strategies, and accessing the authenticated model.
---

# 🛡️ Keystone Authentication & Middleware

> Modular AI Skill for **Laravel Boost** — Guide for configuring Keystone middleware, HMAC-SHA256 signature calculation, replay-attack mitigation, body integrity verification, and rate limiting in `schtzie/laravel-keystone`.

---

## 🧭 When to Use This Skill

Activate this skill whenever you need to:
- Protect API routes or route groups using Keystone middleware (`api.key` and `api.key.payload`).
- Enforce strict route access scopes (e.g. `api.key:orders:read,orders:write`).
- Construct, verify, or debug HMAC-SHA256 signatures (`X-API-Signature`).
- Configure replay-attack protection via timestamp headers (`X-Timestamp`).
- Protect against request body tampering using payload signing (`X-Body-Hash`).
- Extract the authenticated owner model in controllers, policies, or form requests.
- Configure or switch rate-limiting strategies (`fixed_window`, `sliding_window`, `token_bucket`).
- Diagnose authentication rejections (HTTP 401, 403, 422, 429).

---

## 🔬 1. Resolution & Verification Pipeline

```text
Incoming HTTP Request
        │
        ▼
[ 1. Header Extraction ] ── (X-Client-Id, X-API-Signature, X-Timestamp, X-Body-Hash)
        │
        ▼
[ 2. Replay Check ] ─────── (Reject 401 if |now - X-Timestamp| > window_seconds)
        │ Pass
        ▼
[ 3. Key Resolution ] ────► In-Memory Cache ──(hit)──┐
        │                                             │
        └───► Redis Cache ──────────────(hit)──┐      │
                │                              │      │
                └───► Database Query ──────────┼──────┘
                        │ Found                ▼
                        └────────────► [ 4. HMAC-SHA256 Verification ]
                                               │ Valid
                                               ▼
                                       [ 5. Scope Validation ]
                                               │ Has all required scopes
                                               ▼
                                       [ 6. IP Allow/Blocklist ]
                                               │ Allowed
                                               ▼
                                       [ 7. Rate Limiter (Redis / Cache) ]
                                               │ Under quota
                                               ▼
                                       [ 8. Payload Integrity (if stacked) ]
                                               │ Valid X-Body-Hash
                                               ▼
                                       [ 9. Dispatch to Controller ]
                                               │
                                               ▼
                                    Response Sent to Client
                                               │
                                               ▼
                                    terminate(): Re-warm cache TTL
```

---

## 🚦 2. Middleware Registration & Scopes

Apply Keystone middleware to individual routes, groups, or whole sub-domains:

```php
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// 1. Basic Key Authentication
Route::middleware('api.key')->group(function () {
    Route::get('/api/me', [OrderController::class, 'me']);
});

// 2. Scope Enforcement (ALL listed scopes must be held by the key)
Route::middleware('api.key:orders:read,orders:write')->group(function () {
    Route::post('/api/orders', [OrderController::class, 'store']);
});

// 3. Payload Integrity Check (Must follow 'api.key' in pipeline)
Route::middleware(['api.key', 'api.key.payload'])->group(function () {
    Route::post('/api/webhooks/inbound', [WebhookController::class, 'handle']);
});
```

> [!NOTE]
> **Scope Checking**: Passing `api.key:read,write` checks that the client's key has **both** the `read` AND `write` scopes. If any scope is missing, Keystone returns `403 Forbidden` with `{"message": "Insufficient scope."}`.

---

## 🔐 3. Client Signature Calculation (HMAC-SHA256)

Every request requires the public `client` identifier and an HMAC-SHA256 `signature` computed using the private `secret`:

$$\text{signature} = \text{hash\_hmac}('sha256', \text{client}, \text{secret})$$

### PHP Client
```php
use Illuminate\Support\Facades\Http;

$client    = 'ks_a1b2c3d4e5f6...';
$secret    = '9f8e7d6c5b4a...';
$signature = hash_hmac('sha256', $client, $secret);

$response = Http::withHeaders([
    'X-Client-Id'     => $client,
    'X-API-Signature' => $signature,
])->get('https://api.example.com/api/me');
```

### JavaScript / Node.js Client
```javascript
import crypto from 'crypto';

const client    = 'ks_a1b2c3d4e5f6...';
const secret    = '9f8e7d6c5b4a...';
const signature = crypto.createHmac('sha256', secret).update(client).digest('hex');

const res = await fetch('https://api.example.com/api/me', {
  headers: {
    'X-Client-Id':     client,
    'X-API-Signature': signature,
  },
});
```

### Python Client
```python
import hashlib
import hmac
import requests

client = "ks_a1b2c3d4e5f6..."
secret = "9f8e7d6c5b4a..."
signature = hmac.new(secret.encode(), client.encode(), hashlib.sha256).hexdigest()

response = requests.get("https://api.example.com/api/me", headers={
    "X-Client-Id": client,
    "X-API-Signature": signature,
})
```

---

## ⏱️ 4. Replay-Attack Protection (`X-Timestamp`)

Replay protection prevents an attacker from intercepting a request and repeating it later.

### Configuration (`config/keystone.php`)
```php
'replay_protection' => [
    'enabled'          => env('KEYSTONE_REPLAY_PROTECTION', true),
    'timestamp_header' => 'X-Timestamp',
    'window_seconds'   => 30, // Tolerates |now - timestamp| <= 30 seconds
],
```

### Client Header Inclusion
```php
Http::withHeaders([
    'X-Client-Id'     => $client,
    'X-API-Signature' => hash_hmac('sha256', $client, $secret),
    'X-Timestamp'     => time(), // Current Unix timestamp in seconds
])->get('https://api.example.com/api/me');
```

> [!WARNING]
> If `X-Timestamp` is missing, not an integer, or older/future beyond `window_seconds`, the request is immediately rejected with `401 Unauthorized` before reaching the cache or database.

---

## 📦 5. Payload Integrity Signing (`X-Body-Hash`)

The `api.key.payload` (`VerifyKeystonePayload`) middleware ensures that the request body has not been tampered with or corrupted in transit.

$$\text{body\_hash} = \text{hash\_hmac}('sha256', \text{raw\_body}, \text{secret})$$

### Configuration (`config/keystone.php`)
```php
'payload_signing' => [
    'header'           => 'X-Body-Hash',
    'require_on_empty' => false, // Set false to bypass GET/HEAD requests with empty bodies
],
```

### Client Request with Body Hash
```php
$payload = json_encode(['action' => 'capture_charge', 'amount_cents' => 5000]);

Http::withHeaders([
    'X-Client-Id'     => $client,
    'X-API-Signature' => hash_hmac('sha256', $client, $secret),
    'X-Body-Hash'     => hash_hmac('sha256', $payload, $secret),
    'Content-Type'    => 'application/json',
])->withBody($payload, 'application/json')->post('https://api.example.com/api/charges');
```

---

## 👤 6. Accessing the Authenticated Model

Once `api.key` passes, the authenticated owner and Keystone metadata are easily accessible:

```php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Schtzie\Keystone\Facades\Keystone;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Path 1: From request attributes (fastest & recommended)
        /** @var \App\Models\Team $owner */
        $owner = $request->attributes->get('keystoneable');

        // Path 2: Via Keystone Facade
        $keystone = Keystone::resolve($request);
        $owner    = $keystone?->keystoneable;

        // Path 3: Direct IoC container resolution
        $team = app(\App\Models\Team::class);

        return response()->json([
            'owner_id'    => $owner->id,
            'key_name'    => $keystone->name,
            'key_scopes'  => $keystone->scopes,
            'rate_limit'  => $keystone->rate_limit,
        ]);
    }
}
```

---

## 📊 7. Rate Limiting Strategies

Keystone handles rate limiting in `terminate()` after the response is sent, adding **zero latency** to client responses.

| Strategy | Config Key | Engine | Best Suited For |
|---|---|---|---|
| **Fixed Window** | `fixed_window` | Laravel Cache / Atomic increments | General use, zero Redis dependency required. |
| **Sliding Window** | `sliding_window` | Redis Sorted Sets | Eliminating boundary burst spikes (e.g. 60 req at :59 and 60 req at :01). |
| **Token Bucket** | `token_bucket` | Redis Lua Script | Supporting high-throughput bursts up to bucket capacity while enforcing steady rate. |

### Rate Limit Headers

| Header | Description | Example |
|---|---|---|
| `X-RateLimit-Limit` | Total request quota allowed per window | `120` |
| `X-RateLimit-Remaining` | Remaining request quota in the current window | `42` |
| `Retry-After` | Seconds until quota resets (**only sent with 429**) | `18` |

---

## 🚨 8. Error Response Matrix

| Status Code | Response Body | Root Cause | Resolution |
|---|---|---|---|
| `401 Unauthorized` | `{"message": "Unauthorized."}` | Missing/invalid `X-Client-Id`, incorrect signature, expired key, revoked key, or expired replay timestamp. | Check client credentials, HMAC algorithm, and clock synchronization. |
| `403 Forbidden` | `{"message": "Insufficient scope."}` | The key does not possess all scopes required by `api.key:scope1,scope2`. | Update key scopes or adjust route middleware. |
| `403 Forbidden` | `{"message": "IP address not allowed."}` | Request originates from an IP address not present in `ip_allowlist`. | Add client IP/CIDR to the key's allowlist. |
| `403 Forbidden` | `{"message": "IP address blocked."}` | Request originates from an IP address matching `ip_blocklist`. | Remove client IP from the key's blocklist. |
| `422 Unprocessable`| `{"message": "Payload hash mismatch."}` | Body content altered or `X-Body-Hash` computed incorrectly. | Recompute HMAC of the exact raw body string. |
| `429 Too Many Requests` | `{"message": "Too Many Requests."}` | Key has exceeded its allocated request limit. | Respect the `Retry-After` header before retrying. |

---

## ⚠️ Anti-Patterns & Best Practices

| ❌ Anti-Pattern | ✅ Idiomatic Keystone Pattern |
|---|---|
| Manually hashing request bodies using `md5` or `sha1`. | Compute `hash_hmac('sha256', $body, $secret)` matching Keystone's standard. |
| Placing `api.key.payload` before `api.key` in middleware definitions. | Always stack `api.key` first so the signing secret is resolved before payload verification. |
| Reading request input via `$request->all()` before verifying payload hash. | Use the raw body stream `$request->getContent()` for signature comparisons to avoid formatting differences. |
| Ignoring `Retry-After` on 429 errors. | Read and delay retries according to the `Retry-After` header value. |
