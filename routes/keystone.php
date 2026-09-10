<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Schtzie\Keystone\Http\Controllers\KeystoneController;

/*
|--------------------------------------------------------------------------
| Keystone Management Routes
|--------------------------------------------------------------------------
|
| These routes expose a RESTful API for managing API keys belonging to the
| currently authenticated user (resolved via the standard Laravel auth guard).
|
| All routes are named under the "keystones" prefix (plural) so they integrate
| cleanly with front-end resource conventions and can be reverse-routed using
| the route() helper:
|
|   route('keystones.index')         → GET  /keystone/keys
|   route('keystones.store')         → POST /keystone/keys
|   route('keystones.destroy', $id)  → DELETE /keystone/keys/{keystone}
|   route('keystones.rotate', $id)   → POST /keystone/keys/{keystone}/rotate
|
| Routes are gated by the middleware stack defined in config('keystone.routes.middleware')
| (default: ['auth']). Swap this for 'auth:sanctum', 'auth:api', or any custom
| middleware that identifies the current owner.
|
| To disable these routes entirely (if you prefer to build your own controller),
| set `keystone.routes.enabled = false` in your config.
|
| Publish this file to customise route prefix, names, or add additional middleware:
|   php artisan vendor:publish --tag=keystone-routes
|
*/

Route::prefix(config('keystone.routes.prefix', 'keystone'))
    ->middleware(config('keystone.routes.middleware', ['auth']))
    ->name('keystones.')
    ->group(function (): void {
        // List all API keys belonging to the authenticated owner
        Route::get('keys', [KeystoneController::class, 'index'])->name('index');

        // Create and return a new API key pair for the authenticated owner
        Route::post('keys', [KeystoneController::class, 'store'])->name('store');

        // Revoke a specific key (soft-delete by stamping revoked_at)
        Route::delete('keys/{keystone}', [KeystoneController::class, 'destroy'])->name('destroy');

        // Rotate a key: atomically revoke the old one and issue a replacement
        Route::post('keys/{keystone}/rotate', [KeystoneController::class, 'rotate'])->name('rotate');
    });
