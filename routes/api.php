<?php

use App\Enums\ApiTokenAbility;
use App\Http\Controllers\Api\ChurchServiceController;
use App\Http\Controllers\Api\MailgunInboundWebhookController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\SermonApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authentication route
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Sermon data endpoints (read-only)
Route::prefix('sermons')->name('api.sermons.')->group(function () {
    Route::get('/', [SermonApiController::class, 'index'])
        ->middleware('throttle:api')
        ->name('index');

    Route::get('{sermon}', [SermonApiController::class, 'show'])
        ->middleware('throttle:api')
        ->name('show');
});

Route::prefix('services')
    ->name('api.services.')
    ->middleware([
        'auth:sanctum',
        'service.access',
    ])
    ->group(function () {
        Route::post('openlp', [ChurchServiceController::class, 'store'])
            ->middleware('throttle:media-upload')
            ->name('openlp.store');

        Route::get('{churchService}', [ChurchServiceController::class, 'show'])
            ->middleware('throttle:api')
            ->name('show');
    });

Route::post('webhooks/mailgun/inbound', MailgunInboundWebhookController::class)
    ->middleware([
        'throttle:mailgun-probe',
        'mailgun.signature',
        'throttle:mailgun-inbound',
    ])
    ->name('api.webhooks.mailgun.inbound');

// Unified media processing endpoints
Route::prefix('media')->name('api.media.')->group(function () {
    // Upload endpoints for each type
    Route::post('{type}', [MediaController::class, 'upload'])
        ->where('type', 'audio|video|livestream')
        ->middleware([
            'auth:sanctum',
            'ability:'.ApiTokenAbility::MEDIA_PROCESS->value,
            'media.process',
            'throttle:media-upload',
        ])
        ->name('upload');
});

// Processing management routes - defined separately to avoid nested group issues
Route::get('media/processing/{processingId}/status', [MediaController::class, 'status'])
    ->middleware([
        'auth:sanctum',
        'ability:'.ApiTokenAbility::MEDIA_PROCESS->value,
        'media.process',
        'throttle:api',
    ])
    ->name('api.media.processing.status');

Route::delete('media/processing/{processingId}', [MediaController::class, 'cancel'])
    ->middleware([
        'auth:sanctum',
        'ability:'.ApiTokenAbility::MEDIA_PROCESS->value,
        'media.process',
        'throttle:api',
    ])
    ->name('api.media.processing.cancel');

Route::post('media/processing/{processingId}/retry', [MediaController::class, 'retry'])
    ->middleware([
        'auth:sanctum',
        'ability:'.ApiTokenAbility::MEDIA_PROCESS->value,
        'media.process',
        'throttle:media-retry',
    ])
    ->name('api.media.processing.retry');

Route::post('media/processing/{processingId}/confirm-segment', [MediaController::class, 'confirmSegment'])
    ->middleware([
        'auth:sanctum',
        'ability:'.ApiTokenAbility::MEDIA_PROCESS->value,
        'media.process',
        'throttle:api',
    ])
    ->name('api.media.processing.confirm-segment');
