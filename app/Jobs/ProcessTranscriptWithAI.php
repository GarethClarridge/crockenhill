<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\QueueScriptureEnrichment;
use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\TranscriptStorageService;
use App\Services\Public\SermonRepository;
use App\Services\Sermon\SermonSlugGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
        private MediaProcessingLog $processingLog,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SermonAnalysisInterface $analysisService, SermonRepository $sermonRepository): void
    {
        try {
            Log::info('Starting AI transcript processing', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
                Log::info('AI processing job cancelled or log missing', ['processing_id' => $this->processingLog->processing_id]);

                return;
            }

            // Log step start and update processing log
            $this->logStepStart('analyzing', 'Starting AI analysis');
            $this->updateProcessingRunStep($this->processingLog, 'analyzing_transcript');

            // Get transcript
            $transcriptPath = $this->processingLog->transcript_file_path;
            if (empty($transcriptPath)) {
                throw new \Exception('No transcript path available');
            }

            $transcript = $this->loadTranscriptFromStorage($transcriptPath);
            if (empty($transcript)) {
                throw new \Exception('Transcript file is empty or unreadable');
            }

            Log::info('Processing transcript with AI', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type->value,
                'word_count' => str_word_count($transcript),
            ]);

            // Get existing series for matching
            $existingSeries = $sermonRepository->getExistingSeries();

            // Perform comprehensive AI analysis
            $analysis = $analysisService->analyzeSermon(
                $transcript,
                $existingSeries,
                $this->processingLog->processing_id,
            );

            if (! $analysis->hasValidTranscript()) {
                throw new \Exception('AI analysis produced invalid results');
            }

            // Store in processing log
            $this->processingLog->update(['ai_analysis' => $analysis]);

            // Update sermon - preserve any existing curated content; only fill in missing fields with AI data.
            // The sermon may already have been created by a richer prior pipeline run (upsert enrichment),
            // so we treat non-null sermon fields as authoritative regardless of what ID3 tags say.
            $sermon = $this->processingLog->sermon;
            $updateData = [];

            if (! $sermon instanceof Sermon) {
                throw new \Exception("No sermon found for processing log: {$this->processingLog->processing_id}");
            }

            // Get ID3 metadata from processing log if available (still used as the primary non-AI source)
            $id3Metadata = $this->processingLog->processing_metadata?->id3Metadata;

            // Only update title if neither ID3 nor an existing curated title is present
            $id3Title = $id3Metadata?->title;
            $hasValidId3Title = $id3Title !== null && ! $this->looksLikeFilename($id3Title);

            if (! $hasValidId3Title && $this->looksLikeFilename($sermon->title)) {
                $updateData['title'] = $analysis->title;

                // The placeholder title the pipeline created the record with also
                // produced the slug, so the public URL still reads
                // "evening-sermon-january-16-2022". Nothing has been publicised at
                // that URL yet — SendCompletionNotification runs later in the chain
                // — so carry the better title through to the slug as well.
                $this->applyAiSlug($sermon, $analysis->title, $updateData);
            }

            // Only update series if not already set on the sermon or in ID3 tags
            if ($sermon->series === null && $id3Metadata?->series === null) {
                $updateData['series'] = $analysis->series;
            }

            // Only update reference if not already set on the sermon or in ID3 tags
            if ($sermon->reference === null && $id3Metadata?->reference === null) {
                $updateData['reference'] = $analysis->reference;
                // Clear any stale passage link so the old text is not shown while re-enrichment runs
                $updateData['scripture_passage_id'] = null;
            }

            // Always update summary and points — no human-edit path exists for these fields
            $updateData['summary'] = $analysis->summary;
            $updateData['points'] = $analysis->points;

            Log::info('Updating sermon with AI analysis', [
                'processing_id' => $this->processingLog->processing_id,
                'has_id3_metadata' => $id3Metadata !== null,
                'id3_fields_preserved' => $id3Metadata ? array_keys(array_filter($id3Metadata->toArray())) : [],
                'ai_fields_applied' => array_keys($updateData),
            ]);

            $sermon->update($updateData);

            // Dispatch scripture enrichment asynchronously after reference is persisted
            app(QueueScriptureEnrichment::class)->dispatch($sermon->fresh() ?? $sermon);

            // Update processing log and mark step as complete
            $this->updateProcessingRunStep($this->processingLog, 'ai_analysis_completed');
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
                $this->processingLog->update(['ai_analysis' => $fallbackAnalysis]);

                // Update sermon if it exists. The fallback carries no real analysis —
                // its title is filename-derived and its other fields are empty — so it
                // may only fill gaps, never overwrite what a previous run or an editor
                // already produced. Without this the degraded path silently destroys
                // good data every time AI analysis fails on a re-run.
                $sermon = $this->processingLog->sermon;

                if ($sermon instanceof Sermon) {
                    $updateData = [];

                    // Get ID3 metadata from processing log if available
                    $id3Metadata = $this->processingLog->processing_metadata?->id3Metadata;

                    // Only update fields not set by ID3 tags OR if ID3 title looks like a filename
                    $id3Title = $id3Metadata?->title;
                    $hasValidId3Title = $id3Title !== null
                        && ! $this->looksLikeFilename($id3Title);

                    // A filename-derived title must not displace a curated one, nor a
                    // placeholder when the filename yields nothing better ("17 55").
                    if (! $hasValidId3Title
                        && $this->looksLikeFilename($sermon->title)
                        && ! $this->looksLikeFilename($fallbackAnalysis->title)
                    ) {
                        $updateData['title'] = $fallbackAnalysis->title;
                    }
                    if ($sermon->series === null && $id3Metadata?->series === null) {
                        $updateData['series'] = $fallbackAnalysis->series;
                    }
                    if ($sermon->reference === null && $id3Metadata?->reference === null) {
                        $updateData['reference'] = $fallbackAnalysis->reference;
                        // Clear stale passage link so old text is not shown during re-enrichment
                        $updateData['scripture_passage_id'] = null;
                    }

                    if (blank($sermon->summary)) {
                        $updateData['summary'] = $fallbackAnalysis->summary;
                    }
                    if (blank($sermon->points)) {
                        $updateData['points'] = $fallbackAnalysis->points;
                    }

                    $sermon->update($updateData);

                    // Dispatch scripture enrichment for the fallback reference too
                    app(QueueScriptureEnrichment::class)->dispatch($sermon->fresh() ?? $sermon);
                }

                $this->updateProcessingRunStep($this->processingLog, 'ai_analysis_fallback');
                $this->processingLog->update(['is_degraded_completion' => true]);
            } else {
                $this->markProcessingRunAsFailed($this->processingLog, $e->getMessage(), 'analyzing_transcript');
                $this->logStepFailed('analyzing', $e->getMessage());
                throw $e;
            }
        }
    }

    private function createFallbackAnalysis(): ?SermonAnalysis
    {
        try {
            $fallbackTitle = $this->generateFallbackTitle();
            $transcript = null;

            // Only try to read transcript if path exists
            if ($this->processingLog->transcript_file_path) {
                $transcript = $this->loadTranscriptFromStorage($this->processingLog->transcript_file_path);
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

    private function loadTranscriptFromStorage(string $transcriptPath): ?string
    {
        return app(TranscriptStorageService::class)->readTranscriptFromPath($transcriptPath);
    }

    private function generateFallbackTitle(): string
    {
        $filename = pathinfo($this->processingLog->original_filename, PATHINFO_FILENAME);
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename) ?? $filename;
        $title = preg_replace('/[-_]+/', ' ', $title) ?? $title;
        $title = trim($title);

        if (empty($title) || strlen($title) < 3) {
            return 'Sermon - '.($this->processingLog->created_at ?? now())->format('F j, Y');
        }

        return Str::title($title);
    }

    /**
     * Re-slug a sermon whose placeholder title is being replaced by the AI title.
     *
     * Skipped when the current slug is not the machine-generated form of the
     * current title, which means someone chose it by hand and it must survive.
     *
     * @param  array<string, mixed>  $updateData
     */
    private function applyAiSlug(Sermon $sermon, string $aiTitle, array &$updateData): void
    {
        $slugGenerator = app(SermonSlugGenerator::class);

        if (! $slugGenerator->isDerivedFrom($sermon->slug, $sermon->title)) {
            Log::info('Preserving hand-curated sermon slug despite AI title update', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $sermon->id,
                'slug' => $sermon->slug,
            ]);

            return;
        }

        $slug = $slugGenerator->generate($aiTitle, $sermon->id);

        if ($slug === $sermon->slug) {
            return;
        }

        Log::info('Re-slugging sermon from AI title', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_id' => $sermon->id,
            'old_slug' => $sermon->slug,
            'new_slug' => $slug,
        ]);

        $updateData['slug'] = $slug;
    }

    /**
     * Check if a string looks like a filename rather than a proper title
     */
    private function looksLikeFilename(string $title): bool
    {
        $normalizedTitle = trim($title);

        if (preg_match('/^untitled(?:\s+sermon)?$/i', $normalizedTitle) === 1) {
            return true;
        }

        if (preg_match('/^(morning|evening|other)\s+sermon\s+-\s+/i', $normalizedTitle) === 1) {
            return true;
        }

        if (preg_match('/^sermon\s+-\s+/i', $normalizedTitle) === 1) {
            return true;
        }

        // Check for file extension
        if (preg_match('/\.(mp3|wav|m4a|mp4|mov|avi|mkv|flac|aac|ogg)$/i', $normalizedTitle)) {
            return true;
        }

        // Check for bare time-like titles created from raw filenames, e.g. "18 08" or "10 31 2"
        if (preg_match('/^\d{1,2}(?:\s+\d{2})+(?:\s+\d+)?$/', $normalizedTitle)) {
            return true;
        }

        // Check for date-only titles created from date-named uploads, e.g.
        // "Sunday 3Rd May 2026" (title-cased from "Sunday 3rd May 2026.mp4")
        if (preg_match(
            '/^(?:(?:sun|mon|tues|wednes|thurs|fri|satur)day\s+)?\d{1,2}(?:st|nd|rd|th)?\s+[a-z]+\s+\d{4}$/i',
            $normalizedTitle
        )) {
            return true;
        }

        // Check for hash-like patterns (long strings of random characters)
        // Look for 20+ contiguous lowercase letters/numbers without spaces
        if (preg_match('/[a-z0-9]{20,}/i', $normalizedTitle)) {
            return true;
        }

        // Check for UUID-like patterns
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $normalizedTitle)) {
            return true;
        }

        return false;
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->markProcessingRunAsFailed($this->processingLog, $exception->getMessage(), 'analyzing_transcript_failed');
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Exponential backoff: 2 minutes, 5 minutes, 10 minutes
        return [120, 300, 600];
    }
}
