@verbatim
# 🛡️ Laravel Keystone Core Guidelines

> Always-on AI Guidelines for **Laravel Boost** (`schtzie/laravel-keystone`) — Follow these architectural conventions, security rules, and best practices when implementing or modifying Keystone code.

---

## 🏛️ Core Architectural Principles

1. **Polymorphic Key Ownership**:
   - Models (`User`, `Team`, `Application`, etc.) become API key owners by adding the `Schtzie\Keystone\Traits\HasKeystones` trait.
   - Keys live in the `keystoneables` table linked via `keystoneable_type` and `keystoneable_id`.
   - Never query `keystoneables` directly or create custom key lookup queries; use `api.key` middleware or `Keystone::resolve($request)`.

2. **Zero-Database Caching & Performance**:
   - Key lookups are cached in Redis (`keystone:key:{client}` or `keystone:{tenant}:key:{client}`) for ultra-low latency.
   - Cache invalidation is automatic via Eloquent observers on creation, revocation, rotation, and model deletion.
   - All rate limit decrementing and cache re-warming occurs in `terminate()` after the response has sent to the client, adding **zero latency** to API calls.

3. **HMAC-SHA256 Cryptographic Verification**:
   - Requests require both a public identifier (`X-Client-Id`) and an HMAC-SHA256 signature (`X-API-Signature`).
   - Signature formula: `hash_hmac('sha256', $client, $secret)`.
   - POSSESSING ONLY THE CLIENT ID NEVER AUTHENTICATES A REQUEST. Possessing the secret alone is not sent over HTTP.
   - Signing secrets are displayed **once** upon creation/rotation and must never be stored in plain text.

4. **Middleware Pipeline**:
   - `api.key`: Resolves client identity, checks replay timestamp, validates HMAC signature, validates required scopes, enforces IP allow/blocklist, and checks rate limits.
   - `api.key.payload`: Validates request body integrity against `X-Body-Hash`. Must always follow `api.key` in the pipeline.
   - Scopes: `api.key:scope1,scope2` requires the key to hold **all** listed scopes.

5. **Testing Ergonomics**:
   - Use the `Schtzie\Keystone\Testing\InteractsWithKeystone` trait in Pest and PHPUnit tests.
   - Use `$this->actingWithKeystone($owner)` or `$this->actingWithKeystoneScopes($owner, ['read'])` instead of manual header calculation.
   - Use `Keystone::fake()` to stub authentication without touching database or cache records.

---

## ⚡ Quick Reference

### 1. Enable Key Management on a Model
```php
use Illuminate\Database\Eloquent\Model;
use Schtzie\Keystone\Traits\HasKeystones;

class Team extends Model
{
    use HasKeystones;
}
```

### 2. Generate a Key Pair
```php
$result = $team->createKeystone(
    name: 'Production Worker',
    scopes: ['orders:read', 'orders:write'],
    expiresAt: now()->addMonths(6)->toImmutable(),
    options: [
        'rate_limit'   => 120,
        'ip_allowlist' => ['192.168.1.0/24'],
        'description'  => 'Payment webhook worker',
    ]
);

$clientId = $result['client']; // Provide to client
$secret   = $result['secret']; // Show ONCE to consumer
$model    = $result['model'];  // Keystone Eloquent record
```

### 3. Protect Routes
```php
use Illuminate\Support\Facades\Route;

// Standard key authentication
Route::middleware('api.key')->group(function () {
    Route::get('/api/me', [UserController::class, 'me']);
});

// Scope restriction
Route::middleware('api.key:admin,reports')->group(function () {
    Route::get('/api/reports', [ReportController::class, 'index']);
});

// Request body integrity
Route::middleware(['api.key', 'api.key.payload'])->group(function () {
    Route::post('/api/webhooks', [WebhookController::class, 'handle']);
});
```

### 4. Access Authenticated Owner in Controller
```php
public function index(Request $request): JsonResponse
{
    /** @var \App\Models\Team $team */
    $team = $request->attributes->get('keystoneable');

    return response()->json(['team' => $team->name]);
}
```

---

## ⚠️ Anti-Patterns & Best Practices

| ❌ Anti-Pattern | ✅ Idiomatic Keystone Pattern |
|---|---|
| Querying the `keystoneables` table manually in controllers. | Use `api.key` route middleware or `Keystone::resolve($request)`. |
| Passing plain API tokens or secrets in headers. | Send client ID in `X-Client-Id` and HMAC signature in `X-API-Signature`. |
| Storing cleartext secrets in logs or config files. | Never log secrets. Verify signatures using HMAC-SHA256. |
| Manually hashing request headers in every test method. | Use `$this->actingWithKeystone($owner)` from `InteractsWithKeystone`. |
| Updating `revoked_at` with raw SQL without cache eviction. | Use `$owner->revokeKeystone($key)` or `$owner->revokeAllKeystones()`. |
@endverbatim
