<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessingRouterInterface;
use App\Exceptions\InvalidFileException;
use App\Exceptions\UnsupportedProcessingTypeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * ProcessingRouter - Smart routing for different types of media uploads using strategy pattern
 *
 * Refactored to use strategy pattern as outlined in the media processing refactoring plan.
 * Routes uploads to appropriate processing strategies based on user selection.
 */
class ProcessingRouter implements ProcessingRouterInterface
{
    public function __construct(
        private readonly ProcessingStrategyRegistry $strategyRegistry
    ) {}

    /**
     * Route processing based on type using strategy pattern
     */
    public function route(string $type, UploadedFile $file, array $options = []): ProcessingResult
    {
        Log::info('Routing processing request', [
            'type' => $type,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        $strategy = $this->strategyRegistry->getStrategy($type);

        if (!$strategy) {
            throw new UnsupportedProcessingTypeException($type);
        }

        $validation = $strategy->validateFile($file);
        if (!$validation['valid']) {
            throw new InvalidFileException($validation['errors']);
        }

        return $strategy->process($file, $options);
    }

    /**
     * Route livestream video to segmentation pipeline (legacy method for backward compatibility)
     * This is for full livestream recordings that need segment analysis
     */
    public function routeLivestreamVideo(UploadedFile $file): ProcessingResult
    {
        return $this->route('livestream', $file);
    }

    /**
     * Route sermon video to direct processing pipeline (legacy method for backward compatibility)
     * This is for sermon-only video files that don't need segmentation
     */
    public function routeSermonVideo(UploadedFile $file): ProcessingResult
    {
        return $this->route('sermon_video', $file);
    }

    /**
     * Route audio to sermon processing pipeline (legacy method for backward compatibility)
     * This is for direct audio uploads
     */
    public function routeAudio(UploadedFile $file): ProcessingResult
    {
        return $this->route('audio', $file);
    }

    /**
     * Get supported processing types from strategy registry
     */
    public function getSupportedTypes(): array
    {
        return $this->strategyRegistry->getAllConfigurations();
    }

    /**
     * Validate file for specific processing type using strategy
     */
    public function validateFileForType(UploadedFile $file, string $type): array
    {
        try {
            $strategy = $this->strategyRegistry->getStrategy($type);
            return $strategy->validateFile($file);
        } catch (UnsupportedProcessingTypeException $e) {
            return [
                'valid' => false,
                'errors' => ["Unsupported processing type: {$type}"],
            ];
        }
    }

    /**
     * Get routing statistics for monitoring
     */
    public function getRoutingStatistics(): array
    {
        return [
            'supported_types' => $this->strategyRegistry->getSupportedTypes(),
            'strategies_registered' => count($this->strategyRegistry->getSupportedTypes()),
            'configurations' => $this->strategyRegistry->getAllConfigurations(),
        ];
    }
}
