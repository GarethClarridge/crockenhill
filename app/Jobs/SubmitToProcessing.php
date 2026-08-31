<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonCreationOptions;
use App\Enums\ProcessingStep;
use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Sermon\SermonCreationService;
use App\Traits\ChecksCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SubmitToProcessing implements ShouldQueue
{
    use ChecksCancellation, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        SermonCreationService $sermonCreationService,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);

        if ($this->abortIfCancelled('SubmitToProcessing')) {
            return;
        }

        try {
            // Update status to show sermon processing is starting
            $processingRunTransitions->markAsProcessing($this->processingLog, ProcessingStep::SermonCreation->value);

            Log::info('Starting sermon creation from livestream', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $this->processingLog->audio_file_path,
            ]);

            if (! $this->processingLog->audio_file_path) {
                throw new \Exception('Sermon audio path not found in processing log');
            }

            // Get sermon disk configuration
            $sermonDisk = config('media-processing.storage.sermon_disk', 'public');
            $diskInstance = Storage::disk($sermonDisk);

            Log::info('Checking for audio file', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_audio_path' => $this->processingLog->audio_file_path,
                'sermon_disk' => $sermonDisk,
            ]);

            // Use Storage::exists() for all disk types (works for both local and S3)
            if (! $diskInstance->exists($this->processingLog->audio_file_path)) {
                // Additional diagnostics
                $diskExists = Storage::disk($sermonDisk)->exists($this->processingLog->audio_file_path);
                $alternativeFiles = [];
                $parentDirs = [];

                // Look for files in the expected directory
                $expectedDir = dirname($this->processingLog->audio_file_path);
                try {
                    if (Storage::disk($sermonDisk)->exists($expectedDir)) {
                        $alternativeFiles = Storage::disk($sermonDisk)->files($expectedDir);
                    }

                    // Also check parent directories for diagnostic purposes
                    $parentDirs = [
                        'sermons' => Storage::disk($sermonDisk)->exists('sermons') ? Storage::disk($sermonDisk)->files('sermons') : [],
                        'sermons/audio' => Storage::disk($sermonDisk)->exists('sermons/audio') ? Storage::disk($sermonDisk)->files('sermons/audio') : [],
                    ];
                } catch (\Exception $e) {
                    Log::warning('Could not list files in expected directory', [
                        'directory' => $expectedDir,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Check if there are any similar files with the same processing ID
                $processingUuid = explode('_', basename($this->processingLog->audio_file_path))[0];
                $similarFiles = [];
                if ($processingUuid) {
                    try {
                        $allFiles = Storage::disk($sermonDisk)->allFiles('sermons');
                        $similarFiles = array_filter($allFiles, fn ($file) => str_contains($file, $processingUuid));
                    } catch (\Exception $e) {
                        Log::warning('Could not search for similar files', [
                            'processing_uuid' => $processingUuid,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                Log::error('Sermon audio file not found - detailed diagnostics', [
                    'processing_id' => $this->processingLog->processing_id,
                    'expected_path' => $this->processingLog->audio_file_path,
                    'relative_path' => $this->processingLog->audio_file_path,
                    'disk_exists_check' => $diskExists,
                    'files_in_expected_dir' => $alternativeFiles,
                    'parent_directory_contents' => $parentDirs,
                    'similar_files_found' => $similarFiles,
                    'processing_log_status' => $this->processingLog->status,
                    'processing_log_error' => $this->processingLog->error_message,
                    'sermon_disk' => $sermonDisk,
                ]);

                throw new \Exception('Sermon audio file not found: '.$this->processingLog->audio_file_path.' - Check logs for detailed diagnostics');
            }

            // Validate that the file is accessible via the public disk for web serving
            if ($sermonDisk === 'public' && ! Storage::disk('public')->exists($this->processingLog->audio_file_path)) {
                throw new \Exception('Sermon audio file not accessible via public disk: '.$this->processingLog->audio_file_path);
            }

            $metadata = [
                'livestream_processing_id' => $this->processingLog->processing_id,
                'original_filename' => $this->processingLog->original_filename,
                'segment_start_time' => $this->processingLog->sermon_start_time,
                'segment_end_time' => $this->processingLog->sermon_end_time,
                'video_file_path' => $this->processingLog->video_file_path,
            ];

            $existingSermon = $this->resolveExistingLivestreamSermon();

            if ($existingSermon instanceof Sermon) {
                $sermon = $this->refreshExistingLivestreamSermon($existingSermon);
                $sermonId = $sermon->id;

                Log::info('Refreshing existing sermon record from livestream reprocessing', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $sermonId,
                    'sermon_title' => $sermon->title,
                ]);
            } else {
                // Create sermon using unified service
                $options = SermonCreationOptions::fromLivestream($this->processingLog, $metadata);
                $sermon = $sermonCreationService->createSermon($this->processingLog, $options);
                $sermonId = $sermon->id;

                // Update this processing log with the sermon ID immediately
                $this->processingLog->update(['sermon_id' => $sermonId]);

                Log::info('Created sermon record directly from livestream', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $sermonId,
                    'sermon_title' => $sermon->title,
                ]);
            }

            // Dispatch video upload independently so it does not block audio processing.
            // AssessSermonVideoQuality and GenerateThumbnail both handle a missing video_file_path gracefully.
            dispatch(new StoreSermonVideo($this->processingLog, $sermonId));

            // Validate that the sermon record has a valid audio file path
            if ($sermonId) {
                $sermon = Sermon::query()->find($sermonId);
                if ($sermon && $sermon->audio_file_path) {
                    // Verify the audio file is accessible for web serving
                    if (! Storage::disk('public')->exists($sermon->audio_file_path)) {
                        Log::warning('Sermon audio file may not be web-accessible', [
                            'sermon_id' => $sermonId,
                            'filename' => $sermon->audio_file_path,
                            'processing_id' => $this->processingLog->processing_id,
                        ]);
                    }
                }
            }

            $processingRunTransitions->updateRunFields($this->processingLog, [
                'sermon_id' => $sermonId,
                'current_step' => ProcessingStep::Transcription->value,
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata?->toArray() ?? [],
                    ['sermon_creation_completed_at' => now()->toISOString()]
                ),
            ]);

            Log::info('Sermon creation from livestream completed', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $sermonId,
            ]);

            // Job chain will automatically proceed to transcription job

        } catch (\Exception $e) {
            Log::error('Sermon creation from livestream failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $processingRunTransitions->markAsFailed($this->processingLog, 'Sermon creation from livestream failed: '.$e->getMessage());

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SubmitToProcessing job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        app(MediaProcessingRunTransitionService::class)->markAsFailed(
            $this->processingLog,
            'Sermon creation from livestream failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    private function refreshExistingLivestreamSermon(Sermon $sermon): Sermon
    {
        $updates = [
            'audio_file_path' => $this->processingLog->audio_file_path,
            'source_type' => SermonSourceType::Livestream,
            'livestream_processing_id' => $this->processingLog->processing_id,
            'segment_start_time' => $this->processingLog->sermon_start_time,
            'segment_end_time' => $this->processingLog->sermon_end_time,
        ];

        $duration = $this->processingLog->observedSermonMediaDuration();

        if ($duration === null
            && $this->processingLog->sermon_start_time !== null
            && $this->processingLog->sermon_end_time !== null
            && $this->processingLog->sermon_end_time > $this->processingLog->sermon_start_time) {
            $duration = $this->processingLog->sermon_end_time - $this->processingLog->sermon_start_time;
        }

        if ($duration !== null) {
            $updates['duration'] = $duration;
        }

        $sermon->update($updates);

        return $sermon->fresh() ?? $sermon;
    }

    private function resolveExistingLivestreamSermon(): ?Sermon
    {
        $existingSermon = $this->processingLog->sermon;

        if ($existingSermon instanceof Sermon) {
            return $existingSermon;
        }

        return Sermon::query()
            ->where('livestream_processing_id', $this->processingLog->processing_id)
            ->latest('id')
            ->first();
    }
}
