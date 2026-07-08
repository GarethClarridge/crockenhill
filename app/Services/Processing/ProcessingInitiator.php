<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Traits\SanitizesLogData;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * Identity Extraction Hierarchy:
     * 1. $serviceOverride: Always wins for service determination if provided.
     * 2. $preExtractedMetadata: When non-null, replaces the standard video-style
     *    date/service extraction entirely (used for audio with ID3 tags).
     * 3. Extraction/Determination: Falls back to extracting date from video
     *    metadata/filename and determining service from time/filename.
     *
     * @param  UploadedFile  $file  The uploaded media file
     * @param  MediaType  $processingType  The type of processing to initiate
     * @param  string|null  $clientFileDate  Optional date provided by the client (YYYY-MM-DD)
     * @param  array{
     *     source_file_path?: string|false,
     *     file_hash?: string|null,
     *     dedup_key?: string|null,
     *     file_size?: int,
     *     duration?: float,
     *     processing_metadata?: array<string, mixed>
     * }  $additionalLogData  Extra columns to merge into the log record
     * @param  array{
     *     id3_metadata?: array{
     *         title: string|null,
     *         preacher: string|null,
     *         series: string|null,
     *         reference: string|null
     *     }
     * }|null  $preExtractedMetadata  When non-null, replaces standard identity extraction
     * @param  SermonService|null  $serviceOverride  Operator-selected service; when set, overrides automatic detection
     * @return MediaProcessingLog The newly created processing log
     *
     * @throws UniqueConstraintViolationException If a duplicate processing run is initiated concurrently.
     */
    public function initiateProcessing(
        UploadedFile $file,
        MediaType $processingType,
        ?string $clientFileDate = null,
        array $additionalLogData = [],
        ?array $preExtractedMetadata = null,
        ?SermonService $serviceOverride = null,
    ): MediaProcessingLog {
        $processingId = Str::uuid()->toString();

        $extractedIdentity = [];

        if ($preExtractedMetadata !== null) {
            $baseMetadata = $preExtractedMetadata;

            if ($serviceOverride instanceof SermonService) {
                $extractedIdentity['extracted_service'] = $serviceOverride;
                $baseMetadata['service_extraction_method'] = 'manual_override';
            }

            Log::info('Initiating media processing with pre-extracted metadata', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'processing_type' => $processingType->value,
                'original_filename' => $file->getClientOriginalName(),
                'service_override' => $serviceOverride?->value,
            ]));
        } else {
            $extractedDateTime = $this->metadataService->extractDateFromVideo($file, $clientFileDate);
            $extractedService = $serviceOverride ?? $this->determineService($extractedDateTime, $file->getClientOriginalName());

            Log::info('Extracted metadata from media file', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'processing_type' => $processingType->value,
                'original_filename' => $file->getClientOriginalName(),
                'extracted_date' => $extractedDateTime->toDateString(),
                'extracted_datetime' => $extractedDateTime->toDateTimeString(),
                'extracted_service' => $extractedService->value,
                'service_override' => $serviceOverride?->value,
            ]));

            $extractedIdentity = [
                'extracted_date' => $extractedDateTime->toDateString(),
                'extracted_service' => $extractedService,
            ];

            $baseMetadata = [
                'date_extraction_method' => 'video_metadata_or_filename',
                'service_extraction_method' => $serviceOverride instanceof SermonService ? 'manual_override' : 'datetime_timestamp',
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
