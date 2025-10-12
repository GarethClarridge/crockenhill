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

// Unified media processing endpoints
Route::prefix('media')->name('api.media.')->group(function () {
    // Upload endpoints for each type
    Route::post('{type}', [MediaController::class, 'upload'])
        ->where('type', 'audio|video|livestream')
        ->middleware(['cors', 'auth:sanctum', 'throttle:media-upload'])
        ->name('upload');
});

// Processing management routes - defined separately to avoid nested group issues
Route::get('media/processing/{processingId}/status', [MediaController::class, 'status'])
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('api.media.processing.status');

Route::delete('media/processing/{processingId}', [MediaController::class, 'cancel'])
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('api.media.processing.cancel');

Route::post('media/processing/{processingId}/retry', [MediaController::class, 'retry'])
    ->middleware(['auth:sanctum', 'throttle:media-retry'])
    ->name('api.media.processing.retry');
