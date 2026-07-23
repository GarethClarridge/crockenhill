<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Enums\MediaType;
use App\Exceptions\InvalidFileException;
use App\Services\Processing\MediaValidationService;
use Illuminate\Http\UploadedFile;

/**
 * Service for managing initial validation of uploaded audio files.
 *
 * Historically responsible for audio extraction operations, this service now acts as a
 * dedicated application-boundary handler for validating that raw uploaded audio files
 * meet size, mime-type, and extension requirements prior to queue ingestion, delegating
 * the actual validation rules and assertions to {@see MediaValidationService}.
 */
class AudioExtractionService
{
    /**
     * Create a new AudioExtractionService instance.
     *
     * @param  MediaValidationService  $mediaValidation  The underlying media validation utility
     */
    public function __construct(
        private readonly MediaValidationService $mediaValidation
    ) {}

    /**
     * Validate an uploaded audio file against configured size and format constraints.
     *
     * Validates that the file has uploaded successfully, its size is within acceptable
     * bounds, and its MIME type and original file extension match configured allowed types.
     *
     * @param  UploadedFile  $file  The uploaded file to validate
     * @return void
     *
     * @throws InvalidFileException If the file is corrupted, too large, or of an unsupported format
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        $this->mediaValidation->validateUploadedFile(MediaType::Audio, $file);
    }
}
