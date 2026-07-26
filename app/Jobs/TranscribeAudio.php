<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Enums\MediaType;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranscribeAudio extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes for transcription by default

    /**
     * Create a new job instance.
     */
    public function __construct(
        public MediaProcessingLog $processingLog,
    ) {
        $this->timeout = max(60, (int) config('media-processing.transcription.job_timeout', 1800));
    }

    /**
     * Execute the job.
     */
    public function handle(TranscriptionServiceInterface $transcriptionService): void
    {
        $transcriptAttachedToSermon = false;

        try {
            Log::info('Starting audio transcription', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
                Log::info('Transcription job cancelled or log missing', ['processing_id' => $this->processingLog->processing_id]);

                return;
            }

            // Log step start and update processing log
            $this->logStepStart('transcribing', 'Starting audio transcription');
            $this->updateProcessingRunStep($this->processingLog, 'transcribing_audio');

            // Resolve audio path based on processing type
            $audioFilePath = $this->resolveAudioPath();

            Log::info('Transcribing audio file', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type->value,
                'audio_file' => $audioFilePath,
            ]);

            // Transcribe the audio file
            $transcript = $transcriptionService->transcribe(
                $audioFilePath,
                $this->processingLog->processing_id
            );

            if (empty($transcript)) {
                throw new \Exception('Transcription returned empty content');
            }

            // Store the transcript file
            if (! $this->processingLog->sermon_id) {
                throw new \Exception("No sermon ID found in processing log: {$this->processingLog->processing_id}");
            }

            $transcriptPath = $transcriptionService->storeTranscript(
                $this->processingLog->sermon_id,
                $transcript,
            );

            // Update processing log
            $this->processingLog->update(['transcript_file_path' => $transcriptPath]);

            // Update sermon
            $sermon = $this->processingLog->sermon;
            if (! $sermon instanceof Sermon) {
                throw new \Exception("No sermon found for processing log: {$this->processingLog->processing_id}");
            }

            // Only set if the sermon doesn't already have a transcript from a richer prior run
            if ($sermon->transcript_file_path === null) {
                $sermon->update(['transcript_file_path' => $transcriptPath]);
            }

            $transcriptAttachedToSermon = true;

            // Update processing log and mark step as complete
            $this->updateProcessingRunStep($this->processingLog, 'transcription_completed');
            $this->logStepComplete('transcribing', 'Audio transcription completed successfully');

            Log::info('Audio transcription completed successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'transcript_file_path' => $transcriptPath,
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
            ]);
        } catch (NonRetryableTranscriptionException $e) {
            Log::error('Failed to transcribe audio (non-retryable — failing permanently)', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            // Write the terminal state first so it is never lost if cleanup throws.
            $this->markProcessingRunAsFailed($this->processingLog, $e->getMessage(), 'transcribing_audio');
            $this->logStepFailed('transcribing', $e->getMessage());

            // Clean up partial transcript files; swallow errors so the status write above is preserved.
            $sermonIdForCleanup = $this->sermonIdForTranscriptCleanup($transcriptAttachedToSermon);
            if ($sermonIdForCleanup !== null) {
                try {
                    $transcriptionService->cleanupOnFailure($sermonIdForCleanup);
                } catch (\Exception $cleanupException) {
                    Log::warning('Failed to clean up after non-retryable transcription error', [
                        'processing_id' => $this->processingLog->processing_id,
                        'cleanup_error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            // Fail the job immediately — do not re-throw, which would trigger queue retries.
            // Deterministic API errors (401, 413, 400) will not succeed on retry.
            $this->fail($e);
        } catch (\Exception $e) {
            Log::error('Failed to transcribe audio', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any partial transcript files
            $sermonIdForCleanup = $this->sermonIdForTranscriptCleanup($transcriptAttachedToSermon);
            if ($sermonIdForCleanup !== null) {
                $transcriptionService->cleanupOnFailure($sermonIdForCleanup);
            }

            // Update processing log with error and log step failure
            $this->markProcessingRunAsFailed($this->processingLog, $e->getMessage(), 'transcribing_audio');
            $this->logStepFailed('transcribing', $e->getMessage());

            throw $e;
        }
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);

        try {
            $sermonIdForCleanup = $this->sermonIdForTranscriptCleanup();
            if ($sermonIdForCleanup !== null) {
                app(TranscriptionServiceInterface::class)->cleanupOnFailure($sermonIdForCleanup);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup after transcription failure', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id ?? null,
                'cleanup_error' => $e->getMessage(),
            ]);
        }

        $this->markProcessingRunAsFailed($this->processingLog, $exception->getMessage(), 'transcribing_audio_failed');
    }

    /**
     * Resolve the audio file path based on processing type.
     *
     * When an enhanced audio file was produced by EnhanceAudio, prefer it so
     * that Whisper receives the quality-improved version.
     */
    private function resolveAudioPath(): string
    {
        if (! empty($this->processingLog->enhanced_audio_file_path)) {
            return $this->processingLog->enhanced_audio_file_path;
        }

        $path = match ($this->processingLog->processing_type) {
            MediaType::Audio => $this->processingLog->source_file_path,
            MediaType::Video, MediaType::Livestream => $this->processingLog->audio_file_path,
        };

        if (empty($path)) {
            throw new \Exception('No audio file path found');
        }

        return $path;
    }

    private function sermonIdForTranscriptCleanup(bool $transcriptAttachedToSermon = false): ?int
    {
        if ($this->processingLog->sermon_id === null) {
            return null;
        }

        if ($transcriptAttachedToSermon) {
            return null;
        }

        $existingTranscriptPath = Sermon::query()
            ->whereKey($this->processingLog->sermon_id)
            ->value('transcript_file_path');

        if (! empty($existingTranscriptPath)) {
            return null;
        }

        return $this->processingLog->sermon_id;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Exponential backoff: 1 minute, 5 minutes, 15 minutes
        return [60, 300, 900];
    }

    /**
     * Prevent duplicate workers from transcribing the same processing item at once.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        $middleware = [
            (new WithoutOverlapping('transcribe-audio-'.$this->processingLog->processing_id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];

        if (
            config('media-processing.transcription.service') === 'local'
            && (bool) config('media-processing.transcription.local_whisper_serialize', true)
        ) {
            $middleware[] = (new WithoutOverlapping('local-whisper-transcription'))
                ->shared()
                ->releaseAfter(max(5, (int) config('media-processing.transcription.local_whisper_lock_release_after', 60)))
                ->expireAfter($this->timeout + 120);
        }

        return $middleware;
    }
}
