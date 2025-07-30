<?php

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
