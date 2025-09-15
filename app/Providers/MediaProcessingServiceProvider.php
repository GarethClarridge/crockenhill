<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AudioExtractionServiceInterface;
use App\Contracts\ProcessingHealthServiceInterface;
use App\Contracts\ProcessingRouterInterface;
use App\Contracts\SermonMetadataServiceInterface;
use App\Contracts\SermonProcessingServiceInterface;
use App\Contracts\VideoProcessingServiceInterface;
use App\Contracts\VideoStorageServiceInterface;
use App\Services\AudioExtractionService;
use App\Services\LivestreamSegmentationService;
use App\Services\LivestreamStatusService;
use App\Services\ProcessingHealthService;
use App\Services\ProcessingRouter;
use App\Services\ProcessingStrategyRegistry;
use App\Services\SermonAudioProcessingService;
use App\Services\SermonJobPipelineService;
use App\Services\SermonMetadataService;
use App\Services\SermonProcessingService;
use App\Services\SermonStatusManagementService;
use App\Services\SermonValidationService;
use App\Services\Strategies\AudioProcessingStrategy;
use App\Services\Strategies\DirectVideoProcessingStrategy;
use App\Services\Strategies\LivestreamProcessingStrategy;
use App\Services\VideoProcessingService;
use App\Services\VideoStorageService;
use Illuminate\Support\ServiceProvider;

/**
 * MediaProcessingServiceProvider - Service provider for media processing architecture
 *
 * Implements proper Laravel service registration following the refactoring plan.
 * Replaces service location pattern with proper dependency injection.
 */
class MediaProcessingServiceProvider extends ServiceProvider
{
    /**
     * Register services in the container
     */
    public function register(): void
    {
        // Register core service interfaces
        $this->app->bind(AudioExtractionServiceInterface::class, AudioExtractionService::class);
        $this->app->bind(VideoStorageServiceInterface::class, VideoStorageService::class);
        $this->app->bind(ProcessingHealthServiceInterface::class, ProcessingHealthService::class);
        $this->app->bind(SermonMetadataServiceInterface::class, SermonMetadataService::class);

        // Register new broken-down services
        $this->app->bind(LivestreamSegmentationService::class);
        $this->app->bind(LivestreamStatusService::class);
        $this->app->bind(SermonAudioProcessingService::class);
        $this->app->bind(SermonJobPipelineService::class);
        $this->app->bind(SermonStatusManagementService::class);
        $this->app->bind(SermonValidationService::class);

        // Register main service interfaces
        $this->app->bind(VideoProcessingServiceInterface::class, VideoProcessingService::class);
        $this->app->bind(SermonProcessingServiceInterface::class, function ($app) {
            return new SermonProcessingService(
                $app->make(SermonAudioProcessingService::class),
                $app->make(SermonJobPipelineService::class),
                $app->make(SermonStatusManagementService::class),
                $app->make(SermonValidationService::class),
                $app->make(\App\Services\SermonProcessingLogger::class)
            );
        });
        $this->app->bind(ProcessingRouterInterface::class, ProcessingRouter::class);

        // Register processing strategy registry as singleton
        $this->app->singleton(ProcessingStrategyRegistry::class, function ($app) {
            return new ProcessingStrategyRegistry([
                'audio' => $app->make(AudioProcessingStrategy::class),
                'sermon_video' => $app->make(DirectVideoProcessingStrategy::class),
                'livestream' => $app->make(LivestreamProcessingStrategy::class),
            ]);
        });

        // Register individual strategies
        $this->app->bind(AudioProcessingStrategy::class);
        $this->app->bind(DirectVideoProcessingStrategy::class);
        $this->app->bind(LivestreamProcessingStrategy::class);
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Any bootstrap logic for media processing services
        // For example, registering event listeners, publishing config files, etc.
    }

    /**
     * Get the services provided by the provider
     */
    public function provides(): array
    {
        return [
            AudioExtractionServiceInterface::class,
            VideoStorageServiceInterface::class,
            ProcessingHealthServiceInterface::class,
            SermonMetadataServiceInterface::class,
            LivestreamSegmentationService::class,
            LivestreamStatusService::class,
            SermonAudioProcessingService::class,
            SermonJobPipelineService::class,
            SermonStatusManagementService::class,
            SermonValidationService::class,
            VideoProcessingServiceInterface::class,
            SermonProcessingServiceInterface::class,
            ProcessingRouterInterface::class,
            ProcessingStrategyRegistry::class,
            AudioProcessingStrategy::class,
            DirectVideoProcessingStrategy::class,
            LivestreamProcessingStrategy::class,
        ];
    }
}
