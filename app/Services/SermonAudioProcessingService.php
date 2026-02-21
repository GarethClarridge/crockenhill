<?php

namespace App\Services;

use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SermonAudioProcessingService
{
    public function __construct(
        private readonly MetadataExtractionService $metadataService,
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly MediaValidationService $mediaValidation
    ) {}

    /**
     * Process a sermon audio file through the complete automation pipeline
     * Uses ProcessingPipelineBuilder for consistent job chain pattern (same as video processing)
     */
    public function processSermon(UploadedFile $file, ?string $clientFileDate = null): ProcessingResult
    {
        try {
            Log::info('Starting audio processing', [
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'client_file_date' => $clientFileDate,
            ]);

            // Generate unique processing ID
            $processingId = $this->generateProcessingId();

            // Validate the uploaded file
            $this->validateAudioFile($file);

            // Extract metadata and store the audio file
            $metadata = SermonMetadata::fromUploadedFile($file);
            $storedFilePath = $this->storeAudioFile($file, $metadata);

            // Extract ID3 metadata tags (title, artist/preacher, album/series, reference)
            $id3Metadata = $this->metadataService->extractId3Metadata($file);

            Log::info('Audio file stored, creating processing log', [
                'processing_id' => $processingId,
                'stored_path' => $storedFilePath,
                'id3_metadata' => $id3Metadata,
            ]);

            // Create media processing log with ID3 metadata in processing_metadata
            $processingLog = MediaProcessingLog::create([
                'processing_id' => $processingId,
                'processing_type' => 'audio',
                'original_filename' => $file->getClientOriginalName(),
                'owner_user_id' => Auth::id(),
                'source_file_path' => $storedFilePath,
                'status' => ProcessingStatus::PENDING,
                'current_step' => 'audio_processing_initiated',
                'processing_metadata' => [
                    'id3_metadata' => $id3Metadata,
                ],
            ]);

            // Build and dispatch job chain using ProcessingPipelineBuilder (same as video processing)
            $jobs = $this->pipelineBuilder->buildAudioPipeline($processingLog);

            Log::info('Audio processing pipeline created', [
                'processing_id' => $processingId,
                'jobs_count' => count($jobs),
                'job_classes' => array_map(fn ($job) => get_class($job), $jobs),
            ]);

            Bus::chain($jobs)
                ->catch(function (\Throwable $e) use ($processingLog) {
                    $processingLog->update([
                        'status' => ProcessingStatus::FAILED,
                        'error_message' => 'Audio processing failed: '.$e->getMessage(),
                    ]);
                })
                ->onQueue($this->audioQueue())
                ->dispatch();

            Log::info('Audio processing jobs dispatched', [
                'processing_id' => $processingId,
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Audio processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingId])
            );

        } catch (\Exception $e) {
            Log::error('Failed to initiate audio processing', [
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: 'Failed to initiate audio processing: '.$e->getMessage(),
                errorCode: 'AUDIO_PROCESSING_INITIATION_FAILED'
            );
        }
    }

    /**
     * Process a sermon audio file from livestream with additional metadata
     */
    public function processSermonAudio(UploadedFile $file, array $livestreamMetadata = []): array
    {
        /** @var string|null $storedFilePath */
        $storedFilePath = null;

        try {
            Log::info('Starting livestream sermon processing', [
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'source_type' => $livestreamMetadata['source_type'] ?? 'unknown',
            ]);

            // Generate unique processing ID
            $processingId = $this->generateProcessingId();

            // Validate the uploaded file
            $this->validateAudioFile($file);

            // Store the audio file with metadata context
            $metadata = SermonMetadata::fromUploadedFile($file);
            $storedFilePath = $this->storeAudioFile($file, $metadata);

            // Create processing log with livestream context
            $processingLog = $this->createProcessingLogWithLivestreamContext(
                $processingId,
                $file->getClientOriginalName(),
                $livestreamMetadata
            );

            // Determine absolute path for the stored file for job processing
            $absolutePath = Storage::disk(config('media-processing.storage.sermon_disk', 'public'))->path($storedFilePath);

            Log::info('Audio file stored, dispatching processing jobs', [
                'processing_id' => $processingId,
                'stored_path' => $storedFilePath,
                'absolute_path' => $absolutePath,
            ]);

            // Create pipeline and dispatch jobs
            $jobs = $this->pipelineBuilder->buildAudioPipeline($processingLog);

            Log::info('Sermon processing pipeline created', [
                'processing_id' => $processingId,
                'jobs_count' => count($jobs),
                'job_classes' => array_map(fn ($job) => get_class($job), $jobs),
            ]);

            $this->dispatchProcessingJobs($jobs, $processingLog, $livestreamMetadata);

            // Store the transcript path for later use
            $transcriptPath = $this->storeTranscript($processingLog->id, '');

            return [
                'success' => true,
                'processing_id' => $processingId,
                'sermon_id' => null, // Will be set by CreateSermonRecord job
                'message' => 'Sermon processing initiated successfully',
                'status_url' => route('api.media.processing.status', ['processingId' => $processingId]),
                'transcript_path' => $transcriptPath,
                'stored_file_path' => $storedFilePath,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to initiate livestream sermon processing', [
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any partial files if they were created
            if ($storedFilePath !== null) {
                Storage::disk(config('media-processing.storage.sermon_disk', 'public'))->delete($storedFilePath);
            }

            return [
                'success' => false,
                'processing_id' => 'failed-'.Str::uuid(),
                'sermon_id' => null,
                'message' => 'Failed to initiate sermon processing: '.$e->getMessage(),
                'error_code' => 'PROCESSING_INITIATION_FAILED',
            ];
        }
    }

    /**
     * Generate a unique processing ID
     */
    private function generateProcessingId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Validate the uploaded audio file
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        // Check file size
        $maxSize = $this->mediaValidation->maxFileSizeBytes('audio');
        if ($file->getSize() > $maxSize) {
            $maxSizeMB = round($maxSize / (1024 * 1024));
            throw new \InvalidArgumentException("File size exceeds maximum limit of {$maxSizeMB}MB");
        }

        // Check MIME type
        $allowedMimeTypes = $this->mediaValidation->allowedMimes('audio');

        if (! in_array($file->getMimeType(), $allowedMimeTypes)) {
            throw new \InvalidArgumentException('Invalid file type. Only audio files are allowed.');
        }

        // Check file extension
        $allowedExtensions = $this->mediaValidation->allowedExtensions('audio');
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions)) {
            throw new \InvalidArgumentException('Invalid file extension. Allowed: '.implode(', ', $allowedExtensions));
        }

        // Basic file integrity check
        if (! $file->isValid()) {
            throw new \InvalidArgumentException('Uploaded file is corrupted or invalid');
        }
    }

    /**
     * Store the audio file securely
     */
    public function storeAudioFile(UploadedFile $file, SermonMetadata $metadata): string
    {
        // Get storage configuration
        $disk = config('media-processing.storage.sermon_disk', 'public');
        $basePath = config('media-processing.storage.paths.audio', 'sermons');

        // Create directory structure: sermons/YYYY/MM/
        $directory = $basePath.'/'.$metadata->date->format('Y/m');

        // Generate unique filename while preserving extension
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().'.'.$extension;

        // Store the file
        $path = $file->storeAs($directory, $filename, $disk);

        if (! $path) {
            throw new \RuntimeException('Failed to store audio file');
        }

        return $path;
    }

    /**
     * Create initial processing log entry with livestream context
     */
    private function createProcessingLogWithLivestreamContext(
        string $processingId,
        string $originalFilename,
        array $livestreamMetadata
    ): MediaProcessingLog {
        $logData = [
            'processing_id' => $processingId,
            'processing_type' => 'audio',
            'original_filename' => $originalFilename,
            'owner_user_id' => Auth::id(),
            'status' => \App\Enums\ProcessingStatus::PENDING,
            'current_step' => 'initiated_from_livestream',
        ];

        // Store livestream context in processing_metadata JSON field
        if (! empty($livestreamMetadata['livestream_processing_id'])) {
            $logData['processing_metadata'] = $livestreamMetadata;
            $logData['current_step'] = 'initiated_from_livestream:'.$livestreamMetadata['livestream_processing_id'];
        }

        return MediaProcessingLog::create($logData);
    }

    /**
     * Store the transcript path for later use
     */
    private function storeTranscript(int $sermonId, string $transcript): string
    {
        $transcriptPath = 'transcripts/sermon_'.$sermonId.'_'.time().'.txt';
        Storage::disk('local')->put($transcriptPath, $transcript);

        return $transcriptPath;
    }

    /**
     * Dispatch processing jobs for the sermon
     */
    private function dispatchProcessingJobs(array $jobs, MediaProcessingLog $processingLog, array $livestreamMetadata): void
    {
        Log::info('Dispatching sermon processing jobs', [
            'processing_id' => $processingLog->processing_id,
            'jobs_count' => count($jobs),
            'source_type' => $livestreamMetadata['source_type'] ?? 'unknown',
        ]);

        $queueName = $this->isLivestreamAudio($livestreamMetadata)
            ? $this->livestreamAudioQueue()
            : $this->defaultQueue();

        \Illuminate\Support\Facades\Bus::chain($jobs)
            ->catch(function (\Throwable $e) use ($processingLog) {
                Log::error('Sermon processing job chain failed', [
                    'processing_id' => $processingLog->processing_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Update processing log with error
                $processingLog->update([
                    'status' => \App\Enums\ProcessingStatus::FAILED,
                    'error_message' => 'Processing chain failed: '.$e->getMessage(),
                    'current_step' => 'job_chain_failed',
                ]);
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    /**
     * Check if this is livestream audio processing
     */
    private function isLivestreamAudio(array $metadata): bool
    {
        return isset($metadata['source_type']) &&
               in_array($metadata['source_type'], ['livestream', 'video_upload']);
    }

    private function audioQueue(): string
    {
        return (string) config('media-processing.queues.audio', config('media-processing.types.audio.queue', 'audio-processing'));
    }

    private function livestreamAudioQueue(): string
    {
        return (string) config('media-processing.queues.livestream_audio', $this->audioQueue());
    }

    private function defaultQueue(): string
    {
        return (string) config('media-processing.queues.default', config('media-processing.processing.queue', 'default'));
    }
}
