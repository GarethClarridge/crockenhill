<?php

namespace App\Services;

use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\TranscribeAudio;
use App\Jobs\UpdateSermonRecord;
use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SermonProcessingService
{
  protected SermonProcessingLogger $logger;

  public function __construct(SermonProcessingLogger $logger)
  {
    $this->logger = $logger;
  }

  /**
   * Process a sermon audio file through the complete automation pipeline
   */
  public function processSermon(UploadedFile $file): ProcessingResult
  {
    try {
      Log::info('Starting sermon processing', [
        'original_filename' => $file->getClientOriginalName(),
        'file_size' => $file->getSize(),
        'mime_type' => $file->getMimeType(),
      ]);

      // Generate unique processing ID
      $processingId = $this->generateProcessingId();

      // Validate the uploaded file
      $this->validateAudioFile($file);

      // Extract metadata from the file
      $metadata = SermonMetadata::fromUploadedFile($file);

      // Store the audio file securely
      $storedFilePath = $this->storeAudioFile($file, $metadata);

      // Create initial processing log
      $processingLog = $this->createProcessingLog($processingId, $metadata->originalName);

      // Dispatch the job chain for processing
      $this->dispatchProcessingChain($processingId, $metadata, $storedFilePath);

      Log::info('Sermon processing initiated successfully', [
        'processing_id' => $processingId,
        'stored_file_path' => $storedFilePath,
      ]);

      return ProcessingResult::success(
        processingId: $processingId,
        message: 'Sermon processing initiated successfully',
        statusUrl: route('api.sermons.processing.status', ['processingId' => $processingId])
      );
    } catch (\Exception $e) {
      Log::error('Failed to initiate sermon processing', [
        'original_filename' => $file->getClientOriginalName(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      // Clean up any partial files
      if (isset($storedFilePath)) {
        $this->cleanupFile($storedFilePath);
      }

      return ProcessingResult::error(
        message: 'Failed to initiate sermon processing: ' . $e->getMessage(),
        errorCode: 'PROCESSING_INITIATION_FAILED'
      );
    }
  }

  /**
   * Get the current processing status for a given processing ID
   */
  public function getProcessingStatus(string $processingId): ProcessingStatusResult
  {
    try {
      $processingLog = SermonProcessingLog::where('processing_id', $processingId)->first();

      if (!$processingLog) {
        return ProcessingStatusResult::notFound();
      }

      $sermon = $processingLog->sermon;

      return ProcessingStatusResult::found(
        processingId: $processingId,
        status: $processingLog->status,
        currentStep: $processingLog->current_step,
        errorMessage: $processingLog->error_message,
        sermonId: $processingLog->sermon_id,
        sermonTitle: $sermon?->title,
        sermonSlug: $sermon?->slug,
        createdAt: $processingLog->created_at,
        updatedAt: $processingLog->updated_at
      );
    } catch (\Exception $e) {
      Log::error('Failed to retrieve processing status', [
        'processing_id' => $processingId,
        'error' => $e->getMessage(),
      ]);

      return ProcessingStatusResult::error(
        message: 'Failed to retrieve processing status: ' . $e->getMessage()
      );
    }
  }

  /**
   * Get processing statistics and recent activity
   */
  public function getProcessingStatistics(): array
  {
    try {
      $stats = [
        'total_processed' => SermonProcessingLog::count(),
        'completed' => SermonProcessingLog::completed()->count(),
        'failed' => SermonProcessingLog::failed()->count(),
        'in_progress' => SermonProcessingLog::processing()->count(),
        'pending' => SermonProcessingLog::pending()->count(),
        'recent_activity' => SermonProcessingLog::recent()
          ->with('sermon')
          ->orderBy('created_at', 'desc')
          ->limit(10)
          ->get()
          ->map(function ($log) {
            return [
              'processing_id' => $log->processing_id,
              'status' => $log->status->label(),
              'current_step' => $log->current_step,
              'sermon_title' => $log->sermon?->title,
              'created_at' => $log->created_at->diffForHumans(),
              'has_errors' => !empty($log->error_message),
            ];
          }),
      ];

      return $stats;
    } catch (\Exception $e) {
      Log::error('Failed to retrieve processing statistics', [
        'error' => $e->getMessage(),
      ]);

      return [
        'error' => 'Failed to retrieve statistics',
        'total_processed' => 0,
        'completed' => 0,
        'failed' => 0,
        'in_progress' => 0,
        'pending' => 0,
        'recent_activity' => [],
      ];
    }
  }

  /**
   * Generate a unique processing ID
   */
  private function generateProcessingId(): string
  {
    return (string) Str::uuid();
  }

  /**
   * Validate the uploaded audio file
   */
  private function validateAudioFile(UploadedFile $file): void
  {
    // Check file size
    $maxSize = config('sermon-processing.processing.max_file_size', 100 * 1024 * 1024);
    if ($file->getSize() > $maxSize) {
      $maxSizeMB = round($maxSize / (1024 * 1024));
      throw new \InvalidArgumentException("File size exceeds maximum limit of {$maxSizeMB}MB");
    }

    // Check MIME type
    $allowedMimeTypes = config('sermon-processing.processing.allowed_mime_types', [
      'audio/mpeg',
      'audio/mp3',
      'audio/wav',
      'audio/x-wav',
      'audio/mp4',
      'audio/m4a',
    ]);

    if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
      throw new \InvalidArgumentException('Invalid file type. Only audio files are allowed.');
    }

    // Check file extension
    $allowedExtensions = config('sermon-processing.processing.allowed_extensions', ['mp3', 'wav', 'm4a', 'mp4']);
    $extension = strtolower($file->getClientOriginalExtension());

    if (!in_array($extension, $allowedExtensions)) {
      throw new \InvalidArgumentException('Invalid file extension. Allowed: ' . implode(', ', $allowedExtensions));
    }

    // Basic file integrity check
    if (!$file->isValid()) {
      throw new \InvalidArgumentException('Uploaded file is corrupted or invalid');
    }
  }

  /**
   * Store the audio file securely
   */
  private function storeAudioFile(UploadedFile $file, SermonMetadata $metadata): string
  {
    // Get storage configuration
    $disk = config('sermon-processing.storage.disk', 'public');
    $basePath = config('sermon-processing.storage.audio_path', 'sermons');

    // Create directory structure: sermons/YYYY/MM/
    $directory = $basePath . '/' . $metadata->date->format('Y/m');

    // Generate unique filename while preserving extension
    $extension = $file->getClientOriginalExtension();
    $filename = Str::uuid() . '.' . $extension;

    // Store the file
    $path = $file->storeAs($directory, $filename, $disk);

    if (!$path) {
      throw new \RuntimeException('Failed to store audio file');
    }

    return $path;
  }

  /**
   * Create initial processing log entry
   */
  private function createProcessingLog(string $processingId, string $originalFilename): SermonProcessingLog
  {
    return SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => $originalFilename,
      'status' => ProcessingStatus::PENDING,
      'current_step' => 'initiated',
    ]);
  }

  /**
   * Dispatch the job chain for processing
   */
  private function dispatchProcessingChain(string $processingId, SermonMetadata $metadata, string $storedFilePath): void
  {
    // For Laravel job chaining, we need to create a custom chain that can pass data between jobs
    // We'll use a simpler approach: dispatch the first job and let each job dispatch the next one

    Log::info('Dispatching initial job for processing chain', [
      'processing_id' => $processingId,
    ]);

    // Dispatch the first job in the chain
    CreateSermonRecord::dispatch($processingId, $metadata, $storedFilePath)
      ->onQueue(config('sermon-processing.processing.queue', 'default'));
  }

  /**
   * Retry failed processing for a given processing ID
   */
  public function retryProcessing(string $processingId): ProcessingResult
  {
    try {
      Log::info('Attempting to retry failed processing', [
        'processing_id' => $processingId,
      ]);

      $processingLog = SermonProcessingLog::where('processing_id', $processingId)->first();

      if (!$processingLog) {
        return ProcessingResult::error(
          message: 'Processing log not found',
          errorCode: 'PROCESSING_LOG_NOT_FOUND'
        );
      }

      if (!$processingLog->isFailed()) {
        return ProcessingResult::error(
          message: 'Processing is not in failed state',
          errorCode: 'PROCESSING_NOT_FAILED'
        );
      }

      // Reset processing log to pending state
      $processingLog->update([
        'status' => ProcessingStatus::PENDING,
        'current_step' => 'retry_initiated',
        'error_message' => null,
      ]);

      // Determine where to restart the processing chain based on current step
      $this->restartProcessingChain($processingLog);

      Log::info('Processing retry initiated successfully', [
        'processing_id' => $processingId,
      ]);

      return ProcessingResult::success(
        processingId: $processingId,
        message: 'Processing retry initiated successfully',
        statusUrl: route('api.sermons.processing.status', ['processingId' => $processingId])
      );
    } catch (\Exception $e) {
      Log::error('Failed to retry processing', [
        'processing_id' => $processingId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      return ProcessingResult::error(
        message: 'Failed to retry processing: ' . $e->getMessage(),
        errorCode: 'RETRY_FAILED'
      );
    }
  }

  /**
   * Get failed processing logs that may need manual review
   */
  public function getFailedProcessingLogs(int $limit = 50): array
  {
    try {
      $failedLogs = SermonProcessingLog::failed()
        ->with('sermon')
        ->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get();

      return $failedLogs->map(function ($log) {
        return [
          'processing_id' => $log->processing_id,
          'original_filename' => $log->original_filename,
          'current_step' => $log->current_step,
          'error_message' => $log->error_message,
          'created_at' => $log->created_at->toISOString(),
          'updated_at' => $log->updated_at->toISOString(),
          'sermon' => $log->sermon ? [
            'id' => $log->sermon->id,
            'title' => $log->sermon->title,
            'slug' => $log->sermon->slug,
          ] : null,
          'can_retry' => $this->canRetryProcessing($log),
          'requires_manual_review' => $this->requiresManualReview($log),
        ];
      })->toArray();
    } catch (\Exception $e) {
      Log::error('Failed to retrieve failed processing logs', [
        'error' => $e->getMessage(),
      ]);

      return [];
    }
  }

  /**
   * Mark a processing log for manual review
   */
  public function markForManualReview(string $processingId, string $reviewNote = ''): bool
  {
    try {
      $processingLog = SermonProcessingLog::where('processing_id', $processingId)->first();

      if (!$processingLog) {
        return false;
      }

      $processingLog->update([
        'current_step' => 'manual_review_required',
        'error_message' => ($processingLog->error_message ?? '') .
          ($reviewNote ? "\n\nManual Review Note: {$reviewNote}" : ''),
      ]);

      Log::info('Processing marked for manual review', [
        'processing_id' => $processingId,
        'review_note' => $reviewNote,
      ]);

      return true;
    } catch (\Exception $e) {
      Log::error('Failed to mark processing for manual review', [
        'processing_id' => $processingId,
        'error' => $e->getMessage(),
      ]);

      return false;
    }
  }

  /**
   * Apply graceful degradation by creating a basic sermon record
   */
  public function applyGracefulDegradation(string $processingId): ProcessingResult
  {
    try {
      Log::info('Applying graceful degradation', [
        'processing_id' => $processingId,
      ]);

      $processingLog = SermonProcessingLog::where('processing_id', $processingId)->first();

      if (!$processingLog) {
        return ProcessingResult::error(
          message: 'Processing log not found',
          errorCode: 'PROCESSING_LOG_NOT_FOUND'
        );
      }

      $sermon = $processingLog->sermon;

      if (!$sermon) {
        return ProcessingResult::error(
          message: 'No sermon record found for graceful degradation',
          errorCode: 'NO_SERMON_RECORD'
        );
      }

      // Apply basic fallback values
      $fallbackData = $this->generateFallbackData($sermon, $processingLog);

      $sermon->update($fallbackData);

      // Mark processing as completed with degradation
      $processingLog->update([
        'status' => ProcessingStatus::COMPLETED,
        'current_step' => 'completed_with_degradation',
        'error_message' => ($processingLog->error_message ?? '') .
          "\n\nGraceful degradation applied with fallback values.",
      ]);

      Log::info('Graceful degradation applied successfully', [
        'processing_id' => $processingId,
        'sermon_id' => $sermon->id,
        'fallback_title' => $fallbackData['title'],
      ]);

      return ProcessingResult::success(
        processingId: $processingId,
        message: 'Graceful degradation applied successfully',
        details: [
          'sermon_id' => $sermon->id,
          'sermon_title' => $sermon->title,
          'sermon_url' => url("/christ/sermons/{$sermon->slug}"),
        ]
      );
    } catch (\Exception $e) {
      Log::error('Failed to apply graceful degradation', [
        'processing_id' => $processingId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      return ProcessingResult::error(
        message: 'Failed to apply graceful degradation: ' . $e->getMessage(),
        errorCode: 'DEGRADATION_FAILED'
      );
    }
  }

  /**
   * Restart processing chain from the appropriate point
   */
  private function restartProcessingChain(SermonProcessingLog $processingLog): void
  {
    $currentStep = $processingLog->current_step;
    $sermonId = $processingLog->sermon_id;

    Log::info('Restarting processing chain', [
      'processing_id' => $processingLog->processing_id,
      'current_step' => $currentStep,
      'sermon_id' => $sermonId,
    ]);

    // Determine which job to restart based on the failed step
    switch ($currentStep) {
      case 'creating_sermon_record':
      case 'creating_sermon_record_failed':
        // Restart from the beginning - but we need the original metadata
        // For now, we'll mark for manual review since we can't easily recreate the metadata
        $this->markForManualReview($processingLog->processing_id, 'Failed during sermon record creation - requires manual intervention');
        break;

      case 'transcribing_audio':
      case 'transcribing_audio_failed':
        if ($sermonId) {
          TranscribeAudio::dispatch($sermonId)
            ->onQueue(config('sermon-processing.processing.queue', 'default'));
        }
        break;

      case 'analyzing_transcript':
      case 'analyzing_transcript_failed':
        if ($sermonId) {
          ProcessTranscriptWithAI::dispatch($sermonId)
            ->onQueue(config('sermon-processing.processing.queue', 'default'));
        }
        break;

      case 'updating_sermon_record':
      case 'updating_sermon_record_failed':
        if ($sermonId) {
          UpdateSermonRecord::dispatch($sermonId)
            ->onQueue(config('sermon-processing.processing.queue', 'default'));
        }
        break;

      case 'sending_notification':
      case 'notification_failed':
        if ($sermonId) {
          SendCompletionNotification::dispatch($sermonId)
            ->onQueue(config('sermon-processing.processing.queue', 'default'));
        }
        break;

      default:
        // Unknown step - mark for manual review
        $this->markForManualReview($processingLog->processing_id, "Unknown processing step: {$currentStep}");
        break;
    }
  }

  /**
   * Check if processing can be retried automatically
   */
  private function canRetryProcessing(SermonProcessingLog $processingLog): bool
  {
    // Don't retry if it's been marked for manual review
    if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
      return false;
    }

    // Don't retry if it's too old (more than 7 days)
    if ($processingLog->created_at->diffInDays(now()) > 7) {
      return false;
    }

    // Don't retry certain critical failures
    $criticalFailures = [
      'file_not_found',
      'invalid_file_format',
      'storage_failure',
    ];

    foreach ($criticalFailures as $failure) {
      if (str_contains(strtolower($processingLog->error_message ?? ''), $failure)) {
        return false;
      }
    }

    return true;
  }

  /**
   * Check if processing requires manual review
   */
  private function requiresManualReview(SermonProcessingLog $processingLog): bool
  {
    // Already marked for manual review
    if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
      return true;
    }

    // Multiple failures in critical steps
    $criticalSteps = [
      'creating_sermon_record',
      'transcribing_audio',
    ];

    if (in_array($processingLog->current_step, $criticalSteps)) {
      return true;
    }

    // Check for specific error patterns that require manual intervention
    $manualReviewPatterns = [
      'file not found',
      'invalid audio format',
      'transcription service unavailable',
      'storage failure',
      'database constraint violation',
    ];

    $errorMessage = strtolower($processingLog->error_message ?? '');
    foreach ($manualReviewPatterns as $pattern) {
      if (str_contains($errorMessage, $pattern)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Generate fallback data for graceful degradation
   */
  private function generateFallbackData(Sermon $sermon, SermonProcessingLog $processingLog): array
  {
    // Generate basic title if current title is generic
    $title = $sermon->title;
    if (
      empty($title) ||
      str_contains(strtolower($title), 'untitled') ||
      str_contains(strtolower($title), 'sermon -')
    ) {
      $title = $this->generateFallbackTitle($sermon, $processingLog);
    }

    // Generate unique slug
    $slug = $this->generateUniqueSlug($title, $sermon->id);

    return [
      'title' => $title,
      'slug' => $slug,
      'series' => null, // No series identification in fallback
      'reference' => null, // No Bible passage extraction in fallback
      'points' => ['Main Message'], // Simple fallback points
    ];
  }

  /**
   * Generate a fallback title for graceful degradation
   */
  private function generateFallbackTitle(Sermon $sermon, SermonProcessingLog $processingLog): string
  {
    // Try to extract from original filename
    if (!empty($processingLog->original_filename)) {
      $filename = pathinfo($processingLog->original_filename, PATHINFO_FILENAME);
      $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
      $title = preg_replace('/[-_]+/', ' ', $title);
      $title = trim($title);

      if (!empty($title) && strlen($title) > 3) {
        return Str::title($title);
      }
    }

    // Fallback to date-based title
    $service = $sermon->service ? $sermon->service->value : '';
    return "Sermon - {$sermon->date->format('F j, Y')} {$service}";
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
      $slug = $baseSlug . '-' . $counter;
      $counter++;
    }

    return $slug;
  }

  /**
   * Get detailed processing logs for troubleshooting
   */
  public function getDetailedProcessingLogs(string $processingId): array
  {
    try {
      $processingLog = SermonProcessingLog::where('processing_id', $processingId)
        ->with('sermon')
        ->first();

      if (!$processingLog) {
        return [
          'found' => false,
          'message' => 'Processing log not found',
        ];
      }

      // Get related log entries from Laravel logs
      $logEntries = $this->extractLogEntries($processingId);

      return [
        'found' => true,
        'processing_log' => [
          'processing_id' => $processingLog->processing_id,
          'original_filename' => $processingLog->original_filename,
          'status' => $processingLog->status->value,
          'status_label' => $processingLog->status->label(),
          'current_step' => $processingLog->current_step,
          'error_message' => $processingLog->error_message,
          'created_at' => $processingLog->created_at->toISOString(),
          'updated_at' => $processingLog->updated_at->toISOString(),
          'duration' => $processingLog->created_at->diffForHumans($processingLog->updated_at, true),
        ],
        'sermon' => $processingLog->sermon ? [
          'id' => $processingLog->sermon->id,
          'title' => $processingLog->sermon->title,
          'slug' => $processingLog->sermon->slug,
          'date' => $processingLog->sermon->date->toDateString(),
          'service' => $processingLog->sermon->service?->value,
          'preacher' => $processingLog->sermon->preacher,
          'series' => $processingLog->sermon->series,
          'reference' => $processingLog->sermon->reference,
          'has_transcript' => $processingLog->sermon->hasTranscript(),
        ] : null,
        'log_entries' => $logEntries,
        'troubleshooting' => $this->generateTroubleshootingInfo($processingLog),
      ];
    } catch (\Exception $e) {
      Log::error('Failed to retrieve detailed processing logs', [
        'processing_id' => $processingId,
        'error' => $e->getMessage(),
      ]);

      return [
        'found' => false,
        'message' => 'Failed to retrieve detailed logs: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Generate health check information for the processing system
   */
  public function getSystemHealth(): array
  {
    try {
      $health = [
        'overall_status' => 'healthy',
        'checks' => [],
        'statistics' => $this->getProcessingStatistics(),
        'timestamp' => now()->toISOString(),
      ];

      // Check queue health
      $queueHealth = $this->checkQueueHealth();
      $health['checks']['queue'] = $queueHealth;

      // Check storage health
      $storageHealth = $this->checkStorageHealth();
      $health['checks']['storage'] = $storageHealth;

      // Check recent processing success rate
      $processingHealth = $this->checkProcessingHealth();
      $health['checks']['processing'] = $processingHealth;

      // Determine overall status
      $allHealthy = collect($health['checks'])->every(fn($check) => $check['status'] === 'healthy');
      $health['overall_status'] = $allHealthy ? 'healthy' : 'degraded';

      return $health;
    } catch (\Exception $e) {
      Log::error('Failed to generate system health check', [
        'error' => $e->getMessage(),
      ]);

      return [
        'overall_status' => 'error',
        'message' => 'Failed to generate health check: ' . $e->getMessage(),
        'timestamp' => now()->toISOString(),
      ];
    }
  }

  /**
   * Extract log entries related to a processing ID
   */
  private function extractLogEntries(string $processingId): array
  {
    // This is a simplified implementation
    // In a real system, you might want to use a log aggregation service
    // or parse actual log files

    try {
      // For now, return a placeholder structure
      // In production, this could integrate with services like ELK stack, Fluentd, etc.
      return [
        'note' => 'Log extraction not implemented - integrate with log aggregation service',
        'suggestion' => 'Check Laravel logs for entries containing processing_id: ' . $processingId,
      ];
    } catch (\Exception $e) {
      return [
        'error' => 'Failed to extract log entries: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Generate troubleshooting information
   */
  private function generateTroubleshootingInfo(SermonProcessingLog $processingLog): array
  {
    $troubleshooting = [
      'common_issues' => [],
      'suggested_actions' => [],
      'recovery_options' => [],
    ];

    $currentStep = $processingLog->current_step;
    $errorMessage = strtolower($processingLog->error_message ?? '');

    // Generate step-specific troubleshooting
    switch ($currentStep) {
      case 'creating_sermon_record':
      case 'creating_sermon_record_failed':
        $troubleshooting['common_issues'][] = 'Database connection issues';
        $troubleshooting['common_issues'][] = 'Invalid metadata extraction';
        $troubleshooting['suggested_actions'][] = 'Check database connectivity';
        $troubleshooting['suggested_actions'][] = 'Verify filename format matches expected patterns';
        $troubleshooting['recovery_options'][] = 'Manual sermon record creation';
        break;

      case 'transcribing_audio':
      case 'transcribing_audio_failed':
        $troubleshooting['common_issues'][] = 'Audio file corruption or invalid format';
        $troubleshooting['common_issues'][] = 'OpenAI API rate limits or service unavailable';
        $troubleshooting['common_issues'][] = 'File too large for transcription service';
        $troubleshooting['suggested_actions'][] = 'Verify audio file integrity';
        $troubleshooting['suggested_actions'][] = 'Check OpenAI API status and rate limits';
        $troubleshooting['suggested_actions'][] = 'Consider file compression or chunking';
        $troubleshooting['recovery_options'][] = 'Manual transcription upload';
        $troubleshooting['recovery_options'][] = 'Retry with different transcription service';
        break;

      case 'analyzing_transcript':
      case 'analyzing_transcript_failed':
        $troubleshooting['common_issues'][] = 'AI service rate limits or downtime';
        $troubleshooting['common_issues'][] = 'Transcript content too large or complex';
        $troubleshooting['common_issues'][] = 'Invalid or empty transcript content';
        $troubleshooting['suggested_actions'][] = 'Check AI service status and quotas';
        $troubleshooting['suggested_actions'][] = 'Verify transcript content quality';
        $troubleshooting['recovery_options'][] = 'Apply graceful degradation with fallback values';
        $troubleshooting['recovery_options'][] = 'Manual content analysis and entry';
        break;

      case 'updating_sermon_record':
      case 'updating_sermon_record_failed':
        $troubleshooting['common_issues'][] = 'Database constraint violations';
        $troubleshooting['common_issues'][] = 'Slug generation conflicts';
        $troubleshooting['suggested_actions'][] = 'Check for duplicate slugs or titles';
        $troubleshooting['suggested_actions'][] = 'Verify database schema integrity';
        $troubleshooting['recovery_options'][] = 'Manual record update';
        break;

      case 'sending_notification':
      case 'notification_failed':
        $troubleshooting['common_issues'][] = 'Email service configuration issues';
        $troubleshooting['common_issues'][] = 'Invalid recipient addresses';
        $troubleshooting['suggested_actions'][] = 'Check email service configuration';
        $troubleshooting['suggested_actions'][] = 'Verify admin email addresses';
        $troubleshooting['recovery_options'][] = 'Manual notification';
        $troubleshooting['recovery_options'][] = 'Skip notification step';
        break;
    }

    // Add error-specific troubleshooting
    if (str_contains($errorMessage, 'timeout')) {
      $troubleshooting['common_issues'][] = 'Service timeout - operation took too long';
      $troubleshooting['suggested_actions'][] = 'Increase timeout limits in configuration';
      $troubleshooting['recovery_options'][] = 'Retry with extended timeout';
    }

    if (str_contains($errorMessage, 'rate limit')) {
      $troubleshooting['common_issues'][] = 'API rate limit exceeded';
      $troubleshooting['suggested_actions'][] = 'Wait before retrying or upgrade API plan';
      $troubleshooting['recovery_options'][] = 'Implement exponential backoff retry';
    }

    if (str_contains($errorMessage, 'storage') || str_contains($errorMessage, 'disk')) {
      $troubleshooting['common_issues'][] = 'Storage or disk space issues';
      $troubleshooting['suggested_actions'][] = 'Check available disk space';
      $troubleshooting['suggested_actions'][] = 'Verify storage permissions';
      $troubleshooting['recovery_options'][] = 'Clean up old files or expand storage';
    }

    return $troubleshooting;
  }

  /**
   * Check queue system health
   */
  private function checkQueueHealth(): array
  {
    try {
      // Check if there are jobs stuck in processing for too long
      $stuckJobs = SermonProcessingLog::processing()
        ->where('updated_at', '<', now()->subHours(2))
        ->count();

      $pendingJobs = SermonProcessingLog::pending()->count();

      $status = 'healthy';
      $issues = [];

      if ($stuckJobs > 0) {
        $status = 'degraded';
        $issues[] = "{$stuckJobs} jobs appear to be stuck in processing";
      }

      if ($pendingJobs > 10) {
        $status = 'degraded';
        $issues[] = "{$pendingJobs} jobs pending - queue may be backed up";
      }

      return [
        'status' => $status,
        'stuck_jobs' => $stuckJobs,
        'pending_jobs' => $pendingJobs,
        'issues' => $issues,
      ];
    } catch (\Exception $e) {
      return [
        'status' => 'error',
        'message' => 'Failed to check queue health: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Check storage system health
   */
  private function checkStorageHealth(): array
  {
    try {
      $disk = config('sermon-processing.storage.disk', 'public');
      $storage = Storage::disk($disk);

      $status = 'healthy';
      $issues = [];

      // Check if storage is accessible
      if (!$storage->exists('.')) {
        $status = 'error';
        $issues[] = 'Storage disk is not accessible';
      }

      // Check available space (if supported)
      try {
        $testFile = 'health-check-' . time() . '.txt';
        $storage->put($testFile, 'health check');
        $storage->delete($testFile);
      } catch (\Exception $e) {
        $status = 'degraded';
        $issues[] = 'Storage write test failed: ' . $e->getMessage();
      }

      return [
        'status' => $status,
        'disk' => $disk,
        'issues' => $issues,
      ];
    } catch (\Exception $e) {
      return [
        'status' => 'error',
        'message' => 'Failed to check storage health: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Check processing system health
   */
  private function checkProcessingHealth(): array
  {
    try {
      // Check success rate over last 24 hours
      $recentLogs = SermonProcessingLog::where('created_at', '>=', now()->subDay());
      $totalRecent = $recentLogs->count();
      $completedRecent = $recentLogs->completed()->count();
      $failedRecent = $recentLogs->failed()->count();

      $successRate = $totalRecent > 0 ? ($completedRecent / $totalRecent) * 100 : 100;

      $status = 'healthy';
      $issues = [];

      if ($successRate < 80) {
        $status = 'degraded';
        $issues[] = "Low success rate: {$successRate}% in last 24 hours";
      }

      if ($failedRecent > 5) {
        $status = 'degraded';
        $issues[] = "{$failedRecent} failures in last 24 hours";
      }

      return [
        'status' => $status,
        'success_rate' => round($successRate, 1),
        'total_recent' => $totalRecent,
        'completed_recent' => $completedRecent,
        'failed_recent' => $failedRecent,
        'issues' => $issues,
      ];
    } catch (\Exception $e) {
      return [
        'status' => 'error',
        'message' => 'Failed to check processing health: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Clean up a file on failure
   */
  private function cleanupFile(string $filePath): void
  {
    try {
      $disk = config('sermon-processing.storage.disk', 'public');
      if (Storage::disk($disk)->exists($filePath)) {
        Storage::disk($disk)->delete($filePath);
        Log::info('Cleaned up file after processing failure', [
          'file_path' => $filePath,
        ]);
      }
    } catch (\Exception $e) {
      Log::warning('Failed to cleanup file', [
        'file_path' => $filePath,
        'error' => $e->getMessage(),
      ]);
    }
  }
}
