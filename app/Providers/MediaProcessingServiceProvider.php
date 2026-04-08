<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UnifiedMediaProcessor;
use Illuminate\Support\ServiceProvider;

class MediaProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Performance Optimization: Register core media processing services as singletons.
         * These services are stateless and frequently used during the request cycle.
         */
        $this->app->singleton(\App\Services\SermonStorageService::class);
        $this->app->singleton(\App\Services\SermonExposurePolicy::class);
        $this->app->singleton(\App\Services\TranscriptStorageService::class);
        $this->app->singleton(\App\Services\BritishEnglishConverter::class);
        $this->app->singleton(\App\Presenters\SermonViewPresenter::class);

        // Register supporting services that existing services depend on
        $this->app->bind(\App\Services\LivestreamSegmentationService::class, function ($app) {
            return new \App\Services\LivestreamSegmentationService(
                $app->make(\App\Services\VideoStorageService::class),
                $app->make(\App\Services\VideoSegmentationService::class),
                $app->make(\App\Services\ProcessingInitiator::class),
            );
        });
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

    }

    public function boot(): void {}
}
