<?php

namespace App\Jobs;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Models\MediaProcessingLog;
use App\Repositories\SermonRepository;
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

            // Only update title if not set by ID3 tags OR if ID3 title looks like a filename
            $hasValidId3Title = $id3Metadata
                && ! empty($id3Metadata['title'])
                && ! $this->looksLikeFilename($id3Metadata['title']);

            if (! $hasValidId3Title) {
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

                    // Only update fields not set by ID3 tags OR if ID3 title looks like a filename
                    $hasValidId3Title = $id3Metadata
                        && ! empty($id3Metadata['title'])
                        && ! $this->looksLikeFilename($id3Metadata['title']);

                    if (! $hasValidId3Title) {
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
        $path = trim($transcriptPath);
        if ($path === '') {
            return null;
        }

        foreach ($this->getTranscriptReadDisks() as $disk) {
            try {
                $storage = Storage::disk($disk);

                if (! $storage->exists($path)) {
                    continue;
                }

                $transcript = $storage->get($path);

                return is_string($transcript) ? $transcript : null;
            } catch (\Exception $e) {
                Log::warning('Failed to read transcript during AI processing', [
                    'processing_id' => $this->processingLog->processing_id,
                    'disk' => $disk,
                    'transcript_file_path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function getTranscriptReadDisks(): array
    {
        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', '');
        $sermonDisk = (string) config('media-processing.storage.sermon_disk', '');
        $defaultDisk = (string) config('filesystems.default', '');

        $diskCandidates = [
            $transcriptDisk,
            $sermonDisk,
            $defaultDisk,
            'local',
            'public',
            'do_spaces',
        ];

        return array_values(array_filter(array_unique($diskCandidates), fn (string $disk): bool => $disk !== ''));
    }

    private function generateFallbackTitle(): string
    {
        $filename = pathinfo($this->processingLog->original_filename, PATHINFO_FILENAME);
        $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename) ?? $filename;
        $title = preg_replace('/[-_]+/', ' ', $title) ?? $title;
        $title = trim($title);

        if (empty($title) || strlen($title) < 3) {
            return 'Sermon - '.$this->processingLog->created_at->format('F j, Y');
        }

        return Str::title($title);
    }

    /**
     * Check if a string looks like a filename rather than a proper title
     */
    private function looksLikeFilename(string $title): bool
    {
        // Check for file extension
        if (preg_match('/\.(mp3|wav|m4a|mp4|mov|avi|mkv|flac|aac|ogg)$/i', $title)) {
            return true;
        }

        // Check for hash-like patterns (long strings of random characters)
        // Look for 20+ contiguous lowercase letters/numbers without spaces
        if (preg_match('/[a-z0-9]{20,}/i', $title)) {
            return true;
        }

        // Check for UUID-like patterns
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $title)) {
            return true;
        }

        return false;
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
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Exponential backoff: 2 minutes, 5 minutes, 10 minutes
        return [120, 300, 600];
    }
}
