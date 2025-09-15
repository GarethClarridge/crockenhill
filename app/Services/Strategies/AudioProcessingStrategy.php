<?php

declare(strict_types=1);

namespace App\Services\Strategies;

use App\Contracts\AudioExtractionServiceInterface;
use App\Contracts\ProcessingStrategyInterface;
use App\Services\ProcessingResult;
use App\Services\SermonProcessingService;
use Illuminate\Http\UploadedFile;

/**
 * AudioProcessingStrategy - Strategy for direct audio file processing
 *
 * Handles direct audio uploads using the existing sermon processing pipeline.
 * Part of the strategy pattern implementation to unify processing approaches.
 */
class AudioProcessingStrategy implements ProcessingStrategyInterface
{
    public function __construct(
        private SermonProcessingService $sermonProcessor,
        private AudioExtractionServiceInterface $audioExtractor
    ) {}

    /**
     * Check if this strategy supports the given processing type
     */
    public function supports(string $type): bool
    {
        return $type === 'audio';
    }

    /**
     * Process the uploaded audio file
     */
    public function process(UploadedFile $file, array $options = []): ProcessingResult
    {
        // Use job chains for consistency with other processing types
        return $this->sermonProcessor->processSermon($file);
    }

    /**
     * Validate audio file for processing
     */
    public function validateFile(UploadedFile $file): array
    {
        try {
            $this->audioExtractor->validateAudioFile($file);

            return ['valid' => true, 'errors' => []];
        } catch (\InvalidArgumentException $e) {
            return ['valid' => false, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Get configuration for audio processing
     */
    public function getConfiguration(): array
    {
        return [
            'type' => 'audio',
            'description' => 'Audio sermon file',
            'allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
            'max_file_size' => config('sermon-processing.processing.max_file_size', 104857600), // 100MB
            'queue' => 'audio-processing',
        ];
    }
}
