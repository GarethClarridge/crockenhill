<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use App\Services\AudioTranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranscribeAudio implements ShouldQueue
{
  use Queueable, InteractsWithQueue, SerializesModels;

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
    public readonly int $sermonId
  ) {}

  /**
   * Execute the job.
   */
  public function handle(AudioTranscriptionService $transcriptionService): void
  {
    try {
      Log::info('Starting audio transcription', [
        'sermon_id' => $this->sermonId,
      ]);

      // Get the sermon record
      $sermon = Sermon::find($this->sermonId);
      if (!$sermon) {
        throw new \Exception("Sermon not found with ID: {$this->sermonId}");
      }

      // Get the processing log
      $processingLog = $sermon->processingLogs()->latest()->first();
      if (!$processingLog) {
        throw new \Exception("Processing log not found for sermon ID: {$this->sermonId}");
      }

      // Update processing log to indicate transcription started
      $processingLog->updateStep('transcribing_audio');

      // Verify audio file exists
      if (!$sermon->filename) {
        throw new \Exception("No audio file path found for sermon ID: {$this->sermonId}");
      }

      Log::info('Transcribing audio file', [
        'sermon_id' => $this->sermonId,
        'audio_file' => $sermon->filename,
      ]);

      // Transcribe the audio file
      $transcript = $transcriptionService->transcribe($sermon->filename);

      if (empty($transcript)) {
        throw new \Exception('Transcription returned empty content');
      }

      // Store the transcript file
      $transcriptPath = $transcriptionService->storeTranscript($this->sermonId, $transcript);

      // Update sermon record with transcript path
      $sermon->update([
        'transcript_path' => $transcriptPath,
      ]);

      // Update processing log
      $processingLog->updateStep('transcription_completed');

      Log::info('Audio transcription completed successfully', [
        'sermon_id' => $this->sermonId,
        'transcript_path' => $transcriptPath,
        'transcript_length' => strlen($transcript),
        'word_count' => str_word_count($transcript),
      ]);

      // Dispatch the next job in the chain
      ProcessTranscriptWithAI::dispatch($this->sermonId)
        ->onQueue(config('sermon-processing.processing.queue', 'default'));
    } catch (\Exception $e) {
      Log::error('Failed to transcribe audio', [
        'sermon_id' => $this->sermonId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      // Clean up any partial transcript files
      if (isset($transcriptionService)) {
        $transcriptionService->cleanupOnFailure($this->sermonId);
      }

      // Update processing log with error
      if (isset($processingLog)) {
        $processingLog->markAsFailed($e->getMessage(), 'transcribing_audio');
      }

      throw $e;
    }
  }

  /**
   * Handle a job failure.
   */
  public function failed(\Throwable $exception): void
  {
    Log::error('TranscribeAudio job failed permanently', [
      'sermon_id' => $this->sermonId,
      'error' => $exception->getMessage(),
      'attempts' => $this->attempts(),
    ]);

    // Clean up any partial files
    try {
      $transcriptionService = app(AudioTranscriptionService::class);
      $transcriptionService->cleanupOnFailure($this->sermonId);
    } catch (\Exception $e) {
      Log::warning('Failed to cleanup after transcription failure', [
        'sermon_id' => $this->sermonId,
        'cleanup_error' => $e->getMessage(),
      ]);
    }

    // Mark processing as failed
    $sermon = Sermon::find($this->sermonId);
    if ($sermon) {
      $processingLog = $sermon->processingLogs()->latest()->first();
      if ($processingLog) {
        $processingLog->markAsFailed($exception->getMessage(), 'transcribing_audio_failed');
      }
    }
  }

  /**
   * Calculate the number of seconds to wait before retrying the job.
   */
  public function backoff(): array
  {
    // Exponential backoff: 1 minute, 5 minutes, 15 minutes
    return [60, 300, 900];
  }
}
