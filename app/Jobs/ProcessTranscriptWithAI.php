<?php

namespace App\Jobs;

use App\Data\SermonAnalysis;
use App\Models\Sermon;
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
        public readonly int $sermonId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SermonAnalysisService $analysisService): void
    {
        try {
            Log::info('Starting AI transcript processing', [
                'sermon_id' => $this->sermonId,
            ]);

            // Get the sermon record
            $sermon = Sermon::find($this->sermonId);
            if (! $sermon) {
                throw new \Exception("Sermon not found with ID: {$this->sermonId}");
            }

            // Get the processing log
            /** @var \App\Models\SermonProcessingLog|null $processingLog */
            $processingLog = $sermon->processingLogs()->latest()->first();
            if (! $processingLog) {
                throw new \Exception("Processing log not found for sermon ID: {$this->sermonId}");
            }

            $this->initializeStepLogging($processingLog->processing_id);

            // Check if processing has been cancelled
            if ($this->isCancelled()) {
                Log::info('AI processing job cancelled', ['sermon_id' => $this->sermonId]);

                return;
            }

            // Log step start and update processing log
            $this->logStepStart('analyzing', 'Starting AI analysis');
            $processingLog->updateStep('analyzing_transcript');

            // Get the transcript content
            $transcript = $sermon->transcript;
            if (empty($transcript)) {
                throw new \Exception("No transcript available for sermon ID: {$this->sermonId}");
            }

            Log::info('Processing transcript with AI', [
                'sermon_id' => $this->sermonId,
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

            // Update processing log and mark step as complete
            $processingLog->updateStep('ai_analysis_completed');
            $this->logStepComplete('analyzing', 'AI analysis completed successfully');

            Log::info('AI transcript processing completed successfully', [
                'sermon_id' => $this->sermonId,
                'analysis_summary' => $analysis->getSummary(),
            ]);

            // Dispatch the next job in the chain
            UpdateSermonRecord::dispatch($this->sermonId)
                ->onQueue(config('sermon-processing.processing.queue', 'default'));
        } catch (\Exception $e) {
            Log::error('Failed to process transcript with AI', [
                'sermon_id' => $this->sermonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Handle graceful degradation - create fallback analysis
            $fallbackAnalysis = $this->createFallbackAnalysis($sermon);

            if ($fallbackAnalysis) {
                Log::info('Created fallback analysis after AI failure', [
                    'sermon_id' => $this->sermonId,
                    'fallback_title' => $fallbackAnalysis->title,
                ]);

                // Update processing log to indicate fallback was used
                if (isset($processingLog)) {
                    $processingLog->updateStep('ai_analysis_fallback');
                }

                // Continue the chain even with fallback analysis
                UpdateSermonRecord::dispatch($this->sermonId)
                    ->onQueue(config('sermon-processing.processing.queue', 'default'));
            } else {
                // Update processing log with error and log step failure
                if (isset($processingLog)) {
                    $processingLog->markAsFailed($e->getMessage(), 'analyzing_transcript');
                    $this->logStepFailed('analyzing', $e->getMessage());
                }

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
    private function createFallbackAnalysis(Sermon $sermon): ?SermonAnalysis
    {
        try {
            Log::info('Creating fallback analysis', ['sermon_id' => $sermon->id]);

            // Generate basic title from existing sermon title or filename
            $fallbackTitle = $this->generateFallbackTitle($sermon);

            // Get transcript content
            $transcript = $sermon->transcript ?? '';

            if (empty($transcript)) {
                Log::warning('No transcript available for fallback analysis');

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
                'sermon_id' => $sermon->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate a fallback title when AI processing fails
     */
    private function generateFallbackTitle(Sermon $sermon): string
    {
        // Try to use existing title if it's not the default
        if (! empty($sermon->title) && ! str_contains(strtolower($sermon->title), 'sermon')) {
            return $sermon->title;
        }

        // Generate from filename
        if (! empty($sermon->filename)) {
            $filename = pathinfo($sermon->filename, PATHINFO_FILENAME);
            $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
            $title = preg_replace('/[-_]+/', ' ', $title);
            $title = trim($title);

            if (! empty($title) && strlen($title) > 3) {
                return ucwords($title);
            }
        }

        // Final fallback using date
        return 'Sermon - '.$sermon->date->format('F j, Y');
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessTranscriptWithAI job failed permanently', [
            'sermon_id' => $this->sermonId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Try to create fallback analysis one more time
        try {
            $sermon = Sermon::find($this->sermonId);
            if ($sermon) {
                $fallbackAnalysis = $this->createFallbackAnalysis($sermon);

                if ($fallbackAnalysis) {
                    Log::info('Created fallback analysis in failed() method', [
                        'sermon_id' => $this->sermonId,
                    ]);

                    // Mark as completed with fallback data
                    /** @var \App\Models\SermonProcessingLog|null $processingLog */
                    $processingLog = $sermon->processingLogs()->latest()->first();
                    if ($processingLog) {
                        $processingLog->updateStep('ai_analysis_fallback_final');
                    }

                    return;
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to create fallback analysis in failed() method', [
                'sermon_id' => $this->sermonId,
                'error' => $e->getMessage(),
            ]);
        }

        // Mark processing as failed if fallback also failed
        $sermon = Sermon::find($this->sermonId);
        if ($sermon) {
            /** @var \App\Models\SermonProcessingLog|null $processingLog */
            $processingLog = $sermon->processingLogs()->latest()->first();
            if ($processingLog) {
                $processingLog->markAsFailed($exception->getMessage(), 'analyzing_transcript_failed');
            }
        }
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
