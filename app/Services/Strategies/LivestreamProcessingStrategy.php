<?php

declare(strict_types=1);

namespace App\Services\Strategies;

use App\Contracts\ProcessingStrategyInterface;
use App\Services\ProcessingResult;
use App\Services\VideoProcessingService;
use Illuminate\Http\UploadedFile;

/**
 * LivestreamProcessingStrategy - Strategy for livestream video processing
 *
 * Handles full livestream recordings that require segmentation analysis.
 * Uses the existing job chain pattern for asynchronous processing.
 */
class LivestreamProcessingStrategy implements ProcessingStrategyInterface
{
    public function __construct(
        private VideoProcessingService $videoProcessor
    ) {}

    /**
     * Check if this strategy supports the given processing type
     */
    public function supports(string $type): bool
    {
        return $type === 'livestream';
    }

    /**
     * Process the uploaded livestream video
     */
    public function process(UploadedFile $file, array $options = []): ProcessingResult
    {
        return $this->videoProcessor->processWithSegmentation($file);
    }

    /**
     * Validate livestream video file for processing
     */
    public function validateFile(UploadedFile $file): array
    {
        $config = $this->getConfiguration();
        $errors = [];

        // Check file size (livestreams can be much larger)
        if ($file->getSize() > $config['max_file_size']) {
            $maxSizeGB = round($config['max_file_size'] / (1024 * 1024 * 1024), 1);
            $errors[] = "File size exceeds maximum limit of {$maxSizeGB}GB for livestream processing";
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $config['allowed_extensions'])) {
            $allowed = implode(', ', $config['allowed_extensions']);
            $errors[] = "File extension '{$extension}' not allowed for livestream. Allowed: {$allowed}";
        }

        // Check file validity
        if (! $file->isValid()) {
            $errors[] = 'Uploaded livestream file is corrupted or invalid';
        }

        // Additional livestream-specific validation could be added here
        // For example: minimum duration, video format requirements, etc.

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get configuration for livestream processing
     */
    public function getConfiguration(): array
    {
        return [
            'type' => 'livestream',
            'description' => 'Full livestream recording requiring segmentation',
            'allowed_extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'max_file_size' => config('livestream-processing.max_file_size', 2147483648), // 2GB
            'queue' => 'livestream-processing',
        ];
    }
}
