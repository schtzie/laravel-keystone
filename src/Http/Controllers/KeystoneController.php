<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Schtzie\Keystone\Models\Keystone;

/**
 * RESTful controller for managing API keys through the built-in Keystone routes.
 *
 * All endpoints are scoped to the currently authenticated user — the owner is
 * resolved from `$request->user()` using the guard(s) configured in
 * `keystone.routes.middleware`. Keys belonging to other owners cannot be
 * accessed, rotated, or revoked through these endpoints.
 *
 * Routes (named under "keystones." prefix):
 *   GET    /keystone/keys                  → keystones.index   — list owner's keys
 *   POST   /keystone/keys                  → keystones.store   — create a new key
 *   DELETE /keystone/keys/{keystone}       → keystones.destroy — revoke a key
 *   POST   /keystone/keys/{keystone}/rotate → keystones.rotate — rotate a key
 *
 * The `{keystone}` route parameter is resolved via route–model binding scoped to
 * the authenticated owner, so attempting to act on another user's key returns 404.
 */
class KeystoneController extends Controller
{
    /**
     * List all API keys belonging to the authenticated owner.
     *
     * Returns only active (non-revoked) keys by default. The secret column is
     * intentionally excluded from the response — it was shown once at creation
     * time and cannot be recovered from this endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        $owner = $this->resolveOwner($request);

        if ($owner === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var mixed $query */
        $query = $owner->keystones(); // @phpstan-ignore-line

        /** @var \Illuminate\Database\Eloquent\Collection<int, Keystone> $keys */
        $keys = $query->active()->get()->makeHidden(['secret']); // @phpstan-ignore-line

        return response()->json(['data' => $keys]);
    }

    /**
     * Create a new API key pair for the authenticated owner.
     *
     * Returns the plain client ID and the plain secret — the only time the
     * secret is ever exposed. Instruct consumers to store it in a secrets
     * manager immediately.
     *
     * Request body:
     *   - name        (string, required)
     *   - scopes      (array<string>, optional)
     *   - expires_at  (string Y-m-d, optional)
     *   - description (string, optional)
     *   - metadata    (object, optional)
     */
    public function store(Request $request): JsonResponse
    {
        $owner = $this->resolveOwner($request);

        if ($owner === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            /** @var array<string, mixed> $validated */
            $validated = $request->validate([
                'name'        => 'required|string|max:255',
                'scopes'      => 'sometimes|array',
                'scopes.*'    => 'string|max:100',
                'expires_at'  => 'sometimes|nullable|date|after:now',
                'description' => 'sometimes|nullable|string|max:1000',
                'metadata'    => 'sometimes|nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }

        $expiresAtStr = $validated['expires_at'] ?? null;
        $expiresAt = is_string($expiresAtStr) && $expiresAtStr !== ''
            ? CarbonImmutable::parse($expiresAtStr)
            : null;

        $options = array_filter([
            'description' => $validated['description'] ?? null,
            'metadata'    => $validated['metadata'] ?? null,
        ], fn ($v) => $v !== null);

        $nameRaw = $validated['name'] ?? '';
        $nameStr = is_scalar($nameRaw) ? (string) $nameRaw : '';
        
        /** @var array{client: string, secret: string, model: Keystone} $result */
        $result = $owner->createKeystone( // @phpstan-ignore-line
            name: $nameStr,

            scopes: (array) ($validated['scopes'] ?? []),
            expiresAt: $expiresAt,
            options: $options,
        );

        return response()->json([
            'message' => 'API key created. Store the secret — it will not be shown again.',
            'data'    => [
                'id'         => $result['model']->id,
                'name'       => $result['model']->name,
                'client'     => $result['client'],
                'secret'     => $result['secret'],
                'scopes'     => $result['model']->scopes,
                'expires_at' => $result['model']->expires_at?->toIso8601String(),
                'created_at' => $result['model']->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Revoke an API key belonging to the authenticated owner.
     *
     * Stamps `revoked_at` and evicts the Redis cache entry. The key is
     * immediately invalid on the very next request. This operation is
     * idempotent — revoking an already-revoked key is a no-op.
     */
    public function destroy(Request $request, int $keystoneId): JsonResponse
    {
        $owner = $this->resolveOwner($request);

        if ($owner === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var Keystone|null $keystone */
        $keystone = $owner->keystones()->find($keystoneId); // @phpstan-ignore-line

        if ($keystone === null) {
            return response()->json(['message' => 'API key not found.'], 404);
        }

        $keystone->revoke();

        return response()->json(['message' => 'API key revoked successfully.']);
    }

    /**
     * Rotate an API key belonging to the authenticated owner.
     *
     * Atomically revokes the specified key and issues a new replacement that
     * inherits all original settings (name, scopes, IP lists, rate limit).
     * If `keystone.rotation_grace_seconds` is configured, the old key remains
     * valid for that many seconds to allow a zero-downtime credential update.
     *
     * Returns the new key's credentials — store the secret immediately.
     */
    public function rotate(Request $request, int $keystoneId): JsonResponse
    {
        $owner = $this->resolveOwner($request);

        if ($owner === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        /** @var Keystone|null $keystone */
        $keystone = $owner->keystones()->find($keystoneId); // @phpstan-ignore-line

        if ($keystone === null) {
            return response()->json(['message' => 'API key not found.'], 404);
        }

        /** @var array{client: string, secret: string, model: Keystone} $result */
        $result = $owner->rotateKeystone($keystone); // @phpstan-ignore-line

        return response()->json([
            'message' => 'API key rotated. Store the new secret — it will not be shown again.',
            'data'    => [
                'id'         => $result['model']->id,
                'name'       => $result['model']->name,
                'client'     => $result['client'],
                'secret'     => $result['secret'],
                'scopes'     => $result['model']->scopes,
                'expires_at' => $result['model']->expires_at?->toIso8601String(),
                'created_at' => $result['model']->created_at->toIso8601String(),
            ],
        ]);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the authenticated owner model from the request.
     * Returns null if no authenticated user exists on the request.
     */
    private function resolveOwner(Request $request): ?Model
    {
        $user = $request->user();

        return $user instanceof Model ? $user : null;
    }
}
