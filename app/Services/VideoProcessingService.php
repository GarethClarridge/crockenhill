<?php

namespace App\Services;

use App\Data\LivestreamProcessingResult;
use App\Data\LivestreamProcessingStatus;
use App\Jobs\AnalyzeSegments;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\GenerateThumbnail;
use App\Jobs\SubmitToProcessing;
use App\Mail\LivestreamProcessingFailed;
use App\Models\LivestreamProcessingLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoProcessingService
{
    private VideoStorageService $storageService;

    private VideoSegmentationService $segmentationService;

    public function __construct(
        VideoStorageService $storageService,
        VideoSegmentationService $segmentationService
    ) {
        $this->storageService = $storageService;
        $this->segmentationService = $segmentationService;
    }

    public function processLivestream(UploadedFile $videoFile): ProcessingResult
    {
        return $this->processWithSegmentation($videoFile);
    }

    /**
     * Process video with segmentation (for livestream videos)
     */
    public function processWithSegmentation(UploadedFile $videoFile): ProcessingResult
    {
        return $this->startProcessing($videoFile);
    }

    /**
     * Process video directly without segmentation (for sermon videos)
     */
    public function processDirectly(UploadedFile $videoFile): ProcessingResult
    {
        try {
            Log::info('Starting direct sermon video processing', [
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize(),
                'mime_type' => $videoFile->getMimeType(),
            ]);

            // 1. Store video using existing VideoStorageService
            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);

            // 2. Get video metadata
            $metadata = $this->segmentationService->getVideoMetadata($uploadResult['full_path']);

            // 3. Extract full audio track (optimized for transcription)
            $audioPath = $this->extractFullAudioFromVideo(
                $uploadResult['full_path'],
                $metadata['duration']
            );

            // 4. Create UploadedFile wrapper for extracted audio
            $audioFile = new UploadedFile(
                $audioPath,
                pathinfo($uploadResult['original_filename'], PATHINFO_FILENAME).'.mp3',
                'audio/mpeg',
                null,
                true // Mark as test file to skip is_uploaded_file check
            );

            // 5. Process through existing synchronous sermon pipeline
            $sermonProcessor = app(SermonProcessingService::class);
            $videoMetadata = [
                'source_type' => 'video_upload',
                'original_filename' => $uploadResult['original_filename'],
                'segment_start_time' => 0,
                'segment_end_time' => $metadata['duration'],
                'video_file_path' => $this->moveVideoToPermanentStorage($uploadResult),
            ];

            $result = $sermonProcessor->processSermonAudio($audioFile, $videoMetadata);

            // Update sermon record with video information
            if ($result['success'] && $result['sermon_id']) {
                $this->updateSermonWithVideoMetadata($result['sermon_id'], $videoMetadata);

                // Dispatch thumbnail generation job after sermon creation
                // This happens asynchronously and never blocks the main processing pipeline
                $permanentVideoPath = $videoMetadata['video_file_path'];
                $this->dispatchThumbnailGeneration($result['sermon_id'], $permanentVideoPath);
            }

            Log::info('Direct sermon video processing completed successfully', [
                'processing_id' => $result['processing_id'],
                'sermon_id' => $result['sermon_id'],
                'video_duration' => $metadata['duration'],
                'audio_path' => $audioPath,
            ]);

            return ProcessingResult::success(
                processingId: $result['processing_id'],
                message: $result['message'],
                statusUrl: route('api.sermons.processing.status', ['processingId' => $result['processing_id']])
            );

        } catch (\Exception $e) {
            Log::error('Failed to initiate direct sermon video processing', [
                'original_filename' => $videoFile->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Cleanup temporary files
            $this->cleanupTemporaryFiles($uploadResult ?? null, $audioPath ?? null);

            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: 'Failed to initiate sermon video processing: '.$e->getMessage(),
                errorCode: 'VIDEO_PROCESSING_INITIATION_FAILED'
            );
        }
    }

    public function startProcessing(UploadedFile $videoFile): ProcessingResult
    {
        try {
            $processingId = Str::uuid()->toString();

            Log::info('Starting livestream processing', [
                'processing_id' => $processingId,
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize(),
            ]);

            if (! $this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new \Exception('Insufficient storage space for processing');
            }

            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);

            if (! $this->segmentationService->validateVideoFile($uploadResult['full_path'])) {
                throw new \Exception('Invalid video file format');
            }

            $metadata = $this->segmentationService->getVideoMetadata($uploadResult['full_path']);

            $processingLog = LivestreamProcessingLog::create([
                'processing_id' => $processingId,
                'status' => 'pending',
                'original_filename' => $uploadResult['original_filename'],
                'original_file_path' => $uploadResult['temp_path'],
                'file_size' => $uploadResult['file_size'],
                'file_format' => pathinfo($uploadResult['original_filename'], PATHINFO_EXTENSION),
                'duration' => $metadata['duration'],
                'processing_metadata' => [
                    'upload_time' => now()->toISOString(),
                    'format_details' => $metadata,
                    'mime_type' => $uploadResult['mime_type'],
                ],
            ]);

            $this->dispatchProcessingJobs($processingLog);

            Log::info('Livestream processing initiated', [
                'processing_id' => $processingId,
                'log_id' => $processingLog->id,
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Livestream processing initiated successfully'
            );

        } catch (\Exception $e) {
            Log::error('Failed to start livestream processing', [
                'error' => $e->getMessage(),
                'original_filename' => $videoFile->getClientOriginalName(),
            ]);

            throw $e;
        }
    }

    public function getProcessingStatus(string $processingId): LivestreamProcessingStatus
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        $currentStep = $this->getCurrentStep($processingLog->status);
        $progressPercentage = $this->getProgressPercentage($processingLog->status);

        return new LivestreamProcessingStatus(
            processingId: $processingId,
            status: $processingLog->status,
            currentStep: $currentStep,
            progressPercentage: $progressPercentage,
            errorMessage: $processingLog->error_message,
            stepDetails: $this->getStepDetails($processingLog),
            processingStats: $this->getProcessingStats($processingLog),
        );
    }

    public function getProcessingResult(string $processingId): LivestreamProcessingResult
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        return $this->buildProcessingResult($processingLog);
    }

    public function retryProcessing(string $processingId): LivestreamProcessingResult
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        if (! $processingLog->isFailed()) {
            throw new \Exception('Only failed processing can be retried');
        }

        Log::info('Retrying livestream processing', [
            'processing_id' => $processingId,
            'previous_status' => $processingLog->status,
        ]);

        $processingLog->update([
            'status' => 'pending',
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $processingLog->segments()->delete();

        $this->dispatchProcessingJobs($processingLog);

        return $this->buildProcessingResult($processingLog->fresh());
    }

    public function cancelProcessing(string $processingId): bool
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if (! $processingLog) {
            throw new \Exception('Processing record not found');
        }

        if ($processingLog->isCompleted()) {
            throw new \Exception('Cannot cancel completed processing');
        }

        Log::info('Cancelling livestream processing', [
            'processing_id' => $processingId,
            'current_status' => $processingLog->status,
        ]);

        $processingLog->markAsFailed('Processing cancelled by user');

        $this->storageService->cleanupTemporaryFiles($processingId);

        return true;
    }

    private function dispatchProcessingJobs(LivestreamProcessingLog $processingLog): void
    {
        $processingId = $processingLog->processing_id;

        // Dispatch job chain for resilient processing as specified in design
        Bus::chain([
            new GenerateRmsLog($processingLog),
            new AnalyzeSegments($processingLog),
            new ExtractSermon($processingLog),
            new SubmitToProcessing($processingLog),
            // Add thumbnail generation job to chain - it will be handled by the SubmitToProcessing job
            // after sermon creation, using the sermon ID and video path from the processing log
            new CleanupTemporaryFiles($processingLog),
        ])->catch(function (\Throwable $e) use ($processingId) {
            $this->handleProcessingFailure($processingId, $e);
        })->onQueue(config('livestream-processing.queue.name', 'default'))
            ->dispatch();
    }

    /**
     * Update the processing status for real-time progress tracking
     */
    public function updateProcessingStatus(string $processingId, string $status): void
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if ($processingLog) {
            $processingLog->update(['status' => $status]);

            Log::info('Processing status updated', [
                'processing_id' => $processingId,
                'status' => $status,
            ]);
        }
    }

    private function handleProcessingFailure(string $processingId, \Throwable $e): void
    {
        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();

        if ($processingLog) {
            $processingLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Clean up temporary files
            $this->storageService->cleanupTemporaryFiles($processingId);
        }

        // Send email notification to administrators
        Mail::to(config('livestream-processing.admin_email'))
            ->send(new LivestreamProcessingFailed($processingId, $e));
    }

    private function buildProcessingResult(LivestreamProcessingLog $processingLog): LivestreamProcessingResult
    {
        $segments = $processingLog->segments->map(function ($segment) {
            return new \App\Data\LivestreamSegment(
                startTime: $segment->start_time,
                endTime: $segment->end_time,
                duration: $segment->duration,
                classification: $segment->classification,
                avgRms: $segment->avg_rms,
                peakRms: $segment->peak_rms,
                isSermonCandidate: $segment->is_sermon_candidate,
                segmentOrder: $segment->segment_order,
                metadata: $segment->metadata,
            );
        })->toArray();

        $segmentsSummary = null;
        if ($processingLog->segments->isNotEmpty()) {
            $segmentsSummary = \App\Models\LivestreamSegment::getSegmentsSummary($processingLog->id);
        }

        return new LivestreamProcessingResult(
            processingId: $processingLog->processing_id,
            status: $processingLog->status,
            originalFilename: $processingLog->original_filename,
            fileSize: $processingLog->file_size,
            fileFormat: $processingLog->file_format,
            duration: $processingLog->duration,
            sermonStartTime: $processingLog->sermon_start_time,
            sermonEndTime: $processingLog->sermon_end_time,
            sermonId: $processingLog->sermon_id,
            errorMessage: $processingLog->error_message,
            processingMetadata: $processingLog->processing_metadata,
            startedAt: $processingLog->started_at?->toISOString(),
            completedAt: $processingLog->completed_at?->toISOString(),
            segments: $segments,
            segmentsSummary: $segmentsSummary,
        );
    }

    private function getCurrentStep(string $status): string
    {
        return match ($status) {
            'pending' => 'queued',
            'rms_generation' => 'rms_generation',
            'processing' => 'rms_generation',
            'segmentation' => 'segment_analysis',
            'segmenting' => 'segment_analysis',
            'segmentation_complete' => 'segment_analysis',
            'extraction' => 'sermon_extraction',
            'extraction_complete' => 'sermon_extraction',
            'transcription' => 'sermon_processing',
            'sermon_submitted' => 'sermon_processing',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'unknown',
        };
    }

    private function getProgressPercentage(string $status): int
    {
        return match ($status) {
            'pending' => 0,
            'rms_generation' => 15,
            'processing' => 25,
            'segmentation' => 40,
            'segmenting' => 50,
            'segmentation_complete' => 60,
            'extraction' => 70,
            'extraction_complete' => 75,
            'transcription' => 85,
            'sermon_submitted' => 90,
            'completed' => 100,
            'failed' => 0,
            default => 0,
        };
    }

    private function getStepDetails(LivestreamProcessingLog $processingLog): array
    {
        $details = [
            'processing_id' => $processingLog->processing_id,
            'sermon_processing_id' => $processingLog->sermon_processing_id,
            'file_info' => [
                'filename' => $processingLog->original_filename,
                'size' => $processingLog->file_size,
                'format' => $processingLog->file_format,
                'duration' => $processingLog->duration,
            ],
        ];

        if ($processingLog->segments->isNotEmpty()) {
            $details['segmentation'] = [
                'total_segments' => $processingLog->segments->count(),
                'speech_segments' => $processingLog->speechSegments->count(),
                'song_segments' => $processingLog->songSegments->count(),
                'sermon_candidate_found' => $processingLog->sermonCandidateSegment !== null,
                'segments' => $processingLog->segments->map(function ($segment) {
                    return [
                        'segment_order' => $segment->segment_order,
                        'start_time' => $segment->start_time,
                        'end_time' => $segment->end_time,
                        'classification' => $segment->classification,
                        'is_sermon_candidate' => $segment->is_sermon_candidate,
                    ];
                })->toArray(),
            ];
        }

        if ($processingLog->sermon_start_time && $processingLog->sermon_end_time) {
            $details['sermon_extraction'] = [
                'start_time' => $processingLog->sermon_start_time,
                'end_time' => $processingLog->sermon_end_time,
                'duration' => $processingLog->sermon_end_time - $processingLog->sermon_start_time,
            ];
        }

        // Add sermon video path if processing is completed and has sermon
        if ($processingLog->status === 'completed' && $processingLog->sermon_video_path) {
            $details['sermon_video_path'] = $processingLog->sermon_video_path;
        }

        return $details;
    }

    private function getProcessingStats(LivestreamProcessingLog $processingLog): array
    {
        $stats = [];

        if ($processingLog->started_at) {
            $stats['started_at'] = $processingLog->started_at->toISOString();

            if ($processingLog->completed_at) {
                $stats['completed_at'] = $processingLog->completed_at->toISOString();
                $stats['processing_duration'] = $processingLog->started_at->diffInSeconds($processingLog->completed_at);
            } else {
                $stats['processing_duration'] = $processingLog->started_at->diffInSeconds(now());
            }
        }

        return $stats;
    }

    public function getProcessingSummary(): array
    {
        $total = LivestreamProcessingLog::count();
        $pending = LivestreamProcessingLog::pending()->count();
        $processing = LivestreamProcessingLog::where('status', 'LIKE', '%processing%')
            ->orWhere('status', 'LIKE', '%complete%')
            ->where('status', '!=', 'completed')
            ->count();
        $completed = LivestreamProcessingLog::completed()->count();
        $failed = LivestreamProcessingLog::failed()->count();

        return [
            'total_processing_requests' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Extract full audio track from video optimized for transcription
     */
    private function extractFullAudioFromVideo(string $videoPath, float $duration): string
    {
        $audioConfig = config('livestream-processing.audio_extraction.transcription_optimized');

        $outputPath = storage_path('app/temp/'.Str::uuid().'.mp3');
        $this->ensureDirectoryExists(dirname($outputPath));

        try {
            $ffmpeg = \FFMpeg\FFMpeg::create([
                'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
                'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            ]);

            $video = $ffmpeg->open($videoPath);

            $format = new \FFMpeg\Format\Audio\Mp3;
            $format->setAudioKiloBitrate($audioConfig['bitrate'] ?? 48);
            $format->setAudioChannels($audioConfig['channels'] ?? 1);

            $video->save($format, $outputPath);

            // Check file size and compress if needed
            $fileSize = filesize($outputPath);
            $maxSize = $audioConfig['max_file_size'] ?? (25 * 1024 * 1024);

            if ($fileSize > $maxSize) {
                Log::info('Audio file too large, applying fallback compression', [
                    'original_size' => $fileSize,
                    'max_size' => $maxSize,
                ]);

                $outputPath = $this->compressAudioForTranscription($outputPath);
            }

            Log::info('Audio extracted from full video', [
                'video_path' => $videoPath,
                'audio_path' => $outputPath,
                'duration' => $duration,
                'file_size' => filesize($outputPath),
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Failed to extract audio from video', [
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Compress audio file for transcription service
     */
    private function compressAudioForTranscription(string $inputPath): string
    {
        $fallbackConfig = config('livestream-processing.audio_extraction.fallback_compression');
        $compressedPath = storage_path('app/temp/'.Str::uuid().'_compressed.mp3');

        try {
            $ffmpeg = \FFMpeg\FFMpeg::create([
                'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
                'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            ]);

            $audio = $ffmpeg->open($inputPath);

            $format = new \FFMpeg\Format\Audio\Mp3;
            $format->setAudioKiloBitrate($fallbackConfig['bitrate'] ?? 32);
            $format->setAudioChannels($fallbackConfig['channels'] ?? 1);

            $audio->save($format, $compressedPath);

            // Remove original file
            if (file_exists($inputPath)) {
                unlink($inputPath);
            }

            return $compressedPath;

        } catch (\Exception $e) {
            Log::error('Failed to compress audio file', [
                'input_path' => $inputPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update sermon record with video metadata
     */
    private function updateSermonWithVideoMetadata(string $sermonId, array $videoData): void
    {
        try {
            $sermon = \App\Models\Sermon::find($sermonId);

            if ($sermon) {
                $sermon->update($videoData);

                Log::info('Sermon updated with video metadata', [
                    'sermon_id' => $sermon->id,
                    'video_data' => $videoData,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update sermon with video metadata', [
                'sermon_id' => $sermonId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move video from temporary storage to permanent location
     */
    private function moveVideoToPermanentStorage(array $uploadResult): string
    {
        $tempDisk = config('livestream-processing.temp_disk', 'local');
        $permanentDisk = config('livestream-processing.sermon_disk', 'local');
        $videoPath = config('livestream-processing.storage.video_path', 'sermons/videos');

        $filename = Str::uuid().'_'.basename($uploadResult['original_filename']);
        $permanentPath = $videoPath.'/'.$filename;

        try {
            // Copy from temp to permanent storage
            $tempPath = $uploadResult['temp_path'];
            $fileContent = \Illuminate\Support\Facades\Storage::disk($tempDisk)->get($tempPath);
            \Illuminate\Support\Facades\Storage::disk($permanentDisk)->put($permanentPath, $fileContent);

            // Clean up temporary file
            \Illuminate\Support\Facades\Storage::disk($tempDisk)->delete($tempPath);

            Log::info('Video moved to permanent storage', [
                'temp_path' => $tempPath,
                'permanent_path' => $permanentPath,
            ]);

            return $permanentPath;

        } catch (\Exception $e) {
            Log::error('Failed to move video to permanent storage', [
                'temp_path' => $uploadResult['temp_path'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Clean up temporary files
     */
    private function cleanupTemporaryFiles(?array $uploadResult, ?string $audioPath): void
    {
        try {
            if ($uploadResult && isset($uploadResult['temp_path'])) {
                $tempDisk = config('livestream-processing.temp_disk', 'local');
                if (\Illuminate\Support\Facades\Storage::disk($tempDisk)->exists($uploadResult['temp_path'])) {
                    \Illuminate\Support\Facades\Storage::disk($tempDisk)->delete($uploadResult['temp_path']);
                }
            }

            if ($audioPath && file_exists($audioPath)) {
                unlink($audioPath);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup temporary files', [
                'error' => $e->getMessage(),
                'upload_result' => $uploadResult,
                'audio_path' => $audioPath,
            ]);
        }
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Dispatch thumbnail generation job for the created sermon
     */
    private function dispatchThumbnailGeneration(int $sermonId, string $videoPath): void
    {
        try {
            // Check if thumbnail generation is enabled
            if (! config('thumbnail-generation.enabled', true)) {
                Log::info('Thumbnail generation disabled, skipping', [
                    'sermon_id' => $sermonId,
                ]);

                return;
            }

            // Get the full path to the video file
            $sermonDisk = config('livestream-processing.sermon_disk', 'local');
            $fullVideoPath = Storage::disk($sermonDisk)->path($videoPath);

            // Verify video file exists before dispatching job
            if (! file_exists($fullVideoPath)) {
                Log::warning('Video file not found for thumbnail generation', [
                    'sermon_id' => $sermonId,
                    'video_path' => $videoPath,
                    'full_path' => $fullVideoPath,
                ]);

                return;
            }

            // Dispatch thumbnail generation job to dedicated queue
            GenerateThumbnail::dispatch($sermonId, $fullVideoPath)
                ->onQueue(config('thumbnail-generation.queue.name', 'thumbnails'));

            Log::info('Thumbnail generation job dispatched', [
                'sermon_id' => $sermonId,
                'video_path' => $fullVideoPath,
            ]);

        } catch (\Exception $e) {
            // Log error but don't throw - thumbnail generation should never block processing
            Log::warning('Failed to dispatch thumbnail generation job', [
                'sermon_id' => $sermonId,
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
