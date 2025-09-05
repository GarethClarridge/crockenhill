<?php

namespace App\Jobs;

use App\Data\SermonMetadata;
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
        string $processingId,
        public readonly SermonMetadata $metadata,
        public readonly string $storedFilePath
    ) {
        $this->processingId = $processingId;
    }

    /**
     * Execute the job.
     */
    public function handle(SermonProcessingLogger $logger): void
    {
        $startTime = microtime(true);

        try {
            $this->initializeStepLogging($this->processingId);

            // Check if processing has been cancelled
            if ($this->isCancelled()) {
                Log::info('CreateSermonRecord job cancelled', ['processing_id' => $this->processingId]);

                return;
            }

            $this->logStepStart('creating', 'Creating sermon record');
            $logger->logProcessingStep(
                $this->processingId,
                'creating_sermon_record',
                'started',
                ['original_filename' => $this->metadata->originalName]
            );

            // Update processing log to indicate we're starting
            $processingLog = SermonProcessingLog::where('processing_id', $this->processingId)->first();

            if (! $processingLog) {
                throw new \Exception("Processing log not found for ID: {$this->processingId}");
            }

            $processingLog->markAsProcessing('creating_sermon_record');

            // Generate initial slug from filename
            $initialTitle = $this->generateInitialTitle();
            $slug = $this->generateUniqueSlug($initialTitle);

            // Check if this is from a livestream
            $isFromLivestream = str_contains($processingLog->current_step ?? '', 'livestream');
            $livestreamProcessingId = null;

            if ($isFromLivestream) {
                // Extract livestream processing ID from current_step
                $parts = explode(':', $processingLog->current_step);
                if (count($parts) > 1) {
                    $livestreamProcessingId = $parts[1];
                }
            }

            // Create the sermon record with 'processing' status
            $sermonData = [
                'title' => $initialTitle,
                'filename' => $this->storedFilePath,
                'filetype' => pathinfo($this->metadata->originalName, PATHINFO_EXTENSION),
                'date' => $this->metadata->date,
                'service' => $this->metadata->service,
                'slug' => $slug,
                'series' => null, // Will be filled by AI analysis
                'reference' => null, // Will be filled by AI analysis
                'preacher' => 'Mark Drury', // Default preacher as specified
                'points' => null, // Will be filled by AI analysis
                'transcript_path' => null, // Will be set after transcription
            ];

            // Add livestream-specific fields if applicable
            if ($isFromLivestream && $livestreamProcessingId) {
                $sermonData['source_type'] = 'livestream';
                $sermonData['livestream_processing_id'] = $livestreamProcessingId;
                // Note: video_file_path, segment times will be set later by SermonMetadataIntegrationService
            }

            $sermon = Sermon::create($sermonData);

            // Update processing log with sermon ID
            $processingLog->update([
                'sermon_id' => $sermon->id,
                'current_step' => 'sermon_record_created',
            ]);

            $executionTime = microtime(true) - $startTime;

            $this->logStepComplete('creating', 'Sermon record created successfully');
            $logger->logProcessingStep(
                $this->processingId,
                'creating_sermon_record',
                'completed',
                [
                    'sermon_id' => $sermon->id,
                    'title' => $sermon->title,
                    'slug' => $sermon->slug,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                ]
            );

            // Dispatch the next job in the chain
            TranscribeAudio::dispatch($sermon->id)
                ->onQueue(config('sermon-processing.processing.queue', 'default'));
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $logger->logError(
                $this->processingId,
                'creating_sermon_record',
                $e,
                ['execution_time_ms' => round($executionTime * 1000, 2)]
            );

            // Update processing log with error
            if (isset($processingLog)) {
                $processingLog->markAsFailed($e->getMessage(), 'creating_sermon_record');
            }

            throw $e;
        }
    }

    /**
     * Generate an initial title from the filename
     */
    private function generateInitialTitle(): string
    {
        $filename = pathinfo($this->metadata->originalName, PATHINFO_FILENAME);

        // Remove common date patterns
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
        $title = preg_replace('/\d{1,2}[-_]\d{1,2}[-_]\d{4}/', '', $title);

        // Remove common sermon-related words and clean up
        $title = preg_replace('/\b(sermon|message|service|am|pm)\b/i', '', $title);
        $title = preg_replace('/[-_]+/', ' ', $title);
        $title = trim($title);

        // If title is empty or too short, use a default
        if (empty($title) || strlen($title) < 3) {
            $title = 'Sermon '.$this->metadata->date->format('Y-m-d');
        }

        // Capitalize words properly
        $title = Str::title($title);

        // Ensure it's not too long (will be replaced by AI-generated title later)
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
