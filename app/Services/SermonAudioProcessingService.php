<?php

namespace App\Services;

use App\Data\SermonMetadata;
use App\Models\SermonProcessingLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SermonAudioProcessingService
{
    /**
     * Process a sermon audio file through the complete automation pipeline
     */
    public function processSermon(UploadedFile $file): ProcessingResult
    {
        $storedFilePath = null;

        try {
            Log::info('Starting sermon processing', [
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]);

            // Generate unique processing ID
            $processingId = $this->generateProcessingId();

            // Validate the uploaded file
            $this->validateAudioFile($file);

            // Extract metadata from the file
            $metadata = SermonMetadata::fromUploadedFile($file);

            // Store the audio file securely
            $storedFilePath = $this->storeAudioFile($file, $metadata);

            // Process synchronously using the same method as livestream processing
            $audioMetadata = [
                'source_type' => 'audio_upload',
                'original_filename' => $file->getClientOriginalName(),
            ];

            $result = $this->processSermonAudio($file, $audioMetadata);

            Log::info('Sermon processing completed successfully', [
                'processing_id' => $result['processing_id'],
                'sermon_id' => $result['sermon_id'],
            ]);

            return ProcessingResult::success(
                processingId: $result['processing_id'],
                message: $result['message'],
                statusUrl: route('api.sermons.processing.status', ['processingId' => $result['processing_id']])
            );
        } catch (\Exception $e) {
            Log::error('Failed to initiate sermon processing', [
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any partial files if they exist
            // Note: $storedFilePath would only be set if file storage succeeded

            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: 'Failed to initiate sermon processing: '.$e->getMessage(),
                errorCode: 'PROCESSING_INITIATION_FAILED'
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
            $absolutePath = Storage::disk(config('sermon-processing.storage.disk', 'public'))->path($storedFilePath);

            Log::info('Audio file stored, dispatching processing jobs', [
                'processing_id' => $processingId,
                'stored_path' => $storedFilePath,
                'absolute_path' => $absolutePath,
            ]);

            // Create pipeline and dispatch jobs
            $pipelineBuilder = app(ProcessingPipelineBuilder::class);
            $jobs = $pipelineBuilder->buildAudioPipeline($processingLog);

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
                'status_url' => route('api.sermons.processing.status', ['processingId' => $processingId]),
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
                Storage::disk(config('sermon-processing.storage.disk', 'public'))->delete($storedFilePath);
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
        $maxSize = config('sermon-processing.processing.max_file_size', 100 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            $maxSizeMB = round($maxSize / (1024 * 1024));
            throw new \InvalidArgumentException("File size exceeds maximum limit of {$maxSizeMB}MB");
        }

        // Check MIME type
        $allowedMimeTypes = config('sermon-processing.processing.allowed_mime_types', [
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/x-wav',
            'audio/mp4',
            'audio/m4a',
        ]);

        if (! in_array($file->getMimeType(), $allowedMimeTypes)) {
            throw new \InvalidArgumentException('Invalid file type. Only audio files are allowed.');
        }

        // Check file extension
        $allowedExtensions = config('sermon-processing.processing.allowed_extensions', ['mp3', 'wav', 'm4a', 'mp4']);
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
        $disk = config('sermon-processing.storage.disk', 'public');
        $basePath = config('sermon-processing.storage.audio_path', 'sermons');

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
    ): SermonProcessingLog {
        $logData = [
            'processing_id' => $processingId,
            'original_filename' => $originalFilename,
            'status' => \App\Enums\ProcessingStatus::PENDING,
            'current_step' => 'initiated_from_livestream',
        ];

        // For now, we'll store livestream context in the current_step field
        // In the future, a processing_metadata JSON field could be added to the migration
        if (! empty($livestreamMetadata['livestream_processing_id'])) {
            $logData['current_step'] = 'initiated_from_livestream:'.$livestreamMetadata['livestream_processing_id'];
        }

        return SermonProcessingLog::create($logData);
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
    private function dispatchProcessingJobs(array $jobs, SermonProcessingLog $processingLog, array $livestreamMetadata): void
    {
        Log::info('Dispatching sermon processing jobs', [
            'processing_id' => $processingLog->processing_id,
            'jobs_count' => count($jobs),
            'source_type' => $livestreamMetadata['source_type'] ?? 'unknown',
        ]);

        // For livestream audio, use a different queue to avoid conflicts
        $queueName = $this->isLivestreamAudio($livestreamMetadata) ? 'livestream-audio' : 'default';

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
}
