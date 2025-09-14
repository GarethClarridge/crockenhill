<?php

namespace App\Jobs;

use App\Data\SermonAnalysis;
use App\Models\LivestreamProcessingLog;
use App\Models\Sermon;
use App\Services\SermonAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnalyzeSermonTranscriptFromLivestream implements ShouldQueue
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
        private LivestreamProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SermonAnalysisService $analysisService): void
    {
        try {
            Log::info('Starting livestream AI transcript analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id,
            ]);

            // Check if processing has been cancelled
            if ($this->isProcessingCancelled()) {
                Log::info('Livestream AI analysis job cancelled', ['processing_id' => $this->processingLog->processing_id]);
                return;
            }

            // Update processing log status
            $this->processingLog->update(['status' => 'processing']);

            // Validate sermon exists
            if (!$this->processingLog->sermon_id) {
                throw new \Exception("No sermon ID found in processing log: {$this->processingLog->processing_id}");
            }

            $sermon = Sermon::find($this->processingLog->sermon_id);
            if (!$sermon) {
                throw new \Exception("Sermon not found: {$this->processingLog->sermon_id}");
            }

            // Get transcript path from sermon record
            $transcriptPath = $sermon->transcript_path;
            if (empty($transcriptPath)) {
                throw new \Exception("No transcript path available for sermon: {$sermon->id}");
            }

            // Read transcript content
            $transcript = Storage::get($transcriptPath);
            if (empty($transcript)) {
                throw new \Exception("Transcript file is empty or unreadable: {$transcriptPath}");
            }

            Log::info('Processing livestream transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $sermon->id,
                'transcript_path' => $transcriptPath,
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
            ]);

            // Get existing series for matching
            $existingSeries = $this->getExistingSeries();

            // Perform comprehensive AI analysis
            $analysis = $analysisService->analyzeSermon($transcript, $existingSeries);

            // Validate the analysis results
            if (!$analysis->hasValidTranscript()) {
                throw new \Exception('AI analysis produced invalid results');
            }

            // Update sermon record with AI analysis results
            $this->updateSermonWithAnalysis($sermon, $analysis);

            // Store analysis results in processing metadata
            $this->processingLog->update([
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'ai_analysis' => $analysis->toArray(),
                        'analysis_completed_at' => now()->toISOString(),
                    ]
                ),
            ]);

            // Update processing log status
            $this->processingLog->update(['status' => 'completed']);

            Log::info('Livestream AI transcript analysis completed successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $sermon->id,
                'analysis_summary' => $analysis->getSummary(),
                'updated_title' => $sermon->fresh()->title,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to analyze livestream transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Handle graceful degradation - create fallback analysis
            $fallbackAnalysis = $this->createFallbackAnalysis();

            if ($fallbackAnalysis && $this->processingLog->sermon_id) {
                Log::info('Created fallback analysis after AI failure', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $this->processingLog->sermon_id,
                    'fallback_title' => $fallbackAnalysis->title ?? 'Untitled Sermon',
                ]);

                // Apply fallback analysis to sermon
                $sermon = Sermon::find($this->processingLog->sermon_id);
                if ($sermon) {
                    $this->updateSermonWithAnalysis($sermon, $fallbackAnalysis);

                    // Store fallback analysis and update processing log
                    $this->processingLog->update([
                        'processing_metadata' => array_merge(
                            $this->processingLog->processing_metadata ?? [],
                            [
                                'ai_analysis' => $fallbackAnalysis->toArray(),
                                'analysis_completed_at' => now()->toISOString(),
                                'used_fallback' => true,
                                'fallback_reason' => $e->getMessage(),
                            ]
                        ),
                        'status' => 'completed',
                    ]);

                    Log::info('Applied fallback analysis successfully', [
                        'processing_id' => $this->processingLog->processing_id,
                        'sermon_id' => $sermon->id,
                    ]);
                    return;
                }
            }

            // Update processing log with error if fallback failed
            $this->processingLog->update([
                'status' => 'failed',
                'error_message' => 'Livestream AI analysis failed: ' . $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeSermonTranscriptFromLivestream job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_id' => $this->processingLog->sermon_id ?? null,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Try to create fallback analysis one more time
        try {
            $fallbackAnalysis = $this->createFallbackAnalysis();

            if ($fallbackAnalysis && $this->processingLog->sermon_id) {
                Log::info('Created fallback analysis in failed() method', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $this->processingLog->sermon_id,
                ]);

                // Apply fallback analysis to sermon
                $sermon = Sermon::find($this->processingLog->sermon_id);
                if ($sermon) {
                    $this->updateSermonWithAnalysis($sermon, $fallbackAnalysis);

                    // Store fallback analysis and mark as completed with fallback data
                    $this->processingLog->update([
                        'processing_metadata' => array_merge(
                            $this->processingLog->processing_metadata ?? [],
                            [
                                'ai_analysis' => $fallbackAnalysis->toArray(),
                                'analysis_completed_at' => now()->toISOString(),
                                'used_fallback' => true,
                                'fallback_reason' => $exception->getMessage(),
                                'applied_in_failed_method' => true,
                            ]
                        ),
                        'status' => 'completed',
                    ]);
                    return;
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to create fallback analysis in failed() method', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        // Mark processing as failed if fallback also failed
        $this->processingLog->markAsFailed(
            'Livestream AI analysis failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
        );
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Exponential backoff: 2 minutes, 5 minutes, 10 minutes
        return [120, 300, 600];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    /**
     * Check if processing has been cancelled
     */
    private function isProcessingCancelled(): bool
    {
        // Refresh the processing log to get latest status
        $this->processingLog->refresh();

        // Check if status indicates cancellation
        return in_array($this->processingLog->status, ['cancelled', 'failed']) ||
               str_contains($this->processingLog->error_message ?? '', 'cancelled');
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
     * Update sermon record with analysis results
     */
    private function updateSermonWithAnalysis(Sermon $sermon, SermonAnalysis $analysis): void
    {
        $updateData = [];

        // Update title if AI provided a better one
        if (!empty($analysis->title) && $analysis->title !== $sermon->title) {
            $updateData['title'] = $analysis->title;

            // Generate new slug for the updated title
            $updateData['slug'] = $this->generateUniqueSlug($analysis->title, $sermon->id);
        }

        // Update series if identified
        if (!empty($analysis->series)) {
            $updateData['series'] = $analysis->series;
        }

        // Update Bible reference if identified
        if (!empty($analysis->reference)) {
            $updateData['reference'] = $analysis->reference;
        }

        // Update sermon points if available
        if (!empty($analysis->points)) {
            $updateData['points'] = json_encode($analysis->points);
        }

        // Update summary if available
        if (!empty($analysis->summary)) {
            $updateData['summary'] = $analysis->summary;
        }

        // Only update if we have changes
        if (!empty($updateData)) {
            $sermon->update($updateData);

            Log::info('Updated sermon with AI analysis', [
                'sermon_id' => $sermon->id,
                'updated_fields' => array_keys($updateData),
                'title_changed' => isset($updateData['title']),
                'new_title' => $updateData['title'] ?? null,
            ]);
        }
    }

    /**
     * Create fallback analysis when AI processing fails
     */
    private function createFallbackAnalysis(): ?SermonAnalysis
    {
        try {
            Log::info('Creating fallback analysis for livestream', ['processing_id' => $this->processingLog->processing_id]);

            // Generate basic title from original filename
            $fallbackTitle = $this->generateFallbackTitle();

            // Get transcript content from sermon record if available
            $transcript = '';
            if ($this->processingLog->sermon_id) {
                $sermon = Sermon::find($this->processingLog->sermon_id);
                if ($sermon && $sermon->transcript_path) {
                    try {
                        $transcript = Storage::get($sermon->transcript_path);
                    } catch (\Exception $e) {
                        Log::warning('Could not read transcript for fallback analysis', [
                            'sermon_id' => $sermon->id,
                            'transcript_path' => $sermon->transcript_path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
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
            Log::error('Failed to create fallback analysis for livestream', [
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
        if (!empty($originalFilename)) {
            $filename = pathinfo($originalFilename, PATHINFO_FILENAME);
            $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
            $title = preg_replace('/[-_]+/', ' ', $title);
            $title = trim($title);

            if (!empty($title) && strlen($title) > 3) {
                return Str::title($title);
            }
        }

        // Final fallback using processing date
        return 'Sermon - ' . $this->processingLog->created_at->format('F j, Y');
    }

    /**
     * Generate a unique slug, excluding the current sermon
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
}