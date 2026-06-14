# Hardening Follow-up: Wire `media-admin-action` Rate Limiter

The `media-admin-action` rate limiter has been defined in `RateLimitServiceProvider`, but needs to be wired to the relevant administrative routes in `routes/api.php`. This falls outside Sentinel's autonomous scope for direct route middleware modification.

**Target Routes:**
- `api.media.processing.status` (Currently uses `throttle:api`) - Recommended: Keep `api` or switch to `media-admin-action` if status checks should be tighter.
- `api.media.processing.cancel` (Currently uses `throttle:api`) - Recommended: Switch to `throttle:media-admin-action`.
- `api.media.processing.retry` (Currently uses `throttle:media-retry`) - Recommended: Switch to `throttle:media-admin-action` or keep `media-retry`.
- `api.media.processing.confirm-segment` (Currently uses `throttle:api`) - Recommended: Switch to `throttle:media-admin-action`.

**Proposed Change in `routes/api.php`:**
```php
Route::middleware(['auth:sanctum', 'media.process'])
    ->name('api.media.processing.')
    ->group(function () {
        Route::get('media/processing/{processingId}/status', [MediaController::class, 'status'])
            ->middleware('throttle:media-admin-action')
            ->name('status');

        // ...

        Route::delete('media/processing/{processingId}', [MediaController::class, 'cancel'])
            ->middleware('throttle:media-admin-action')
            ->name('cancel');

        Route::post('media/processing/{processingId}/retry', [MediaController::class, 'retry'])
            ->middleware('throttle:media-admin-action')
            ->name('retry');

        Route::post('media/processing/{processingId}/confirm-segment', [MediaController::class, 'confirmSegment'])
            ->middleware('throttle:media-admin-action')
            ->name('confirm-segment');
    });
```
