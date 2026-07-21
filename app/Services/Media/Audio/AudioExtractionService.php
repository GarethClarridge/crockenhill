<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Enums\MediaType;
use App\Exceptions\InvalidFileException;
use App\Services\Processing\MediaValidationService;
use Illuminate\Http\UploadedFile;

/**
 * AudioExtractionService - Handles audio extraction and processing
 *
 * Extracted from VideoProcessingService to follow Single Responsibility Principle.
 * Handles all audio-related operations including extraction from video and compression.
 */
class AudioExtractionService
{
    public function __construct(private readonly MediaValidationService $mediaValidation) {}

    /**
     * Validate an uploaded audio file against configured size and type limits.
     *
     * @param  UploadedFile  $file  The uploaded file to validate
     *
     * @throws InvalidFileException If the file is too large or an unsupported format
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        $this->mediaValidation->validateUploadedFile(MediaType::Audio, $file);
    }
}
