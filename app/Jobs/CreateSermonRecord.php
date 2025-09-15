<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use App\Services\SermonProcessingLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
        private SermonProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SermonProcessingLogger $logger): void
    {
        $startTime = microtime(true);

        try {
            $this->initializeStepLogging($this->processingLog->processing_id);

            // Validate processing log exists in database
            if (! $this->processingLog->exists()) {
                throw new \Exception('Processing log not found in database');
            }

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

            // Get AI analysis from processing log
            $aiAnalysis = null;
            if ($this->processingLog->ai_analysis) {
                $aiAnalysis = json_decode($this->processingLog->ai_analysis, true);
            }

            // Generate initial title from AI analysis or filename
            $initialTitle = $this->generateInitialTitle($aiAnalysis);
            $slug = $this->generateUniqueSlug($initialTitle);

            // Check if this is from a livestream
            $isFromLivestream = str_contains($this->processingLog->current_step ?? '', 'livestream');
            $livestreamProcessingId = null;

            if ($isFromLivestream) {
                // Extract livestream processing ID from current_step
                $parts = explode(':', $this->processingLog->current_step);
                if (count($parts) > 1) {
                    $livestreamProcessingId = $parts[1];
                }
            }

            // Create the sermon record with data from AI analysis and processing log
            $sermonData = [
                'title' => $initialTitle,
                'filename' => $this->processingLog->stored_file_path ?? $this->processingLog->original_filename,
                'filetype' => pathinfo($this->processingLog->original_filename, PATHINFO_EXTENSION),
                'date' => $this->extractDateFromFilename($this->processingLog->original_filename),
                'service' => $this->extractServiceFromFilename($this->processingLog->original_filename),
                'slug' => $slug,
                'series' => $aiAnalysis['series'] ?? null,
                'reference' => $aiAnalysis['reference'] ?? null,
                'preacher' => 'Mark Drury', // Default preacher as specified
                'points' => isset($aiAnalysis['points']) ? json_encode($aiAnalysis['points']) : null,
                'transcript_path' => $this->processingLog->transcript_path,
            ];

            // Add livestream-specific fields if applicable
            if ($isFromLivestream && $livestreamProcessingId) {
                $sermonData['source_type'] = 'livestream';
                $sermonData['livestream_processing_id'] = $livestreamProcessingId;
            }

            $sermon = Sermon::create($sermonData);

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
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateSermonRecord job failed permanently', [
            'processing_id' => $this->processingId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Mark processing as failed
        SermonProcessingLog::where('processing_id', $this->processingId)
            ->update([
                'status' => ProcessingStatus::FAILED,
                'error_message' => $exception->getMessage(),
                'current_step' => 'creating_sermon_record_failed',
            ]);
    }
}
