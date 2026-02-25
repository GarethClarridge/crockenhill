<?php

namespace App\Services;

use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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
        $this->mediaValidation->validateUploadedFile('audio', $file);
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

    private function audioQueue(): string
    {
        return (string) config('media-processing.queues.audio', 'audio-processing');
    }
}
