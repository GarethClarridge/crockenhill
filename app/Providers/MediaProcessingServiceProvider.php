<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SpeakerIdentificationInterface;
use App\Services\BritishEnglishConverter;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Preacher\NullSpeakerIdentificationService;
use App\Services\Preacher\ResemblyzerSpeakerIdentificationService;
use App\Services\Processing\ProcessingInitiator;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Sermon\LivestreamSegmentationService;
use Illuminate\Support\ServiceProvider;

class MediaProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Register media-processing-specific stateless collaborators here.
         * Sermon presentation and delivery services are registered centrally in
         * AppServiceProvider so their scoped lifetimes are not accidentally overridden.
         */
        $this->app->singleton(BritishEnglishConverter::class);

        // Register supporting services that existing services depend on
        $this->app->bind(LivestreamSegmentationService::class, function ($app) {
            return new LivestreamSegmentationService(
                $app->make(VideoStorageService::class),
                $app->make(VideoSegmentationService::class),
                $app->make(ProcessingInitiator::class),
                $app->make(ProcessingRunOrchestrator::class),
            );
        });
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
