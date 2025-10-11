<?php

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonMetadataIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SubmitToProcessing implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        SermonMetadataIntegrationService $metadataIntegrationService
    ): void {
        try {
            // Update status to show sermon processing is starting
            $this->processingLog->markAsProcessing('sermon_creation');

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
                'livestream_config' => [
                    'storage_disk' => config('media-processing.storage_disk'),
                    'sermon_disk' => config('media-processing.storage.sermon_disk'),
                    'temp_disk' => config('media-processing.storage.temp_disk'),
                    'audio_path' => config('media-processing.storage.paths.audio'),
                ],
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
                    'config_diagnostics' => [
                        'sermon_processing_disk' => config('media-processing.storage.sermon_disk'),
                        'livestream_sermon_disk' => config('media-processing.storage.sermon_disk'),
                        'disk_mismatch' => config('media-processing.storage.sermon_disk') !== config('media-processing.storage.sermon_disk'),
                    ],
                ]);

                throw new \Exception('Sermon audio file not found: '.$this->processingLog->audio_file_path.' - Check logs for detailed diagnostics');
            }

            // Validate that the file is accessible via the public disk for web serving
            if ($sermonDisk === 'public' && ! Storage::disk('public')->exists($this->processingLog->audio_file_path)) {
                throw new \Exception('Sermon audio file not accessible via public disk: '.$this->processingLog->audio_file_path);
            }

            $metadata = [
                'source_type' => 'livestream',
                'livestream_processing_id' => $this->processingLog->processing_id,
                'original_filename' => $this->processingLog->original_filename,
                'segment_start_time' => $this->processingLog->sermon_start_time,
                'segment_end_time' => $this->processingLog->sermon_end_time,
                'video_file_path' => $this->processingLog->video_file_path,
            ];

            // Inline sermon creation logic - no longer calling external service
            $sermon = $this->createSermonFromLivestream($metadata);
            $sermonId = $sermon->id;

            // Update this processing log with the sermon ID immediately
            $this->processingLog->update(['sermon_id' => $sermonId]);

            Log::info('Created sermon record directly from livestream', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $sermonId,
                'sermon_title' => $sermon->title,
            ]);

            // Store video in permanent location
            $finalVideoPath = $metadataIntegrationService->storeVideoForSermon(
                $this->processingLog->processing_id,
                $sermonId
            );

            // Link video to sermon record
            $metadataIntegrationService->linkVideoToSermon(
                $this->processingLog->processing_id,
                $sermonId,
                $finalVideoPath
            );

            // Note: Thumbnail generation will be handled by the next job in the chain
            // Store video path in processing metadata for thumbnail generation job

            // Validate that the sermon record has a valid audio file path
            if ($sermonId) {
                $sermon = \App\Models\Sermon::find($sermonId);
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

            $this->processingLog->update([
                'sermon_id' => $sermonId,
                'current_step' => 'transcription',
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'final_video_path' => $finalVideoPath,
                        'sermon_creation_completed_at' => now()->toISOString(),
                    ]
                ),
            ]);

            Log::info('Sermon creation from livestream completed', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $sermonId,
                'final_video_path' => $finalVideoPath,
            ]);

            // Job chain will automatically proceed to transcription job

        } catch (\Exception $e) {
            Log::error('Sermon creation from livestream failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = 'Sermon creation from livestream failed: '.$e->getMessage();

            $this->processingLog->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);

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

        $this->processingLog->markAsFailed(
            'Sermon creation from livestream failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    /**
     * Create sermon record directly from livestream metadata
     */
    private function createSermonFromLivestream(array $metadata): Sermon
    {
        // Extract metadata from filename and livestream context
        $originalFilename = $metadata['original_filename'] ?? $this->processingLog->original_filename;

        $sermonData = [
            'title' => $this->generateTitleFromMetadata($metadata),
            'audio_file_path' => $this->processingLog->audio_file_path,
            'filetype' => pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'mp3',
            'date' => $this->extractDateFromFilename($originalFilename),
            'service' => $this->extractServiceFromFilename($originalFilename),
            'slug' => $this->generateUniqueSlug($this->generateTitleFromMetadata($metadata)),
            'preacher' => 'Mark Drury', // Default as per existing logic
            'source_type' => 'livestream',
            'livestream_processing_id' => $metadata['livestream_processing_id'],
        ];

        return Sermon::create($sermonData);
    }

    /**
     * Generate title from metadata
     */
    private function generateTitleFromMetadata(array $metadata): string
    {
        $originalFilename = $metadata['original_filename'] ?? $this->processingLog->original_filename;

        if (empty($originalFilename)) {
            return 'Sermon - '.now()->format('F j, Y');
        }

        $filename = pathinfo($originalFilename, PATHINFO_FILENAME);

        // Remove common date patterns
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
        $title = preg_replace('/\d{1,2}[-_]\d{1,2}[-_]\d{4}/', '', $title);

        // Remove common sermon-related words and clean up
        $title = preg_replace('/\b(sermon|message|service|am|pm)\b/i', '', $title);
        $title = preg_replace('/[-_]+/', ' ', $title);
        $title = trim($title);

        // If title is empty or too short, use a default
        if (empty($title) || strlen($title) < 3) {
            $date = $this->extractDateFromFilename($originalFilename);
            $service = $this->extractServiceFromFilename($originalFilename);
            $serviceLabel = $service === 'evening' ? 'Evening' : 'Morning';
            $title = $serviceLabel.' Sermon - '.date('F j, Y', strtotime($date));
        }

        // Capitalize words properly
        return Str::title($title);
    }

    /**
     * Extract date from filename
     */
    private function extractDateFromFilename(string $filename): string
    {
        // Try to extract date in various formats from filename
        if (preg_match('/(\d{4})[-_](\d{1,2})[-_](\d{1,2})/', $filename, $matches)) {
            return $matches[1].'-'.str_pad($matches[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($matches[3], 2, '0', STR_PAD_LEFT);
        }

        // Try DD-MM-YYYY format
        if (preg_match('/(\d{1,2})[-_](\d{1,2})[-_](\d{4})/', $filename, $matches)) {
            return $matches[3].'-'.str_pad($matches[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        }

        // Fallback to current date if no date pattern found
        return now()->format('Y-m-d');
    }

    /**
     * Extract service from filename
     */
    private function extractServiceFromFilename(string $filename): string
    {
        $filename = strtolower($filename);

        if (str_contains($filename, 'evening')) {
            return 'evening';
        }

        if (str_contains($filename, 'morning')) {
            return 'morning';
        }

        // Default to morning if no service pattern found
        return 'morning';
    }

    /**
     * Generate a unique slug for the sermon
     */
    private function generateUniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is unique
        while (Sermon::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}
