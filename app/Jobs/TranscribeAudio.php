<?php

namespace App\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Enums\MediaType;
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
    public int $timeout = 1800; // 30 minutes for transcription

    /**
     * Create a new job instance.
     */
    public function __construct(
        public MediaProcessingLog $processingLog,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TranscriptionServiceInterface $transcriptionService): void
    {
        try {
            Log::info('Starting audio transcription', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            // Initialize step logging
            $this->initializeStepLogging($this->processingLog->processing_id);

            // Check if processing has been cancelled
            if ($this->isCancelled()) {
                Log::info('Transcription job cancelled', ['processing_id' => $this->processingLog->processing_id]);

                return;
            }

            // Log step start and update processing log
            $this->logStepStart('transcribing', 'Starting audio transcription');
            $this->processingLog->updateStep('transcribing_audio');

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
                $transcript
            );

            // Update processing log
            $this->processingLog->update(['transcript_file_path' => $transcriptPath]);

            // Update sermon
            $sermon = $this->processingLog->sermon;
            if (! $sermon instanceof Sermon) {
                throw new \Exception("No sermon found for processing log: {$this->processingLog->processing_id}");
            }

            $sermon->update(['transcript_file_path' => $transcriptPath]);

            // Update processing log and mark step as complete
            $this->processingLog->updateStep('transcription_completed');
            $this->logStepComplete('transcribing', 'Audio transcription completed successfully');

            Log::info('Audio transcription completed successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'transcript_file_path' => $transcriptPath,
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to transcribe audio', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any partial transcript files
            if ($this->processingLog->sermon_id) {
                $transcriptionService->cleanupOnFailure($this->processingLog->sermon_id);
            }

            // Update processing log with error and log step failure
            $this->processingLog->markAsFailed($e->getMessage(), 'transcribing_audio');
            $this->logStepFailed('transcribing', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TranscribeAudio job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_id' => $this->processingLog->sermon_id ?? null,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Clean up any partial files
        try {
            if ($this->processingLog->sermon_id) {
                app(TranscriptionServiceInterface::class)->cleanupOnFailure($this->processingLog->sermon_id);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup after transcription failure', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id ?? null,
                'cleanup_error' => $e->getMessage(),
            ]);
        }

        // Mark processing as failed
        $this->processingLog->markAsFailed($exception->getMessage(), 'transcribing_audio_failed');
    }

    /**
     * Resolve the audio file path based on processing type
     */
    private function resolveAudioPath(): string
    {
        $path = match ($this->processingLog->processing_type) {
            MediaType::Audio => $this->processingLog->source_file_path,
            MediaType::Video, MediaType::Livestream => $this->processingLog->audio_file_path,
        };

        if (empty($path)) {
            throw new \Exception('No audio file path found');
        }

        return $path;
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
        return [
            (new WithoutOverlapping('transcribe-audio-'.$this->processingLog->processing_id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }
}
