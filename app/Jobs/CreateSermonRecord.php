<?php

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonProcessingLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateSermonRecord extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SermonProcessingLogger $logger): void
    {
        $startTime = microtime(true);

        try {
            // Refresh the processing log to get latest data (including metadata updates)
            $refreshedLog = $this->processingLog->fresh();

            if (! $refreshedLog) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $refreshedLog;

            $this->initializeStepLogging($this->processingLog->processing_id);

            // Check if processing has been cancelled
            if ($this->isCancelled()) {
                Log::info('CreateSermonRecord job cancelled', ['processing_id' => $this->processingLog->processing_id]);

                return;
            }

            $this->logStepStart('creating', 'Creating sermon record');
            $logger->logProcessingStep(
                $this->processingLog->processing_id,
                'creating_sermon_record',
                'started',
                ['original_filename' => $this->processingLog->original_filename]
            );

            // Update processing log to indicate we're starting
            $this->processingLog->markAsProcessing('creating_sermon_record');

            // Get AI analysis - handle both array and JSON string formats
            $aiAnalysis = $this->processingLog->ai_analysis;
            // @phpstan-ignore-next-line (defensive code for potential legacy data)
            if (is_string($aiAnalysis)) {
                $aiAnalysis = json_decode($aiAnalysis, true);
            }

            // Generate title and slug
            $initialTitle = $this->generateInitialTitle($aiAnalysis);
            $slug = $this->generateUniqueSlug($initialTitle);

            // Determine filename based on processing type
            $filename = match ($this->processingLog->processing_type) {
                'audio' => $this->processingLog->source_file_path,
                'video', 'livestream' => $this->processingLog->audio_file_path,
                default => throw new \Exception("Unknown processing type: {$this->processingLog->processing_type}"),
            };

            // Extract date using cascading strategy: metadata > filename > today
            $sermonDate = $this->extractSermonDate();

            // Create sermon record
            $sermonData = [
                'title' => $initialTitle,
                'audio_file_path' => $filename,
                'filetype' => pathinfo($this->processingLog->original_filename, PATHINFO_EXTENSION),
                'date' => $sermonDate,
                'service' => $this->extractServiceFromFilename($this->processingLog->original_filename),
                'slug' => $slug,
                'series' => $aiAnalysis['series'] ?? null,
                'reference' => $aiAnalysis['reference'] ?? null,
                'preacher' => 'Mark Drury',
                'points' => isset($aiAnalysis['points']) ? json_encode($aiAnalysis['points']) : null,
                'transcript_file_path' => $this->processingLog->transcript_file_path,
                'source_type' => $this->mapProcessingTypeToSourceType($this->processingLog->processing_type),
            ];

            // Add video path for video/livestream types
            if (in_array($this->processingLog->processing_type, ['video', 'livestream'])) {
                $sermonData['video_file_path'] = $this->processingLog->video_file_path;
            }

            $sermon = Sermon::create($sermonData);

            // Store video permanently if needed
            if ($this->processingLog->processing_type === 'video' && $this->processingLog->source_file_path) {
                $finalVideoPath = $this->storeVideoForSermon($sermon->id, $this->processingLog->source_file_path);
                $sermon->update(['video_file_path' => $finalVideoPath]);
                $this->processingLog->update(['video_file_path' => $finalVideoPath]);
            }

            // Update processing log with sermon ID
            $this->processingLog->update([
                'sermon_id' => $sermon->id,
                'current_step' => 'sermon_record_created',
            ]);

            // Note: Job chain will automatically dispatch the next job (TranscribeAudio)
            // Manual dispatching removed to prevent conflicts with job chain system

            $executionTime = microtime(true) - $startTime;

            $this->logStepComplete('creating', 'Sermon record created successfully');
            $logger->logProcessingStep(
                $this->processingLog->processing_id,
                'creating_sermon_record',
                'completed',
                [
                    'sermon_id' => $sermon->id,
                    'title' => $sermon->title,
                    'slug' => $sermon->slug,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                ]
            );
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $logger->logError(
                $this->processingLog->processing_id,
                'creating_sermon_record',
                $e,
                ['execution_time_ms' => round($executionTime * 1000, 2)]
            );

            // Update processing log with error
            $this->processingLog->markAsFailed($e->getMessage(), 'creating_sermon_record');
            $this->logStepFailed('creating', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Generate an initial title from AI analysis or filename
     */
    private function generateInitialTitle(?array $aiAnalysis = null): string
    {
        // Use AI-generated title if available
        if ($aiAnalysis && ! empty($aiAnalysis['title'])) {
            return Str::limit($aiAnalysis['title'], 100, '');
        }

        // Fall back to filename processing
        $filename = pathinfo($this->processingLog->original_filename, PATHINFO_FILENAME);

        // Remove common date patterns
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
        $title = preg_replace('/\d{1,2}[-_]\d{1,2}[-_]\d{4}/', '', $title);

        // Remove common sermon-related words and clean up
        $title = preg_replace('/\b(sermon|message|service|am|pm)\b/i', '', $title);
        $title = preg_replace('/[-_]+/', ' ', $title);
        $title = trim($title);

        // If title is empty or too short, use a default
        if (empty($title) || strlen($title) < 3) {
            $title = 'Sermon '.$this->processingLog->created_at->format('Y-m-d');
        }

        // Capitalize words properly
        $title = Str::title($title);

        // Ensure it's not too long
        return Str::limit($title, 100, '');
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

    /**
     * Extract sermon date using cascading fallback strategy:
     * 1. Check processing_metadata for extracted_date (from video metadata)
     * 2. Fall back to filename parsing
     * 3. Final fallback to current date
     */
    private function extractSermonDate(): string
    {
        // Strategy 1: Check if date was extracted from video/audio metadata
        $processingMetadata = $this->processingLog->processing_metadata;

        // Debug logging
        Log::info('CreateSermonRecord: Checking for extracted date', [
            'processing_id' => $this->processingLog->processing_id,
            'metadata_type' => gettype($processingMetadata),
            'is_array' => is_array($processingMetadata),
            'has_extracted_date' => is_array($processingMetadata) && isset($processingMetadata['extracted_date']),
            'metadata_keys' => is_array($processingMetadata) ? array_keys($processingMetadata) : [],
        ]);

        if (is_array($processingMetadata) && isset($processingMetadata['extracted_date'])) {
            $extractedDate = $processingMetadata['extracted_date'];
            Log::info('Using date extracted from file metadata', [
                'processing_id' => $this->processingLog->processing_id,
                'extracted_date' => $extractedDate,
                'extraction_method' => $processingMetadata['date_extraction_method'] ?? 'unknown',
            ]);

            return $extractedDate;
        }

        // Strategy 2: Fall back to filename parsing
        $filenameDate = $this->extractDateFromFilename($this->processingLog->original_filename);

        // Strategy 3: Final fallback is handled within extractDateFromFilename (defaults to today)
        Log::info('Using date extracted from filename', [
            'processing_id' => $this->processingLog->processing_id,
            'filename' => $this->processingLog->original_filename,
            'extracted_date' => $filenameDate,
        ]);

        return $filenameDate;
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
     * Map processing_type to source_type enum values for sermons table
     */
    private function mapProcessingTypeToSourceType(string $processingType): string
    {
        return match ($processingType) {
            'audio' => 'audio_upload',
            'video' => 'video_upload',
            'livestream' => 'livestream',
            default => 'manual',
        };
    }

    /**
     * Store video file to permanent sermon storage
     * Similar to SermonMetadataIntegrationService::organizeVideoFile
     */
    private function storeVideoForSermon(int $sermonId, string $tempVideoPath): string
    {
        $sermonDisk = Storage::disk(config('media-processing.storage.sermon_disk', 'public'));

        // Get the temp disk and resolve absolute path
        $tempDisk = config('filesystems.default', 'local');
        $absoluteTempPath = Storage::disk($tempDisk)->path($tempVideoPath);

        // Create directory structure based on sermon ID
        $directory = "sermons/{$sermonId}";
        $extension = pathinfo($tempVideoPath, PATHINFO_EXTENSION);
        $filename = "video.{$extension}"; // Preserve original extension (mkv, mp4, etc)
        $finalPath = "{$directory}/{$filename}";

        // Ensure the directory exists
        $sermonDisk->makeDirectory($directory);

        // Copy the video file to the final location
        $sermonDisk->putFileAs(
            $directory,
            new File($absoluteTempPath),
            $filename
        );

        Log::info('Video file moved to permanent storage', [
            'source_path' => $tempVideoPath,
            'absolute_path' => $absoluteTempPath,
            'final_path' => $finalPath,
            'sermon_id' => $sermonId,
        ]);

        return $finalPath;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateSermonRecord job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
        ]);

        $this->processingLog->markAsFailed($exception->getMessage(), 'creating_sermon_record_failed');
    }
}
