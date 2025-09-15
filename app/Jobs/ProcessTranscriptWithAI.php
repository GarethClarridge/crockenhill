<?php

namespace App\Jobs;

use App\Data\SermonAnalysis;
use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use App\Services\SermonAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        private SermonProcessingLog $processingLog
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

            // Get the transcript content from processing log
            $transcriptPath = $this->processingLog->transcript_path;
            if (empty($transcriptPath)) {
                throw new \Exception("No transcript path available in processing log: {$this->processingLog->processing_id}");
            }

            // Read transcript content
            $transcript = \Illuminate\Support\Facades\Storage::get($transcriptPath);
            if (empty($transcript)) {
                throw new \Exception("Transcript file is empty or unreadable: {$transcriptPath}");
            }

            Log::info('Processing transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'transcript_path' => $transcriptPath,
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
            ]);

            // Get existing series for matching
            $existingSeries = $this->getExistingSeries();

            // Perform comprehensive AI analysis
            $analysis = $analysisService->analyzeSermon($transcript, $existingSeries);

            // Validate the analysis results
            if (! $analysis->hasValidTranscript()) {
                throw new \Exception('AI analysis produced invalid results');
            }

            // Store analysis results in processing log
            $this->processingLog->update([
                'ai_analysis' => json_encode($analysis->toArray()),
            ]);

            // Update processing log and mark step as complete
            $this->processingLog->updateStep('ai_analysis_completed');
            $this->logStepComplete('analyzing', 'AI analysis completed successfully');

            Log::info('AI transcript processing completed successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'analysis_summary' => $analysis->getSummary(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Handle graceful degradation - create fallback analysis
            $fallbackAnalysis = $this->createFallbackAnalysis();

            if ($fallbackAnalysis) {
                Log::info('Created fallback analysis after AI failure', [
                    'processing_id' => $this->processingLog->processing_id,
                    'fallback_title' => $fallbackAnalysis->title ?? 'Untitled Sermon',
                ]);

                // Store fallback analysis and update processing log
                $this->processingLog->update([
                    'ai_analysis' => json_encode($fallbackAnalysis->toArray()),
                ]);
                $this->processingLog->updateStep('ai_analysis_fallback');
            } else {
                // Update processing log with error and log step failure
                $this->processingLog->markAsFailed($e->getMessage(), 'analyzing_transcript');
                $this->logStepFailed('analyzing', $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Get existing sermon series from database
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
     * Create fallback analysis when AI processing fails
     */
    private function createFallbackAnalysis(): ?SermonAnalysis
    {
        try {
            Log::info('Creating fallback analysis', ['processing_id' => $this->processingLog->processing_id]);

            // Generate basic title from original filename
            $fallbackTitle = $this->generateFallbackTitle();

            // Get transcript content from file
            $transcriptPath = $this->processingLog->transcript_path;
            if (empty($transcriptPath)) {
                Log::warning('No transcript path available for fallback analysis');

                return null;
            }

            $transcript = \Illuminate\Support\Facades\Storage::get($transcriptPath);
            if (empty($transcript)) {
                Log::warning('No transcript content available for fallback analysis');

                return null;
            }

            // Create basic analysis with fallback values
            $analysis = SermonAnalysis::create(
                title: $fallbackTitle,
                series: null, // No series matching in fallback
                reference: null, // No Bible passage extraction in fallback
                points: ['Main Message'], // Simple fallback points
                summary: null, // No summary available in fallback
                transcript: $transcript
            );

            return $analysis;
        } catch (\Exception $e) {
            Log::error('Failed to create fallback analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate a fallback title when AI processing fails
     */
    private function generateFallbackTitle(): string
    {
        // Generate from original filename
        $originalFilename = $this->processingLog->original_filename;
        if (! empty($originalFilename)) {
            $filename = pathinfo($originalFilename, PATHINFO_FILENAME);
            $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
            $title = preg_replace('/[-_]+/', ' ', $title);
            $title = trim($title);

            if (! empty($title) && strlen($title) > 3) {
                return ucwords($title);
            }
        }

        // Final fallback using processing date
        return 'Sermon - '.$this->processingLog->created_at->format('F j, Y');
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessTranscriptWithAI job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Try to create fallback analysis one more time
        try {
            $fallbackAnalysis = $this->createFallbackAnalysis();

            if ($fallbackAnalysis) {
                Log::info('Created fallback analysis in failed() method', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                // Store fallback analysis and mark as completed with fallback data
                $this->processingLog->update([
                    'ai_analysis' => json_encode($fallbackAnalysis->toArray()),
                ]);
                $this->processingLog->updateStep('ai_analysis_fallback_final');

                return;
            }
        } catch (\Exception $e) {
            Log::error('Failed to create fallback analysis in failed() method', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Mark processing as failed if fallback also failed
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
