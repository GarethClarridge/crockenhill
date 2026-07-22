<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Actions\GetMediaProcessingStatus;
use App\Contracts\ProvidesSafeMessage;
use App\Data\ProcessingResult;
use App\Data\SermonMetadata;
use App\Data\StandardProcessingResponse;
use App\Data\VideoProcessingOptions;
use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Exceptions\InvalidFileException;
use App\Models\MediaProcessingLog;
use App\Services\Sermon\LivestreamSegmentationService;
use App\Traits\SanitizesLogData;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UnifiedMediaProcessor
{
    use SanitizesLogData;

    /**
     * Orchestrates the complete media processing lifecycle.
     *
     * Serves as the central coordination layer between controllers,
     * initiators, validators, and the background job orchestrator.
     */
    public function __construct(
        private readonly ProcessingInitiator $processingInitiator,
        private readonly MetadataExtractionService $metadataService,
        private readonly MediaValidationService $mediaValidation,
        private readonly ProcessingRunOrchestrator $processingRunOrchestrator,
        private readonly GetMediaProcessingStatus $getMediaProcessingStatus,
    ) {}

    /**
     * Entry point for all media uploads (audio, video, and livestream).
     *
     * Handles deduplication, validation, and dispatches the file to the
     * appropriate processing pipeline based on its MediaType.
     *
     * @param  string  $type  The media type string (audio, video, livestream)
     * @param  UploadedFile  $file  The uploaded media file
     * @param  string|null  $clientFileDate  Optional date provided by the client
     * @param  array{auto_trim?: bool, video_processing_mode?: string}  $options  Processing configuration
     * @param  SermonService|null  $serviceOverride  Operator-selected service; when set, overrides automatic detection
     * @param  string|null  $serviceDateOverride  Server-derived service date; when set, overrides inferred recording dates
     * @return ProcessingResult The result of the initiation attempt
     *
     * @throws UniqueConstraintViolationException If a duplicate race occurs
     * @throws InvalidFileException If the file fails initial validation
     * @throws BindingResolutionException If the livestream service cannot be resolved
     * @throws \Exception For underlying service or storage failures
     */
    public function process(
        string $type,
        UploadedFile $file,
        ?string $clientFileDate = null,
        array $options = [],
        ?SermonService $serviceOverride = null,
        ?string $serviceDateOverride = null,
    ): ProcessingResult {
        Log::info('Unified media processing started', $this->sanitizeArrayForLog([
            'type' => $type,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'client_file_date' => $clientFileDate,
        ]));

        $mediaType = MediaType::tryFrom($type);

        if ($mediaType === null) {
            return ProcessingResult::failure(
                processingId: 'invalid-'.Str::uuid(),
                message: "Unsupported media type: {$type}",
                errorCode: 'UNSUPPORTED_TYPE'
            );
        }

        if (
            $mediaType === MediaType::Video
            && VideoProcessingOptions::requestsAutoTrim($options)
            && ! (bool) config('media-processing.video_auto_trim.enabled', true)
        ) {
            return ProcessingResult::failure(
                processingId: 'disabled-'.Str::uuid(),
                message: 'Auto-trim video uploads are currently disabled.',
                errorCode: 'AUTO_TRIM_DISABLED'
            );
        }

        $fileHash = $this->computeFileHash($file);
        $videoMode = VideoProcessingOptions::resolveMode($options);
        $ownerUserId = $this->authenticatedOwnerUserId();
        $dedupKey = $fileHash !== null ? $this->buildDedupKey($fileHash, $mediaType, $videoMode, $ownerUserId) : null;

        $duplicate = $this->findActiveDuplicate($dedupKey);

        if ($duplicate !== null) {
            return $duplicate;
        }

        try {
            if ($serviceDateOverride === null) {
                return match ($mediaType) {
                    MediaType::Audio => $this->processAudio($file, $clientFileDate, $fileHash, $dedupKey, $serviceOverride),
                    MediaType::Video => $this->processDirectVideo($file, $clientFileDate, $fileHash, $options, $dedupKey, $serviceOverride),
                    MediaType::Livestream => $this->livestreamService()->startProcessing($file, $clientFileDate, $fileHash, $dedupKey, $serviceOverride),
                };
            }

            return match ($mediaType) {
                MediaType::Audio => $this->processAudio($file, $clientFileDate, $fileHash, $dedupKey, $serviceOverride, $serviceDateOverride),
                MediaType::Video => $this->processDirectVideo($file, $clientFileDate, $fileHash, $options, $dedupKey, $serviceOverride, $serviceDateOverride),
                MediaType::Livestream => $this->livestreamService()->startProcessing($file, $clientFileDate, $fileHash, $dedupKey, $serviceOverride, $serviceDateOverride),
            };
        } catch (UniqueConstraintViolationException) {
            return $this->reuseRacedDuplicate($dedupKey);
        }
    }

    /**
     * Retrieve the current processing status for a given ID.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return StandardProcessingResponse The current state snapshot
     */
    public function getStatus(string $processingId): StandardProcessingResponse
    {
        return $this->getMediaProcessingStatus->get($processingId);
    }

    /**
     * Retrieve status along with recent processing logs.
     *
     * @param  string  $processingId  The unique processing identifier
     * @param  bool  $includeLogs  Whether to include the log collection
     * @param  int  $logLimit  Maximum number of recent log entries to return
     * @return StandardProcessingResponse The status with attached logs
     */
    public function getStatusWithLogs(string $processingId, bool $includeLogs = false, int $logLimit = 20): StandardProcessingResponse
    {
        if (! $includeLogs) {
            return $this->getStatus($processingId);
        }

        return $this->getMediaProcessingStatus->getWithLogs($processingId, $logLimit);
    }

    /**
     * Attempt to cancel an active processing run.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return array{success: bool, message: string} Whether cancellation was successful
     */
    public function cancel(string $processingId): array
    {
        $log = $this->getMediaProcessingStatus->find($processingId);

        if (! $log) {
            return ['success' => false, 'message' => 'Processing ID not found'];
        }

        $result = $this->processingRunOrchestrator->cancel($log);

        return [
            'success' => $result,
            'message' => $result ? 'Processing cancelled successfully' : 'Failed to cancel processing',
        ];
    }

    /**
     * Attempt to restart a failed or cancelled processing run.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return ProcessingResult The result of the retry initiation
     *
     * @throws \Throwable
     */
    public function retry(string $processingId): ProcessingResult
    {
        $log = $this->getMediaProcessingStatus->find($processingId);

        if (! $log) {
            return ProcessingResult::failure(
                processingId: $processingId,
                message: 'Processing ID not found for retry',
                errorCode: 'NOT_FOUND'
            );
        }

        return $this->processingRunOrchestrator->retry($log);
    }

    /**
     * Verify if the processor can manage a given processing ID.
     *
     * Useful for pre-flight checks before attempting status or cancel actions.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return bool True if the record exists and is accessible
     */
    public function canHandle(string $processingId): bool
    {
        return $this->getMediaProcessingStatus->canHandle($processingId);
    }

    /**
     * Resolve the livestream service lazily. Constructor-injecting it would
     * force every UnifiedMediaProcessor resolution (including status checks
     * that never touch livestream) to eagerly build the livestream service
     * graph — which is wasteful and breaks targeted-binding tests that
     * assert livestream is not touched on read paths.
     *
     * @throws BindingResolutionException
     */
    private function livestreamService(): LivestreamSegmentationService
    {
        return app(LivestreamSegmentationService::class);
    }

    /**
     * Process an audio file through the complete automation pipeline.
     * Uses ProcessingInitiator for shared log-creation boundary (same as video/livestream).
     *
     * @throws UniqueConstraintViolationException If a duplicate race occurs
     * @throws InvalidFileException If the file fails initial validation
     * @throws \Exception For underlying service or storage failures
     */
    private function processAudio(UploadedFile $file, ?string $clientFileDate, ?string $fileHash, ?string $dedupKey, ?SermonService $serviceOverride = null, ?string $serviceDateOverride = null): ProcessingResult
    {
        try {
            Log::info('Starting audio processing', $this->sanitizeArrayForLog([
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'client_file_date' => $clientFileDate,
            ]));

            $this->mediaValidation->validateUploadedFile(MediaType::Audio, $file);

            $metadata = SermonMetadata::fromUploadedFile($file);
            $storedFilePath = $this->storeAudioFile($file, $metadata);

            $id3Metadata = $this->metadataService->extractId3Metadata($file);

            $additionalLogData = [
                'source_file_path' => $storedFilePath,
                'file_hash' => $fileHash,
                'dedup_key' => $dedupKey,
            ];
            $preExtractedMetadata = ['id3_metadata' => $id3Metadata];

            $processingLog = $serviceDateOverride === null
                ? $this->processingInitiator->initiateProcessing(
                    $file,
                    MediaType::Audio,
                    $clientFileDate,
                    $additionalLogData,
                    preExtractedMetadata: $preExtractedMetadata,
                    serviceOverride: $serviceOverride,
                )
                : $this->processingInitiator->initiateProcessing(
                    $file,
                    MediaType::Audio,
                    $clientFileDate,
                    $additionalLogData,
                    preExtractedMetadata: $preExtractedMetadata,
                    serviceOverride: $serviceOverride,
                    serviceDateOverride: $serviceDateOverride,
                );

            Log::info('Audio file stored, processing log created', $this->sanitizeArrayForLog([
                'processing_id' => $processingLog->processing_id,
                'stored_path' => $storedFilePath,
                'id3_metadata' => json_encode($id3Metadata) ?: '',
            ]));

            $this->processingRunOrchestrator->start($processingLog);

            Log::info('Audio processing jobs dispatched', $this->sanitizeArrayForLog([
                'processing_id' => $processingLog->processing_id,
            ]));

            return ProcessingResult::success(
                processingId: $processingLog->processing_id,
                message: 'Audio processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
            );

        } catch (UniqueConstraintViolationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to initiate audio processing', $this->sanitizeArrayForLog([
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: 'Failed to initiate audio processing: '.$this->safeInitiationMessage($e, 'audio'),
                errorCode: 'AUDIO_PROCESSING_INITIATION_FAILED'
            );
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function storeAudioFile(UploadedFile $file, SermonMetadata $metadata): string
    {
        $disk = config('media-processing.storage.sermon_disk', 'public');
        $basePath = config('media-processing.storage.paths.audio', 'sermons');

        $directory = $basePath.'/'.$metadata->date->format('Y/m');

        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().'.'.$extension;

        $path = $file->storeAs($directory, $filename, $disk);

        if (! $path) {
            throw new \RuntimeException('Failed to store audio file');
        }

        return $path;
    }

    private function computeFileHash(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            return null;
        }

        $hash = hash_file('sha256', $realPath);

        return $hash !== false ? $hash : null;
    }

    private function buildDedupKey(string $fileHash, MediaType $mediaType, string $videoMode, ?int $ownerUserId): string
    {
        return MediaProcessingLog::makeDedupKey($fileHash, $mediaType, $videoMode, $ownerUserId);
    }

    private function authenticatedOwnerUserId(): ?int
    {
        $userId = Auth::id();

        if (is_int($userId)) {
            return $userId;
        }

        if (is_string($userId) && ctype_digit($userId)) {
            return (int) $userId;
        }

        return null;
    }

    private function findActiveDuplicate(?string $dedupKey): ?ProcessingResult
    {
        if ($dedupKey === null) {
            return null;
        }

        $existingLog = MediaProcessingLog::query()
            ->where('dedup_key', $dedupKey)
            ->first();

        if ($existingLog === null) {
            return null;
        }

        Log::info('Duplicate upload detected, reusing existing processing run', $this->sanitizeArrayForLog([
            'dedup_key' => $dedupKey,
            'existing_processing_id' => $existingLog->processing_id,
            'processing_type' => $existingLog->processing_type->value,
        ]));

        return ProcessingResult::success(
            processingId: $existingLog->processing_id,
            message: 'Duplicate upload detected. Returning existing processing run.',
            statusUrl: route('api.media.processing.status', ['processingId' => $existingLog->processing_id])
        );
    }

    private function reuseRacedDuplicate(?string $dedupKey): ProcessingResult
    {
        if ($dedupKey !== null) {
            $racedLog = MediaProcessingLog::query()->where('dedup_key', $dedupKey)->first();

            if ($racedLog !== null) {
                Log::info('Concurrent duplicate resolved via constraint violation, reusing raced run', $this->sanitizeArrayForLog([
                    'dedup_key' => $dedupKey,
                    'existing_processing_id' => $racedLog->processing_id,
                ]));

                return ProcessingResult::success(
                    processingId: $racedLog->processing_id,
                    message: 'Duplicate upload detected. Returning existing processing run.',
                    statusUrl: route('api.media.processing.status', ['processingId' => $racedLog->processing_id])
                );
            }
        }

        return ProcessingResult::failure(
            processingId: 'race-'.Str::uuid(),
            message: 'An internal error occurred while initiating processing.',
            errorCode: 'PROCESSING_INITIATION_FAILED'
        );
    }

    /**
     * Process video by extracting audio and joining existing sermon processing.
     * Uses ProcessingInitiator for shared metadata extraction and log creation.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws UniqueConstraintViolationException If a duplicate race occurs
     * @throws InvalidFileException If the file fails initial validation
     * @throws \Exception For underlying service or storage failures
     */
    private function processDirectVideo(
        UploadedFile $file,
        ?string $clientFileDate,
        ?string $fileHash,
        array $options = [],
        ?string $dedupKey = null,
        ?SermonService $serviceOverride = null,
        ?string $serviceDateOverride = null,
    ): ProcessingResult {
        try {
            // Store video file temporarily before processing (preserves file timestamps for metadata extraction)
            $tempPath = $file->store('temp/video-processing');

            if ($tempPath === false) {
                throw new \RuntimeException('Failed to store video file temporarily');
            }

            $videoProcessingMode = VideoProcessingOptions::resolveMode($options);

            $additionalLogData = [
                'source_file_path' => $tempPath,
                'file_hash' => $fileHash,
                'dedup_key' => $dedupKey,
                'processing_metadata' => [
                    'video_processing_mode' => $videoProcessingMode,
                    'trim_requested' => $videoProcessingMode === MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                ],
            ];

            $processingLog = $serviceDateOverride === null
                ? $this->processingInitiator->initiateProcessing(
                    $file,
                    MediaType::Video,
                    $clientFileDate,
                    $additionalLogData,
                    serviceOverride: $serviceOverride,
                )
                : $this->processingInitiator->initiateProcessing(
                    $file,
                    MediaType::Video,
                    $clientFileDate,
                    $additionalLogData,
                    serviceOverride: $serviceOverride,
                    serviceDateOverride: $serviceDateOverride,
                );

            $this->processingRunOrchestrator->start($processingLog);

            return ProcessingResult::success(
                processingId: $processingLog->processing_id,
                message: 'Video processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
            );

        } catch (UniqueConstraintViolationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to initiate video processing', $this->sanitizeArrayForLog([
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: 'Failed to initiate video processing: '.$this->safeInitiationMessage($e, 'video'),
                errorCode: 'VIDEO_PROCESSING_FAILED'
            );
        }
    }

    private function safeInitiationMessage(\Throwable $exception, string $type): string
    {
        if ($exception instanceof ProvidesSafeMessage) {
            return $exception->getSafeMessage();
        }

        return "An internal error occurred while initiating {$type} processing.";
    }
}
