<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonCreationOptions;
use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Sermon\SermonCreationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateSermonRecord extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        SermonProcessingLogger $logger,
        SermonCreationService $sermonCreationService
    ): void {
        $startTime = microtime(true);

        try {
            $refreshed = $this->processingLog->fresh();
            if (! $refreshed) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $refreshed;
            $this->initializeStepLogging($this->processingLog->processing_id);

            if ($this->isCancelled()) {
                Log::info('CreateSermonRecord job cancelled', ['processing_id' => $this->processingLog->processing_id]);

                return;
            }

            $this->logStepStart('creating', 'Creating sermon record');
            $logger->logProcessingStep(
                $this->processingLog->processing_id,
                'creating_sermon_record',
                'started',
                ['original_filename' => $this->processingLog->original_filename]
            );

            // Update processing log to indicate we're starting
            $this->markProcessingRunAsProcessing($this->processingLog, 'creating_sermon_record');

            /** @var array{title: string, series: string|null, reference: string|null, points: list<string>, summary: string|null, transcript: string}|null $aiAnalysis */
            $aiAnalysis = $this->processingLog->ai_analysis?->toArray();
            $id3Metadata = $this->processingLog->processing_metadata?->id3Metadata;

            // Prepare options using factory method based on processing type
            $options = match ($this->processingLog->processing_type) {
                MediaType::Audio => SermonCreationOptions::fromAudioUpload($this->processingLog, $aiAnalysis),
                MediaType::Video => SermonCreationOptions::fromVideoUpload($this->processingLog, $aiAnalysis),
                MediaType::Livestream => throw new \Exception('CreateSermonRecord should not be used for livestream processing'),
            };

            // ID3 metadata is overlaid onto the options by the factory above
            // (it takes priority over AI/defaults); log it here when present.
            if ($id3Metadata !== null) {
                Log::info('Using ID3 metadata for sermon creation', [
                    'processing_id' => $this->processingLog->processing_id,
                    'id3_title' => $options->id3Title,
                    'id3_preacher' => $options->id3Preacher,
                    'id3_series' => $options->id3Series,
                    'id3_reference' => $options->id3Reference,
                ]);
            }

            // Create sermon using service
            $sermon = $sermonCreationService->createSermon($this->processingLog, $options);

            // Store video permanently if needed
            if ($this->processingLog->processing_type === MediaType::Video && $this->videoSourcePath() !== null) {
                $finalVideoPath = $this->storeVideoForSermon($sermon->id, $this->videoSourcePath());
                $sermon->update(['video_file_path' => $finalVideoPath]);
                $this->processingLog->update(['video_file_path' => $finalVideoPath]);
            }

            // Update processing log with sermon ID
            $this->processingLog->update([
                'sermon_id' => $sermon->id,
                'current_step' => 'sermon_record_created',
            ]);

            // Note: Job chain will automatically dispatch the next job (TranscribeAudio)
            // Manual dispatching removed to prevent conflicts with job chain system

            $executionTime = microtime(true) - $startTime;

            $this->logStepComplete('creating', 'Sermon record created successfully');
            $logger->logProcessingStep(
                $this->processingLog->processing_id,
                'creating_sermon_record',
                'completed',
                [
                    'sermon_id' => $sermon->id,
                    'title' => $sermon->title,
                    'slug' => $sermon->slug,
                    'execution_time_ms' => round($executionTime * 1000, 2),
                ]
            );
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $logger->logError(
                $this->processingLog->processing_id,
                'creating_sermon_record',
                $e,
                ['execution_time_ms' => round($executionTime * 1000, 2)]
            );

            // Update processing log with error
            $this->markProcessingRunAsFailed($this->processingLog, $e->getMessage(), 'creating_sermon_record');
            $this->logStepFailed('creating', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Store video file to permanent sermon storage
     * Similar to SermonMetadataIntegrationService::organizeVideoFile
     */
    private function storeVideoForSermon(int $sermonId, string $tempVideoPath): string
    {
        $sermonDisk = Storage::disk(config('media-processing.storage.sermon_disk', 'public'));

        // Get the temp disk and resolve absolute path
        $tempDisk = config('filesystems.default', 'local');
        $absoluteTempPath = Storage::disk($tempDisk)->path($tempVideoPath);

        // Create directory structure based on sermon ID
        $directory = "sermons/{$sermonId}";
        $extension = pathinfo($tempVideoPath, PATHINFO_EXTENSION);
        $filename = "video.{$extension}"; // Preserve original extension (mkv, mp4, etc)
        $finalPath = "{$directory}/{$filename}";

        // Ensure the directory exists
        $sermonDisk->makeDirectory($directory);

        // Copy the video file to the final location
        $sermonDisk->putFileAs(
            $directory,
            new File($absoluteTempPath),
            $filename
        );

        Log::info('Video file moved to permanent storage', [
            'source_path' => $tempVideoPath,
            'absolute_path' => $absoluteTempPath,
            'final_path' => $finalPath,
            'sermon_id' => $sermonId,
        ]);

        return $finalPath;
    }

    private function videoSourcePath(): ?string
    {
        if (is_string($this->processingLog->video_file_path) && $this->processingLog->video_file_path !== '') {
            return $this->processingLog->video_file_path;
        }

        return is_string($this->processingLog->source_file_path) && $this->processingLog->source_file_path !== ''
            ? $this->processingLog->source_file_path
            : null;
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->markProcessingRunAsFailed($this->processingLog, $exception->getMessage(), 'creating_sermon_record_failed');
    }
}
