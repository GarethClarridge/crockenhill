<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SermonProcessingService;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Support\ServiceProvider;

class MediaProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register core service interfaces that existing services depend on
        $this->app->bind(\App\Contracts\VideoStorageServiceInterface::class, \App\Services\VideoStorageService::class);
        $this->app->bind(\App\Contracts\AudioExtractionServiceInterface::class, \App\Services\AudioExtractionService::class);
        $this->app->bind(\App\Contracts\SermonProcessingServiceInterface::class, \App\Services\SermonProcessingService::class);

        // Register supporting services that existing services depend on
        $this->app->bind(\App\Services\LivestreamSegmentationService::class, function ($app) {
            return new \App\Services\LivestreamSegmentationService(
                $app->make(\App\Contracts\VideoStorageServiceInterface::class),
                $app->make(\App\Services\VideoSegmentationService::class),
                $app->make(\App\Services\ProcessingPipelineBuilder::class),
                $app->make(\App\Services\ProcessingInitiator::class)
            );
        });
        $this->app->bind(\App\Services\LivestreamStatusService::class);
        $this->app->bind(\App\Services\ProcessingLogService::class);

        // Register the unified processor
        $this->app->bind(UnifiedMediaProcessor::class, function ($app) {
            return new UnifiedMediaProcessor(
                $app->make(\App\Services\LivestreamSegmentationService::class),
                $app->make(SermonProcessingService::class),
                $app->make(\App\Services\ProcessingPipelineBuilder::class),
                $app->make(\App\Services\ProcessingLogService::class),
                $app->make(\App\Services\ProcessingInitiator::class)
            );
        });

        // Register speaker identification service (provider-switchable via config)
        $this->app->bind(\App\Contracts\SpeakerIdentificationInterface::class, function ($app) {
            $provider = config('media-processing.speaker_identification.provider', 'null');

            return match ($provider) {
                'resemblyzer' => $app->make(\App\Services\ResemblyzerSpeakerIdentificationService::class),
                default => $app->make(\App\Services\NullSpeakerIdentificationService::class),
            };
        });

        // Keep existing service registrations that work
        $this->app->bind(\App\Services\SermonAudioProcessingService::class, function ($app) {
            return new \App\Services\SermonAudioProcessingService(
                $app->make(\App\Services\MetadataExtractionService::class),
                $app->make(\App\Services\ProcessingPipelineBuilder::class),
                $app->make(\App\Services\MediaValidationService::class)
            );
        });
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
