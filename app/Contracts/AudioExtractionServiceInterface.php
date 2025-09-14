<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * AudioExtractionServiceInterface - Handles audio extraction and validation
 *
 * Extracts audio processing responsibilities from VideoProcessingService
 * following the Single Responsibility Principle as outlined in the refactoring plan.
 */
interface AudioExtractionServiceInterface
{
    /**
     * Extract audio from video file optimized for transcription
     */
    public function extractFromVideo(string $videoPath, float $duration): string;

    /**
     * Compress audio file for transcription service requirements
     */
    public function compressForTranscription(string $audioPath): string;

    /**
     * Validate audio file meets processing requirements
     */
    public function validateAudioFile(UploadedFile $file): void;
}