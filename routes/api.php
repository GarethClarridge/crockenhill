<?php

use App\Http\Controllers\Api\LivestreamProcessingController;
use App\Http\Controllers\AutomatedSermonController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Automated Sermon Processing API Routes
|--------------------------------------------------------------------------
|
| These routes handle automated sermon processing functionality including
| file upload, status checking, and processing management.
|
*/

Route::prefix('sermons')->name('api.sermons.')->middleware('cors')->group(function () {
    // Sermon data endpoints
    Route::get('/', [\App\Http\Controllers\Api\SermonApiController::class, 'index'])
        ->middleware('throttle:api')
        ->name('index');
    
    Route::get('{sermon}', [\App\Http\Controllers\Api\SermonApiController::class, 'show'])
        ->middleware('throttle:api')
        ->name('show');

    // Unified media processing endpoints - different entry points, same controller
    Route::post('livestream', [AutomatedSermonController::class, 'uploadLivestream'])
        ->middleware(['auth:sanctum', 'throttle:sermon-upload'])
        ->name('livestream.upload');

    Route::post('video', [AutomatedSermonController::class, 'uploadVideo'])
        ->middleware(['auth:sanctum', 'throttle:sermon-video-upload'])
        ->name('video.upload');

    Route::post('audio', [AutomatedSermonController::class, 'upload'])
        ->middleware(['auth:sanctum', 'throttle:sermon-upload'])
        ->name('audio.upload');

    // Legacy endpoint for backward compatibility
    Route::post('automated', [AutomatedSermonController::class, 'upload'])
        ->middleware(['auth:sanctum', 'throttle:sermon-upload'])
        ->name('automated.upload');

    // Unified status/management (existing endpoints)
    Route::prefix('processing')->name('processing.')->group(function () {
        Route::get('{processingId}/status', [AutomatedSermonController::class, 'status'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('status');

        Route::delete('{processingId}', [AutomatedSermonController::class, 'cancel'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('cancel');

        Route::post('{processingId}/retry', [AutomatedSermonController::class, 'retry'])
            ->middleware(['auth:sanctum', 'throttle:sermon-retry'])
            ->name('retry');

        Route::post('{processingId}/graceful-degradation', [AutomatedSermonController::class, 'gracefulDegradation'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('graceful-degradation');

        // Keep existing monitoring endpoints
        Route::get('statistics', [AutomatedSermonController::class, 'statistics'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('statistics');

        Route::get('failed', [AutomatedSermonController::class, 'failed'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('failed');

        Route::get('health', [AutomatedSermonController::class, 'health'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('health');
    });
});

/*
|--------------------------------------------------------------------------
| Livestream Processing API Routes
|--------------------------------------------------------------------------
|
| These routes handle livestream video processing functionality including
| file upload, status checking, and processing management.
|
*/

Route::prefix('livestreams')->name('api.livestream.')->middleware('cors')->group(function () {
    Route::post('process', [LivestreamProcessingController::class, 'uploadVideo'])
        ->middleware(['auth:sanctum', 'throttle:livestream-upload'])
        ->name('upload');

    Route::prefix('processing')->name('processing.')->group(function () {
        Route::get('{processingId}/status', [LivestreamProcessingController::class, 'getStatus'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('status');

        Route::get('{processingId}/result', [LivestreamProcessingController::class, 'getResult'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('result');

        Route::post('{processingId}/retry', [LivestreamProcessingController::class, 'retryProcessing'])
            ->middleware(['auth:sanctum', 'throttle:livestream-retry'])
            ->name('retry');

        Route::delete('{processingId}', [LivestreamProcessingController::class, 'cancel'])
            ->middleware(['auth:sanctum', 'throttle:api'])
            ->name('cancel');
    });

    Route::get('processing/summary', [LivestreamProcessingController::class, 'getProcessingSummary'])
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->name('processing.summary');
});
