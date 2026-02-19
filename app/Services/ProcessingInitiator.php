<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles shared processing initialisation logic for video and livestream uploads.
 *
 * Extracts the duplicated startup sequence (UUID generation, metadata extraction,
 * service detection, processing log creation) from UnifiedMediaProcessor and
 * LivestreamSegmentationService into a single reusable path.
 */
class ProcessingInitiator
{
    public function __construct(
        private readonly MetadataExtractionService $metadataService
    ) {}

    /**
     * Initialise a new media processing run.
     *
     * Generates a processing ID, extracts date/service metadata from the file,
     * and creates a MediaProcessingLog record in PENDING state.
     *
     * @param  array<string, mixed>  $additionalLogData  Extra columns to merge into the log record (e.g. file_size, duration)
     * @return MediaProcessingLog The newly created processing log
     */
    public function initiateProcessing(
        UploadedFile $file,
        string $processingType,
        ?string $clientFileDate = null,
        array $additionalLogData = []
    ): MediaProcessingLog {
        $processingId = Str::uuid()->toString();

        $extractedDateTime = $this->metadataService->extractDateFromVideo($file, $clientFileDate);
        $extractedService = $this->determineService($extractedDateTime, $file->getClientOriginalName());

        Log::info('Extracted metadata from media file', [
            'processing_id' => $processingId,
            'processing_type' => $processingType,
            'original_filename' => $file->getClientOriginalName(),
            'extracted_date' => $extractedDateTime->toDateString(),
            'extracted_datetime' => $extractedDateTime->toDateTimeString(),
            'extracted_service' => $extractedService->value,
        ]);

        $baseMetadata = [
            'extracted_date' => $extractedDateTime->toDateString(),
            'extracted_datetime' => $extractedDateTime->toDateTimeString(),
            'extracted_service' => $extractedService->value,
            'date_extraction_method' => 'video_metadata_or_filename',
            'service_extraction_method' => 'datetime_timestamp',
        ];

        // Merge additional processing_metadata if provided, keeping base metadata
        $extraMetadata = $additionalLogData['processing_metadata'] ?? [];
        unset($additionalLogData['processing_metadata']);

        $logData = array_merge([
            'processing_id' => $processingId,
            'processing_type' => $processingType,
            'original_filename' => $file->getClientOriginalName(),
            'owner_user_id' => Auth::id(),
            'status' => ProcessingStatus::PENDING,
            'current_step' => "{$processingType}_processing_initiated",
            'processing_metadata' => array_merge($baseMetadata, $extraMetadata),
        ], $additionalLogData);

        return MediaProcessingLog::create($logData);
    }

    /**
     * Determine the sermon service from datetime or filename.
     *
     * If the extracted datetime has actual time information (not midnight),
     * uses time-based detection. Otherwise falls back to filename patterns.
     */
    private function determineService(Carbon $dateTime, string $filename): SermonService
    {
        if ($dateTime->hour !== 0 || $dateTime->minute !== 0 || $dateTime->second !== 0) {
            return $this->metadataService->determineServiceFromTime($dateTime);
        }

        return $this->metadataService->determineServiceFromFilename($filename);
    }
}
