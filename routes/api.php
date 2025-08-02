<?php

use App\Http\Controllers\AutomatedSermonController;
use App\Http\Controllers\Api\LivestreamProcessingController;
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
  // Automated sermon upload endpoint
  Route::post('automated', [AutomatedSermonController::class, 'upload'])
    ->middleware(['auth:sanctum', 'throttle:sermon-upload'])
    ->name('automated.upload');

  // Processing status and management endpoints
  Route::prefix('processing')->name('processing.')->group(function () {
    // Get processing status by ID
    Route::get('{processingId}/status', [AutomatedSermonController::class, 'status'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('status');

    // Retry failed processing
    Route::post('{processingId}/retry', [AutomatedSermonController::class, 'retry'])
      ->middleware(['auth:sanctum', 'throttle:sermon-retry'])
      ->name('retry');

    // Apply graceful degradation to failed processing
    Route::post('{processingId}/graceful-degradation', [AutomatedSermonController::class, 'gracefulDegradation'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('graceful-degradation');

    // Get processing statistics
    Route::get('statistics', [AutomatedSermonController::class, 'statistics'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('statistics');

    // Get failed processing logs
    Route::get('failed', [AutomatedSermonController::class, 'failed'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('failed');

    // System health check
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
| video upload, segmentation, sermon extraction, and status monitoring.
|
*/

Route::prefix('livestreams')->name('api.livestream.')->middleware('cors')->group(function () {
  // Video upload endpoint for livestream processing
  Route::post('process', [LivestreamProcessingController::class, 'uploadVideo'])
    ->middleware(['auth:sanctum', 'throttle:livestream-upload'])
    ->name('process');

  // Processing status and management endpoints
  Route::prefix('processing')->name('processing.')->group(function () {
    // Get processing status by ID
    Route::get('{processingId}/status', [LivestreamProcessingController::class, 'getStatus'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('status')
      ->where('processingId', '[0-9a-f-]{36}');

    // Get full processing result with segments
    Route::get('{processingId}/result', [LivestreamProcessingController::class, 'getResult'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('result')
      ->where('processingId', '[0-9a-f-]{36}');

    // Retry failed processing
    Route::post('{processingId}/retry', [LivestreamProcessingController::class, 'retryProcessing'])
      ->middleware(['auth:sanctum', 'throttle:livestream-retry'])
      ->name('retry')
      ->where('processingId', '[0-9a-f-]{36}');

    // Cancel ongoing processing
    Route::post('{processingId}/cancel', [LivestreamProcessingController::class, 'cancelProcessing'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('cancel')
      ->where('processingId', '[0-9a-f-]{36}');

    // Get processing statistics summary
    Route::get('summary', [LivestreamProcessingController::class, 'getProcessingSummary'])
      ->middleware(['auth:sanctum', 'throttle:api'])
      ->name('summary');
  });
});
