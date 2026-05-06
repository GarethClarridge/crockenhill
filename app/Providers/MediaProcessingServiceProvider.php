<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SpeakerIdentificationInterface;
use App\Presenters\SermonViewPresenter;
use App\Services\BritishEnglishConverter;
use App\Services\LivestreamSegmentationService;
use App\Services\NullSpeakerIdentificationService;
use App\Services\ProcessingInitiator;
use App\Services\ProcessingLogService;
use App\Services\ResemblyzerSpeakerIdentificationService;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use App\Services\TranscriptStorageService;
use App\Services\UnifiedMediaProcessor;
use App\Services\VideoSegmentationService;
use App\Services\VideoStorageService;
use Illuminate\Support\ServiceProvider;

class MediaProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Performance Optimization: Register core media processing services as singletons.
         * These services are stateless and frequently used during the request cycle.
         */
        $this->app->singleton(SermonStorageService::class);
        $this->app->singleton(SermonExposurePolicy::class);
        $this->app->singleton(TranscriptStorageService::class);
        $this->app->singleton(BritishEnglishConverter::class);
        $this->app->singleton(SermonViewPresenter::class);

        // Register supporting services that existing services depend on
        $this->app->bind(LivestreamSegmentationService::class, function ($app) {
            return new LivestreamSegmentationService(
                $app->make(VideoStorageService::class),
                $app->make(VideoSegmentationService::class),
                $app->make(ProcessingInitiator::class),
            );
        });
        $this->app->bind(ProcessingLogService::class);

        // Register the unified processor
        $this->app->bind(UnifiedMediaProcessor::class);

        // Register speaker identification service (provider-switchable via config)
        $this->app->bind(SpeakerIdentificationInterface::class, function ($app) {
            $provider = config('media-processing.speaker_identification.provider', 'null');

            return match ($provider) {
                'resemblyzer' => $app->make(ResemblyzerSpeakerIdentificationService::class),
                default => $app->make(NullSpeakerIdentificationService::class),
            };
        });

    }
}
