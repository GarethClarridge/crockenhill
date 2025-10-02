<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UnifiedMediaProcessor;
use App\Services\VideoProcessingService;
use App\Services\SermonProcessingService;
use Illuminate\Support\ServiceProvider;

class MediaProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register core service interfaces that existing services depend on
        $this->app->bind(\App\Contracts\VideoStorageServiceInterface::class, \App\Services\VideoStorageService::class);
        $this->app->bind(\App\Contracts\AudioExtractionServiceInterface::class, \App\Services\AudioExtractionService::class);
        $this->app->bind(\App\Contracts\SermonProcessingServiceInterface::class, \App\Services\SermonProcessingService::class);
        $this->app->bind(\App\Contracts\VideoProcessingServiceInterface::class, \App\Services\VideoProcessingService::class);

        // Register supporting services that existing services depend on
        $this->app->bind(\App\Services\LivestreamSegmentationService::class);
        $this->app->bind(\App\Services\LivestreamStatusService::class);
        $this->app->bind(\App\Services\ProcessingLogService::class);

        // Register the unified processor
        $this->app->bind(UnifiedMediaProcessor::class, function ($app) {
            return new UnifiedMediaProcessor(
                $app->make(VideoProcessingService::class),
                $app->make(SermonProcessingService::class),
                $app->make(\App\Services\ProcessingPipelineBuilder::class)
            );
        });

        // Keep existing service registrations that work
        $this->app->bind(VideoProcessingService::class);
        $this->app->bind(SermonProcessingService::class);
    }

    public function boot(): void
    {
        // Publish config if needed
        $this->publishes([
            __DIR__.'/../../config/media-processing.php' => config_path('media-processing.php'),
        ], 'media-processing');
    }
}
