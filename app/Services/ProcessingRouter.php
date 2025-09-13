<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * ProcessingRouter - Smart routing for different types of media uploads
 * 
 * This service routes uploads to the appropriate processing service based on the explicit
 * choice made by the user in the upload form. No auto-detection is needed since the UI
 * already makes the user specify the type.
 */
class ProcessingRouter
{
    public function __construct(
        private readonly VideoProcessingService $videoProcessor,
        private readonly SermonProcessingService $sermonProcessor
    ) {}

    /**
     * Route livestream video to segmentation pipeline
     * This is for full livestream recordings that need segment analysis
     */
    public function routeLivestreamVideo(UploadedFile $file): ProcessingResult
    {
        Log::info('Routing to livestream video processing', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        return $this->videoProcessor->processWithSegmentation($file);
    }

    /**
     * Route sermon video to direct processing pipeline
     * This is for sermon-only video files that don't need segmentation
     */
    public function routeSermonVideo(UploadedFile $file): ProcessingResult
    {
        Log::info('Routing to direct sermon video processing', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        return $this->videoProcessor->processDirectly($file);
    }

    /**
     * Route audio to sermon processing pipeline
     * This is for direct audio uploads
     */
    public function routeAudio(UploadedFile $file): ProcessingResult
    {
        Log::info('Routing to audio processing', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);

        return $this->sermonProcessor->processSermon($file);
    }

    /**
     * Get supported processing types
     */
    public function getSupportedTypes(): array
    {
        return [
            'livestream' => [
                'description' => 'Full livestream recording requiring segmentation',
                'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
                'max_size' => config('livestream-processing.max_file_size', 2147483648), // 2GB
            ],
            'sermon_video' => [
                'description' => 'Direct sermon video file',
                'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
                'max_size' => config('sermon-processing.processing.max_file_size', 104857600), // 100MB
            ],
            'audio' => [
                'description' => 'Audio sermon file',
                'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
                'max_size' => config('sermon-processing.processing.max_file_size', 104857600), // 100MB
            ],
        ];
    }

    /**
     * Validate file for specific processing type
     */
    public function validateFileForType(UploadedFile $file, string $type): array
    {
        $supportedTypes = $this->getSupportedTypes();
        
        if (!isset($supportedTypes[$type])) {
            return [
                'valid' => false,
                'errors' => ["Unsupported processing type: {$type}"],
            ];
        }

        $config = $supportedTypes[$type];
        $errors = [];

        // Check file size
        if ($file->getSize() > $config['max_size']) {
            $maxSizeMB = round($config['max_size'] / (1024 * 1024));
            $errors[] = "File size exceeds maximum limit of {$maxSizeMB}MB for {$type} processing";
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $config['allowed_extensions'])) {
            $allowed = implode(', ', $config['allowed_extensions']);
            $errors[] = "File extension '{$extension}' not allowed for {$type}. Allowed: {$allowed}";
        }

        // Check file validity
        if (!$file->isValid()) {
            $errors[] = 'Uploaded file is corrupted or invalid';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get routing statistics for monitoring
     */
    public function getRoutingStatistics(): array
    {
        // This could be enhanced with actual metrics collection
        return [
            'supported_types' => array_keys($this->getSupportedTypes()),
            'routes_available' => [
                'livestream' => 'VideoProcessingService::processWithSegmentation',
                'sermon_video' => 'VideoProcessingService::processDirectly',
                'audio' => 'SermonProcessingService::processSermon',
            ],
            'validation_rules' => $this->getSupportedTypes(),
        ];
    }
}