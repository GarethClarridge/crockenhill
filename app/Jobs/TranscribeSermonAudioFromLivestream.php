<?php

namespace App\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Models\LivestreamProcessingLog;
use App\Models\Sermon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscribeSermonAudioFromLivestream implements ShouldQueue
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
        private LivestreamProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TranscriptionServiceInterface $transcriptionService): void
    {
        try {
            Log::info('Starting livestream audio transcription', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id,
            ]);

            // Check if processing has been cancelled
            if ($this->isProcessingCancelled()) {
                Log::info('Livestream transcription job cancelled', ['processing_id' => $this->processingLog->processing_id]);
                return;
            }

            // Update processing log status
            $this->processingLog->update(['status' => 'transcription']);

            // Validate sermon exists
            if (!$this->processingLog->sermon_id) {
                throw new \Exception("No sermon ID found in processing log: {$this->processingLog->processing_id}");
            }

            $sermon = Sermon::find($this->processingLog->sermon_id);
            if (!$sermon) {
                throw new \Exception("Sermon not found: {$this->processingLog->sermon_id}");
            }

            // Get audio file path from processing log
            $audioFilePath = $this->processingLog->sermon_audio_path;
            if (!$audioFilePath) {
                throw new \Exception("No audio file path found in processing log: {$this->processingLog->processing_id}");
            }

            // Validate audio file exists
            $sermonDisk = config('livestream-processing.sermon_disk', 'public');
            if (!Storage::disk($sermonDisk)->exists($audioFilePath)) {
                throw new \Exception("Audio file not found: {$audioFilePath}");
            }

            Log::info('Transcribing livestream audio file', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id,
                'audio_file' => $audioFilePath,
                'disk' => $sermonDisk,
            ]);

            // Get absolute path for transcription service
            $absoluteAudioPath = Storage::disk($sermonDisk)->path($audioFilePath);

            // Transcribe the audio file
            $transcript = $transcriptionService->transcribe($absoluteAudioPath);

            if (empty($transcript)) {
                throw new \Exception('Transcription returned empty content');
            }

            // Store the transcript file
            $transcriptPath = $transcriptionService->storeTranscript($sermon->id, $transcript);

            // Update processing log with transcript path
            $this->processingLog->update([
                'processing_metadata' => array_merge(
                    $this->processingLog->processing_metadata ?? [],
                    [
                        'transcript_path' => $transcriptPath,
                        'transcript_length' => strlen($transcript),
                        'word_count' => str_word_count($transcript),
                        'transcription_completed_at' => now()->toISOString(),
                    ]
                ),
            ]);

            // Update sermon record with transcript path
            $sermon->update(['transcript_path' => $transcriptPath]);

            // Update processing log status
            $this->processingLog->update(['status' => 'sermon_submitted']);

            Log::info('Livestream audio transcription completed successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $sermon->id,
                'transcript_path' => $transcriptPath,
                'transcript_length' => strlen($transcript),
                'word_count' => str_word_count($transcript),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to transcribe livestream audio', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any partial transcript files
            if ($this->processingLog->sermon_id) {
                try {
                    $transcriptionService->cleanupOnFailure($this->processingLog->sermon_id);
                } catch (\Exception $cleanupError) {
                    Log::warning('Failed to cleanup after transcription failure', [
                        'processing_id' => $this->processingLog->processing_id,
                        'sermon_id' => $this->processingLog->sermon_id,
                        'cleanup_error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            // Update processing log with error
            $this->processingLog->update([
                'status' => 'failed',
                'error_message' => 'Livestream transcription failed: ' . $e->getMessage(),
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
        Log::error('TranscribeSermonAudioFromLivestream job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_id' => $this->processingLog->sermon_id ?? null,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Clean up any partial files
        try {
            $transcriptionService = app(TranscriptionServiceInterface::class);
            if ($this->processingLog->sermon_id) {
                $transcriptionService->cleanupOnFailure($this->processingLog->sermon_id);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup after permanent transcription failure', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id ?? null,
                'cleanup_error' => $e->getMessage(),
            ]);
        }

        // Mark processing as failed
        $this->processingLog->markAsFailed(
            'Livestream transcription failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
        );
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Exponential backoff: 1 minute, 5 minutes, 15 minutes
        return [60, 300, 900];
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
}