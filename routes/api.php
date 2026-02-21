<?php

use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\SermonApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authentication route
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Sermon data endpoints (read-only)
Route::prefix('sermons')->name('api.sermons.')->middleware('cors')->group(function () {
    Route::get('/', [SermonApiController::class, 'index'])
        ->middleware('throttle:api')
        ->name('index');

    Route::get('{sermon}', [SermonApiController::class, 'show'])
        ->middleware('throttle:api')
        ->name('show');
});

// Unified media processing endpoints - restricted to admins
Route::prefix('media')->name('api.media.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // Upload endpoints for each type
    Route::post('{type}', [MediaController::class, 'upload'])
        ->where('type', 'audio|video|livestream')
        ->middleware(['cors', 'throttle:media-upload'])
        ->name('upload');

    // Processing management routes
    Route::get('processing/{processingId}/status', [MediaController::class, 'status'])
        ->middleware('throttle:api')
        ->name('processing.status');

    Route::delete('processing/{processingId}', [MediaController::class, 'cancel'])
        ->middleware('throttle:api')
        ->name('processing.cancel');

    Route::post('processing/{processingId}/retry', [MediaController::class, 'retry'])
        ->middleware('throttle:media-retry')
        ->name('processing.retry');
});
