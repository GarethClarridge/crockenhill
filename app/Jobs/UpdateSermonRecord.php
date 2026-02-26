<?php

namespace App\Jobs;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Enums\ProcessingStatus;
use App\Models\Sermon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UpdateSermonRecord implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300; // 5 minutes for record update

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $sermonId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SermonAnalysisInterface $analysisService): void
    {
        try {
            Log::info('Starting sermon record update', [
                'sermon_id' => $this->sermonId,
            ]);

            // Get the sermon record
            $sermon = Sermon::find($this->sermonId);
            if (! $sermon) {
                throw new \Exception("Sermon not found with ID: {$this->sermonId}");
            }

            // Get the processing log
            /** @var \App\Models\MediaProcessingLog|null $processingLog */
            $processingLog = $sermon->processingLogs()->latest()->first();
            if (! $processingLog) {
                throw new \Exception("Processing log not found for sermon ID: {$this->sermonId}");
            }

            // Update processing log to indicate final update started
            $processingLog->updateStep('updating_sermon_record');

            // Get the analysis results - either from previous job or regenerate
            $analysis = $this->getOrGenerateAnalysis($sermon, $analysisService);

            // Generate final slug from AI-generated title
            $finalSlug = $this->generateUniqueSlug($analysis->title, $sermon->id);

            // Update sermon record with all processed data
            $updateData = [
                'title' => $analysis->title,
                'slug' => $finalSlug,
                'series' => $analysis->series,
                'reference' => $analysis->reference,
                'points' => $analysis->points, // Will be cast to JSON by Eloquent
                'summary' => $analysis->summary,
            ];

            $sermon->update($updateData);

            // Mark processing as completed
            $processingLog->markAsCompleted();

            Log::info('Sermon record updated successfully', [
                'sermon_id' => $this->sermonId,
                'final_title' => $sermon->title,
                'final_slug' => $sermon->slug,
                'series' => $sermon->series ?? 'None',
                'reference' => $sermon->reference ?? 'None',
                'points_count' => count($sermon->points ?? []),
            ]);

            // Find the processing log for this sermon to dispatch notification
            $processingLog = \App\Models\MediaProcessingLog::where('sermon_id', $this->sermonId)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($processingLog) {
                SendCompletionNotification::dispatch($processingLog)
                    ->onQueue((string) config('media-processing.queues.default', 'default'));
            } else {
                Log::warning('No processing log found for sermon completion notification', [
                    'sermon_id' => $this->sermonId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update sermon record', [
                'sermon_id' => $this->sermonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update processing log with error
            if (isset($processingLog)) {
                $processingLog->markAsFailed($e->getMessage(), 'updating_sermon_record');
            }

            throw $e;
        }
    }

    /**
     * Get existing analysis or generate new one if needed
     */
    private function getOrGenerateAnalysis(Sermon $sermon, SermonAnalysisInterface $analysisService): SermonAnalysis
    {
        try {
            // Check if we have a transcript to work with
            $transcript = $sermon->transcript;

            if (empty($transcript)) {
                Log::warning('No transcript available for analysis', [
                    'sermon_id' => $sermon->id,
                ]);

                return $this->createBasicAnalysis($sermon);
            }

            // Try to get fresh analysis (this will use cached results if available)
            Log::info('Generating final analysis for sermon update', [
                'sermon_id' => $sermon->id,
                'transcript_length' => strlen($transcript),
            ]);

            $existingSeries = $this->getExistingSeries();
            $analysis = $analysisService->analyzeSermon($transcript, $existingSeries);

            return $analysis;
        } catch (\Exception $e) {
            Log::warning('Failed to generate analysis, using basic fallback', [
                'sermon_id' => $sermon->id,
                'error' => $e->getMessage(),
            ]);

            return $this->createBasicAnalysis($sermon);
        }
    }

    /**
     * Create basic analysis when AI processing is not available
     */
    private function createBasicAnalysis(Sermon $sermon): SermonAnalysis
    {
        // Use existing sermon data or generate basic values
        $title = $this->generateBasicTitle($sermon);
        $transcript = $sermon->transcript ?? '';

        return SermonAnalysis::create(
            title: $title,
            series: null,
            reference: null,
            points: ['Main Message'],
            summary: null,
            transcript: $transcript
        );
    }

    /**
     * Generate a basic title from existing sermon data
     */
    private function generateBasicTitle(Sermon $sermon): string
    {
        // If we already have a good title, use it
        if (
            ! empty($sermon->title) &&
            ! str_contains(strtolower($sermon->title), 'untitled') &&
            strlen($sermon->title) > 10
        ) {
            return $sermon->title;
        }

        // Generate from filename
        if (! empty($sermon->audio_file_path)) {
            $filename = pathinfo($sermon->audio_file_path, PATHINFO_FILENAME);
            $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
            $title = preg_replace('/[-_]+/', ' ', $title);
            $title = trim($title);

            if (! empty($title) && strlen($title) > 3) {
                return Str::title($title);
            }
        }

        // Final fallback using date and service
        $service = $sermon->service ? $sermon->service->value : '';

        return "Sermon - {$sermon->date->format('F j, Y')} {$service}";
    }

    /**
     * Get existing sermon series from database
     *
     * @return array<int, string>
     */
    private function getExistingSeries(): array
    {
        try {
            return Sermon::whereNotNull('series')
                ->where('series', '!=', '')
                ->distinct()
                ->pluck('series')
                ->filter()
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            Log::warning('Failed to retrieve existing series', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Generate a unique slug for the sermon, excluding the current sermon
     */
    private function generateUniqueSlug(string $title, int $excludeSermonId): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is unique (excluding current sermon)
        while (Sermon::where('slug', $slug)->where('id', '!=', $excludeSermonId)->exists()) {
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
        Log::error('UpdateSermonRecord job failed permanently', [
            'sermon_id' => $this->sermonId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Try to update with basic information to avoid leaving sermon in processing state
        try {
            $sermon = Sermon::find($this->sermonId);
            if ($sermon) {
                // Create minimal update to get sermon out of processing state
                $basicTitle = $this->generateBasicTitle($sermon);
                $basicSlug = $this->generateUniqueSlug($basicTitle, $sermon->id);

                $sermon->update([
                    'title' => $basicTitle,
                    'slug' => $basicSlug,
                ]);

                Log::info('Applied basic update after job failure', [
                    'sermon_id' => $this->sermonId,
                    'basic_title' => $basicTitle,
                ]);

                // Mark processing log as completed with error note
                $processingLog = $sermon->processingLogs()->latest()->first();
                if ($processingLog) {
                    $processingLog->update([
                        'status' => ProcessingStatus::COMPLETED,
                        'current_step' => 'completed_with_basic_data',
                        'error_message' => 'AI processing failed, used basic data: '.$exception->getMessage(),
                    ]);
                }

                return;
            }
        } catch (\Exception $e) {
            Log::error('Failed to apply basic update after job failure', [
                'sermon_id' => $this->sermonId,
                'error' => $e->getMessage(),
            ]);
        }

        // Mark processing as failed if basic update also failed
        $sermon = Sermon::find($this->sermonId);
        if ($sermon) {
            /** @var \App\Models\MediaProcessingLog|null $processingLog */
            $processingLog = $sermon->processingLogs()->latest()->first();
            if ($processingLog) {
                $processingLog->markAsFailed($exception->getMessage(), 'updating_sermon_record_failed');
            }
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Quick retries for database operations: 30 seconds, 2 minutes, 5 minutes
        return [30, 120, 300];
    }
}
