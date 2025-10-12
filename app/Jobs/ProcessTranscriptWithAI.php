<?php

namespace App\Jobs;

use App\Data\SermonAnalysis;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessTranscriptWithAI extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 600; // 10 minutes for AI analysis

    /**
     * Create a new job instance.
     */
    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SermonAnalysisService $analysisService): void
    {
        try {
            Log::info('Starting AI transcript processing', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            // Initialize step logging
            $this->initializeStepLogging($this->processingLog->processing_id);

            // Check if processing has been cancelled
            if ($this->isCancelled()) {
                Log::info('AI processing job cancelled', ['processing_id' => $this->processingLog->processing_id]);

                return;
            }

            // Log step start and update processing log
            $this->logStepStart('analyzing', 'Starting AI analysis');
            $this->processingLog->updateStep('analyzing_transcript');

            // Get transcript
            $transcriptPath = $this->processingLog->transcript_file_path;
            if (empty($transcriptPath)) {
                throw new \Exception('No transcript path available');
            }

            $transcript = Storage::get($transcriptPath);
            if (empty($transcript)) {
                throw new \Exception('Transcript file is empty or unreadable');
            }

            Log::info('Processing transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
                'word_count' => str_word_count($transcript),
            ]);

            // Get existing series for matching
            $existingSeries = $this->getExistingSeries();

            // Perform comprehensive AI analysis
            $analysis = $analysisService->analyzeSermon($transcript, $existingSeries);

            if (! $analysis->hasValidTranscript()) {
                throw new \Exception('AI analysis produced invalid results');
            }

            // Store in processing log
            $this->processingLog->update(['ai_analysis' => $analysis->toArray()]);

            // Update sermon - preserve ID3 metadata, only fill in missing fields with AI data
            $sermon = $this->processingLog->sermon;
            $updateData = [];

            // Get ID3 metadata from processing log if available
            $id3Metadata = is_array($this->processingLog->processing_metadata)
                && isset($this->processingLog->processing_metadata['id3_metadata'])
                ? $this->processingLog->processing_metadata['id3_metadata']
                : null;

            // Only update title if not set by ID3 tags
            if (! $id3Metadata || empty($id3Metadata['title'])) {
                $updateData['title'] = $analysis->title;
            }

            // Only update series if not set by ID3 tags
            if (! $id3Metadata || empty($id3Metadata['series'])) {
                $updateData['series'] = $analysis->series;
            }

            // Only update reference if not set by ID3 tags
            if (! $id3Metadata || empty($id3Metadata['reference'])) {
                $updateData['reference'] = $analysis->reference;
            }

            // Always update summary and points (ID3 tags don't contain these)
            $updateData['summary'] = $analysis->summary;
            $updateData['points'] = $analysis->points;

            Log::info('Updating sermon with AI analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'has_id3_metadata' => $id3Metadata !== null,
                'id3_fields_preserved' => $id3Metadata ? array_keys(array_filter($id3Metadata)) : [],
                'ai_fields_applied' => array_keys($updateData),
            ]);

            $sermon->update($updateData);

            // Update processing log and mark step as complete
            $this->processingLog->updateStep('ai_analysis_completed');
            $this->logStepComplete('analyzing', 'AI analysis completed successfully');

            Log::info('AI analysis completed', [
                'processing_id' => $this->processingLog->processing_id,
                'title' => $analysis->title,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Try fallback
            $fallbackAnalysis = $this->createFallbackAnalysis();
            if ($fallbackAnalysis) {
                $this->processingLog->update(['ai_analysis' => $fallbackAnalysis->toArray()]);

                // Update sermon if it exists - preserve ID3 metadata
                if ($this->processingLog->sermon) {
                    $updateData = [];

                    // Get ID3 metadata from processing log if available
                    $id3Metadata = is_array($this->processingLog->processing_metadata)
                        && isset($this->processingLog->processing_metadata['id3_metadata'])
                        ? $this->processingLog->processing_metadata['id3_metadata']
                        : null;

                    // Only update fields not set by ID3 tags
                    if (! $id3Metadata || empty($id3Metadata['title'])) {
                        $updateData['title'] = $fallbackAnalysis->title;
                    }
                    if (! $id3Metadata || empty($id3Metadata['series'])) {
                        $updateData['series'] = $fallbackAnalysis->series;
                    }
                    if (! $id3Metadata || empty($id3Metadata['reference'])) {
                        $updateData['reference'] = $fallbackAnalysis->reference;
                    }

                    $updateData['summary'] = $fallbackAnalysis->summary;
                    $updateData['points'] = $fallbackAnalysis->points;

                    $this->processingLog->sermon->update($updateData);
                }

                $this->processingLog->updateStep('ai_analysis_fallback');
            } else {
                $this->processingLog->markAsFailed($e->getMessage(), 'analyzing_transcript');
                $this->logStepFailed('analyzing', $e->getMessage());
                throw $e;
            }
        }
    }

    private function getExistingSeries(): array
    {
        return Sermon::whereNotNull('series')
            ->where('series', '!=', '')
            ->distinct()
            ->pluck('series')
            ->filter()
            ->values()
            ->toArray();
    }

    private function createFallbackAnalysis(): ?SermonAnalysis
    {
        try {
            $fallbackTitle = $this->generateFallbackTitle();
            $transcript = null;

            // Only try to read transcript if path exists
            if ($this->processingLog->transcript_file_path) {
                $transcript = Storage::get($this->processingLog->transcript_file_path);
            }

            return SermonAnalysis::create(
                title: $fallbackTitle,
                series: null,
                reference: null,
                points: ['Main Message'],
                summary: null,
                transcript: $transcript ?? ''
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    private function generateFallbackTitle(): string
    {
        $filename = pathinfo($this->processingLog->original_filename, PATHINFO_FILENAME);
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
        $title = preg_replace('/[-_]+/', ' ', $title);
        $title = trim($title);

        if (empty($title) || strlen($title) < 3) {
            return 'Sermon - '.$this->processingLog->created_at->format('F j, Y');
        }

        return Str::title($title);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessTranscriptWithAI job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
        ]);

        $this->processingLog->markAsFailed($exception->getMessage(), 'analyzing_transcript_failed');
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Exponential backoff: 2 minutes, 5 minutes, 10 minutes
        return [120, 300, 600];
    }
}
