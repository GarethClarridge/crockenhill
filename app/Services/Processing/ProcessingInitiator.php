<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Traits\SanitizesLogData;
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
    use SanitizesLogData;

    public function __construct(
        private readonly MetadataExtractionService $metadataService
    ) {}

    /**
     * Initialise a new media processing run.
     *
     * Generates a processing ID, extracts date/service metadata from the file,
     * and creates a MediaProcessingLog record in PENDING state.
     *
     * For media types where date/service extraction differs from the video strategy
     * (e.g. audio using ID3 tags), pass a pre-extracted metadata array via
     * $preExtractedMetadata. When non-null, it replaces the video-style
     * date/service extraction entirely and is used as the base processing metadata.
     *
     * @param  array<string, mixed>  $additionalLogData  Extra columns to merge into the log record (e.g. source_file_path, file_hash)
     * @param  array<string, mixed>|null  $preExtractedMetadata  When non-null, replaces video-style date/service extraction
     * @return MediaProcessingLog The newly created processing log
     */
    public function initiateProcessing(
        UploadedFile $file,
        MediaType $processingType,
        ?string $clientFileDate = null,
        array $additionalLogData = [],
        ?array $preExtractedMetadata = null
    ): MediaProcessingLog {
        $processingId = Str::uuid()->toString();

        $extractedIdentity = [];

        if ($preExtractedMetadata !== null) {
            $baseMetadata = $preExtractedMetadata;
            Log::info('Initiating media processing with pre-extracted metadata', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'processing_type' => $processingType->value,
                'original_filename' => $this->sanitizeForLog($file->getClientOriginalName()),
            ]);
        } else {
            $extractedDateTime = $this->metadataService->extractDateFromVideo($file, $clientFileDate);
            $extractedService = $this->determineService($extractedDateTime, $file->getClientOriginalName());

            Log::info('Extracted metadata from media file', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'processing_type' => $processingType->value,
                'original_filename' => $this->sanitizeForLog($file->getClientOriginalName()),
                'extracted_date' => $this->sanitizeForLog($extractedDateTime->toDateString()),
                'extracted_datetime' => $this->sanitizeForLog($extractedDateTime->toDateTimeString()),
                'extracted_service' => $extractedService->value,
            ]);

            $extractedIdentity = [
                'extracted_date' => $extractedDateTime->toDateString(),
                'extracted_service' => $extractedService,
            ];

            $baseMetadata = [
                'date_extraction_method' => 'video_metadata_or_filename',
                'service_extraction_method' => 'datetime_timestamp',
            ];
        }

        // Merge additional processing_metadata if provided, keeping base metadata
        $extraMetadata = $additionalLogData['processing_metadata'] ?? [];
        unset($additionalLogData['processing_metadata']);

        $logData = array_merge([
            'processing_id' => $processingId,
            'processing_type' => $processingType,
            'original_filename' => $file->getClientOriginalName(),
            'owner_user_id' => Auth::id(),
            'status' => ProcessingStatus::Pending,
            'current_step' => "{$processingType->value}_processing_initiated",
            'processing_metadata' => array_merge($baseMetadata, $extraMetadata),
        ], $extractedIdentity, $additionalLogData);

        return MediaProcessingLog::query()->create($logData);
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
