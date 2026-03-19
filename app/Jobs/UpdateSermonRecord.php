<?php

declare(strict_types=1);

namespace App\Jobs;

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
    public function handle(): void
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

            // Consume stored AI analysis — no re-analysis performed here
            $analysis = $this->getOrGenerateAnalysis($sermon, $processingLog);

            // Generate final slug from AI-generated title
            $finalSlug = $sermonRepository->generateUniqueSlug($analysis->title, $sermon->id);

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

            SendCompletionNotification::dispatch($processingLog)
                ->onQueue((string) config('media-processing.queues.default', 'default'));
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
     * Consume the stored ai_analysis from the processing log.
     * Falls back to a basic analysis when no stored result is available.
     */
    private function getOrGenerateAnalysis(Sermon $sermon, \App\Models\MediaProcessingLog $processingLog): SermonAnalysis
    {
        if (is_array($processingLog->ai_analysis)) {
            Log::info('Consuming stored AI analysis for sermon update', [
                'sermon_id' => $sermon->id,
            ]);

            return SermonAnalysis::fromAiAnalysis($processingLog->ai_analysis);
        }

        Log::warning('No stored AI analysis found, using basic fallback', [
            'sermon_id' => $sermon->id,
        ]);

        return $this->createBasicAnalysis($sermon);
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
            $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename) ?? $filename;
            $title = preg_replace('/[-_]+/', ' ', $title) ?? $title;
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
                $sermonRepository = app(SermonRepository::class);
                $basicSlug = $sermonRepository->generateUniqueSlug($basicTitle, $sermon->id);

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
