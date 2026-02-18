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
        // Register supporting services that existing services depend on
        $this->app->bind(\App\Services\LivestreamSegmentationService::class, function ($app) {
            return new \App\Services\LivestreamSegmentationService(
                $app->make(\App\Services\VideoStorageService::class),
                $app->make(\App\Services\VideoSegmentationService::class),
                $app->make(\App\Services\ProcessingPipelineBuilder::class),
                $app->make(\App\Services\ProcessingInitiator::class)
            );
        });
        $this->app->bind(\App\Services\LivestreamStatusService::class);
        $this->app->bind(\App\Services\ProcessingLogService::class);

        // Register the unified processor
        $this->app->bind(UnifiedMediaProcessor::class);

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
