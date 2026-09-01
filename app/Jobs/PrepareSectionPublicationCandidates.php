<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\SectionPublicationHandler;
use App\Data\ServiceSectionMetadata;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\HistoricMedia\HistoricProcessingThroughput;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\MediaAssetPath;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class PrepareSectionPublicationCandidates extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        public MediaProcessingLog $processingLog,
        public bool $standalone = false,
    ) {}

    /**
     * Register and dispatch preparation started by a review-panel recut. The
     * normal pipeline supplies the cleanup successor through its chain; this
     * path must create that lifecycle explicitly because it has no chain.
     */
    public static function dispatchStandalone(MediaProcessingLog $processingLog): void
    {
        if (! self::registerHistoricNestedJob($processingLog)) {
            return;
        }

        $pendingDispatch = self::dispatch($processingLog, true);
        $queue = $processingLog->historic_import_operation_id === null
            ? (string) config('media-processing.queues.livestream', 'livestream-processing')
            : app(HistoricProcessingThroughput::class)->queueForClass(self::class);

        $pendingDispatch->onQueue($queue);
    }

    /**
     * Register this standalone preparation as historic nested work before the
     * queue message is visible. A queued/running/retryable row owns the source
     * already; terminal rows are reopened only for a new operator recut.
     *
     * @return bool Whether a new queue message should be dispatched
     */
    public static function registerHistoricNestedJob(MediaProcessingLog $processingLog): bool
    {
        $operationId = $processingLog->historic_import_operation_id;

        if ($operationId === null) {
            return true;
        }

        $jobKey = self::nestedJobKey($processingLog->processing_id);
        $nestedJob = HistoricImportNestedJob::query()->firstOrNew([
            'historic_import_operation_id' => $operationId,
            'job_key' => $jobKey,
        ]);

        if ($nestedJob->exists
            && ($nestedJob->media_processing_log_id !== $processingLog->id
                || $nestedJob->job_type !== self::class)) {
            throw new \RuntimeException(
                'Historic section publication preparation is owned by a different processing run: '
                .$processingLog->processing_id,
            );
        }

        if ($nestedJob->exists && in_array($nestedJob->state, ['queued', 'running', 'retryable'], true)) {
            return false;
        }

        if ($nestedJob->exists && ! in_array($nestedJob->state, ['failed', 'cancelled', 'completed'], true)) {
            throw new \RuntimeException(
                "Historic section publication preparation has an unknown nested state '{$nestedJob->state}' for processing ID: "
                .$processingLog->processing_id,
            );
        }

        $nestedJob->forceFill([
            'media_processing_log_id' => $processingLog->id,
            'job_type' => self::class,
            'state' => 'queued',
            'attempts' => 0,
            'error_fingerprint' => null,
            'dispatched_at' => now(),
            'settled_at' => null,
        ])->save();

        return true;
    }

    public static function nestedJobKey(string $processingId): string
    {
        return 'prepare-section-publication-candidates-'.$processingId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        /**
         * The lock has to outlive the job it guards.
         *
         * It expired after 300 seconds against an 1800-second timeout, so any
         * run past five minutes — which extraction of a full service routinely
         * is — left its own lock open and let a second copy start extracting the
         * same sections. The bulk run makes that the normal case rather than the
         * unlucky one.
         */
        return [
            (new WithoutOverlapping('prepare-section-publications-'.$this->processingLog->id))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        VideoExtractionService $videoExtractor,
        StorageAdapterHelper $storageHelper,
        SectionPublicationHandlerFactory $handlerFactory,
        ServiceSectionPublicationTransitionService $publicationTransitions
    ): void {
        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'Section publishing disabled');
            $this->finishStandalonePreparation();

            return;
        }

        if ($this->refreshAndCheckCancellation($this->processingLog)) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'Processing cancelled or log missing');
            $this->cancelStandalonePreparation();

            return;
        }

        if ($this->processingLog->processing_type !== MediaType::Livestream) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'Section publication preparation only runs for livestream processing');
            $this->failStandalonePreparation('Section publication preparation requires a livestream processing run.');

            return;
        }

        $this->logStepStart(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES);
        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::PreparingSectionPublicationCandidates->value);
        $this->markHistoricNestedJobRunning();

        $retainHours = (int) config('media-processing.section_publishing.retain_unpublished_hours', 48);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        if ($sections->isEmpty()) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'No classified sections available for publication review');
            $this->finishStandalonePreparation();

            return;
        }

        $pendingApprovalCount = 0;
        $autoPublishCount = 0;

        foreach ($sections as $section) {
            $handler = $handlerFactory->forSection($section);

            if (! $handler instanceof SectionPublicationHandler || ! $handler->isEligible($section)) {
                if (
                    $section->publication_status === ServiceSectionPublicationStatus::Published
                    || $section->publication_status === ServiceSectionPublicationStatus::Approved
                    || $section->publication_status === ServiceSectionPublicationStatus::Rejected
                ) {
                    continue;
                }

                $this->moveToNotApplicable($section, $publicationTransitions);

                continue;
            }

            // Extract early and run post-extraction hooks (e.g. speaker detection).
            // This must happen before status checks because afterExtraction may
            // enrich the section with data needed for approval review.
            $this->extractCandidateMediaIfNeeded($section, $handler, $videoExtractor, $storageHelper);
            $handler->afterExtraction($section);

            if ($section->needs_manual_review) {
                if (
                    $section->publication_status === ServiceSectionPublicationStatus::Published
                    || $section->publication_status === ServiceSectionPublicationStatus::Approved
                    || $section->publication_status === ServiceSectionPublicationStatus::Rejected
                ) {
                    $this->saveSectionIfDirty($section);

                    continue;
                }

                $this->moveToNotApplicable($section, $publicationTransitions);

                continue;
            }

            if ($section->publication_status === ServiceSectionPublicationStatus::Published) {
                $this->saveSectionIfDirty($section);

                continue;
            }

            if (! $handler->requiresApproval($section)) {
                $this->saveSectionIfDirty($section);
                $jobKey = 'auto-publish-section-'.$section->id;

                if ($this->processingLog->historic_import_operation_id !== null) {
                    HistoricImportNestedJob::query()->firstOrCreate(
                        [
                            'historic_import_operation_id' => $this->processingLog->historic_import_operation_id,
                            'job_key' => $jobKey,
                        ],
                        [
                            'media_processing_log_id' => $this->processingLog->id,
                            'job_type' => AutoPublishServiceSection::class,
                            'state' => 'queued',
                            'attempts' => 0,
                            'dispatched_at' => now(),
                        ],
                    );
                }

                $autoPublish = AutoPublishServiceSection::dispatch($section->id);
                $historicQueue = app(HistoricProcessingThroughput::class)
                    ->historicQueueFor(AutoPublishServiceSection::class);

                if ($historicQueue !== null) {
                    $autoPublish->onQueue($historicQueue);
                }

                $autoPublishCount++;

                continue;
            }

            if (
                $section->publication_status !== ServiceSectionPublicationStatus::PendingApproval
                && ! $publicationTransitions->transition($section, ServiceSectionPublicationStatus::PendingApproval)
            ) {
                continue;
            }

            if ($section->unpublished_expires_at === null) {
                $section->unpublished_expires_at = now()->addHours($retainHours);
            }
            $this->saveSectionIfDirty($section);
            $pendingApprovalCount++;
        }

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES,
            sprintf('Prepared %d approval candidate(s), dispatched %d auto-publish job(s)', $pendingApprovalCount, $autoPublishCount)
        );
        $this->finishStandalonePreparation();
    }

    private function moveToNotApplicable(
        ServiceSection $section,
        ServiceSectionPublicationTransitionService $publicationTransitions
    ): void {
        if ($section->publication_status === ServiceSectionPublicationStatus::Published) {
            return;
        }

        if (! $publicationTransitions->transition($section, ServiceSectionPublicationStatus::NotApplicable)) {
            return;
        }

        $section->unpublished_expires_at = now();
        $this->saveSectionIfDirty($section);
    }

    private function saveSectionIfDirty(ServiceSection $section): void
    {
        if ($section->isDirty()) {
            $section->save();
        }
    }

    private function extractCandidateMediaIfNeeded(
        ServiceSection $section,
        SectionPublicationHandler $handler,
        VideoExtractionService $videoExtractor,
        StorageAdapterHelper $storageHelper
    ): void {
        if ($this->shouldReuseExtractedMedia($section, $handler)) {
            return;
        }

        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \RuntimeException('Cannot prepare section candidates: missing source video path');
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        $isS3TempDisk = $this->isS3Disk($tempDisk);

        if ($isS3TempDisk) {
            if (! Storage::disk($tempDisk)->exists($sourceFilePath)) {
                throw new \RuntimeException('Cannot prepare section candidates: source video not found on temp disk');
            }

            $localSourcePath = $storageHelper->downloadToTemp(
                $sourceFilePath,
                $tempDisk,
                'local',
                'temp/section-publishing'
            );
        } else {
            $localSourcePath = Storage::disk($tempDisk)->path($sourceFilePath);
            if (! file_exists($localSourcePath)) {
                throw new \RuntimeException('Cannot prepare section candidates: source video file not found');
            }
        }

        try {
            $segment = (object) [
                'start_time' => (float) $section->start_time,
                'end_time' => (float) $section->end_time,
            ];

            $tempVideoPath = $videoExtractor->extractSegmentAsFile(
                $localSourcePath,
                $segment,
                $this->processingLog->processing_id.'_section_'.$section->id.'.mp4'
            );

            $videoStoragePath = $this->candidateVideoPath($section);
            $videoReadStream = Storage::disk($tempDisk)->readStream($tempVideoPath);
            if (! is_resource($videoReadStream)) {
                throw new \RuntimeException('Failed to read extracted section video stream');
            }

            Storage::disk($this->candidateDisk())->put($videoStoragePath, $videoReadStream);
            fclose($videoReadStream);
            Storage::disk($tempDisk)->delete($tempVideoPath);

            $section->extracted_video_path = $videoStoragePath;

            if ($handler->requiresAudioExtraction()) {
                $audioResult = $videoExtractor->extractOptimizedAudio(
                    $localSourcePath,
                    $segment,
                    $this->processingLog->processing_id.'_section_'.$section->id.'.mp3',
                    $this->candidateDisk(),
                    $this->candidateAudioDirectory($section)
                );

                $section->extracted_audio_path = $audioResult['audio_path'];
            }

            $section->extracted_at = now();
            $section->metadata = ServiceSectionMetadata::fromArray(array_replace(
                $section->metadata?->toArray() ?? [],
                [
                    'publication_candidate_extraction' => [
                        'processing_id' => $this->processingLog->processing_id,
                        'classification_signature' => $section->classificationSignature(),
                        'extracted_at' => now()->toIso8601String(),
                    ],
                ]
            ));
        } finally {
            if ($isS3TempDisk) {
                $storageHelper->cleanupTempFile($localSourcePath);
            }
        }
    }

    private function candidateDisk(): string
    {
        return MediaAssetPath::disk();
    }

    private function candidateAudioDirectory(ServiceSection $section): string
    {
        return 'section-publications/'.$section->id.'-'.$this->candidateDirectorySlug($section);
    }

    /**
     * Candidate directories used to be a bare `section-publications/{id}` on the
     * local disk. On the sermon disk — public-read and CDN-fronted — a sequential
     * integer would let unpublished review clips be walked by section id, so the
     * directory gains a component an outsider cannot derive.
     *
     * It is keyed on the application key rather than randomised so that it stays
     * stable for a section: a re-extraction overwrites the previous candidate
     * instead of orphaning it on a bucket nothing will ever clean up.
     */
    private function candidateDirectorySlug(ServiceSection $section): string
    {
        return substr(
            hash_hmac('sha256', 'section-publication-candidate:'.$section->id, (string) config('app.key')),
            0,
            16,
        );
    }

    private function candidateVideoPath(ServiceSection $section): string
    {
        return $this->candidateAudioDirectory($section).'/video.mp4';
    }

    private function shouldReuseExtractedMedia(ServiceSection $section, SectionPublicationHandler $handler): bool
    {
        if (! $handler->hasReusableExtractedMedia($section)) {
            return false;
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $provenance = $metadata['publication_candidate_extraction'] ?? null;

        if (! is_array($provenance)) {
            return true;
        }

        return ($provenance['processing_id'] ?? null) === $this->processingLog->processing_id
            && ($provenance['classification_signature'] ?? null) === $section->classificationSignature();
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES,
            $exception->getMessage()
        );

        if (! $this->standalone) {
            return;
        }

        $processingLog = $this->processingLog->fresh() ?? $this->processingLog;
        $this->markHistoricNestedJobFailed($exception);
        $this->markProcessingRunAsFailed(
            $processingLog,
            $exception->getMessage(),
            ProcessingStep::PreparingSectionPublicationCandidates->value,
        );
    }

    private function finishStandalonePreparation(): void
    {
        if (! $this->standalone) {
            return;
        }

        $processingLog = $this->processingLog->fresh();

        if (! $processingLog instanceof MediaProcessingLog || $processingLog->isCancelled()) {
            $this->cancelStandalonePreparation();

            return;
        }

        $this->markHistoricNestedJobCompleted();
        $this->markProcessingRunAsCompleted($processingLog, 'completed');

        $cleanup = CleanupTemporaryFiles::dispatch($processingLog);

        if ($processingLog->historic_import_operation_id !== null) {
            $cleanup->onQueue(app(HistoricProcessingThroughput::class)->queueForClass(CleanupTemporaryFiles::class));
        }
    }

    private function cancelStandalonePreparation(): void
    {
        if (! $this->standalone) {
            return;
        }

        $nestedJob = $this->historicNestedJob();

        if ($nestedJob instanceof HistoricImportNestedJob && $nestedJob->state !== 'completed') {
            $nestedJob->forceFill([
                'state' => 'cancelled',
                'settled_at' => now(),
            ])->save();
        }
    }

    private function failStandalonePreparation(string $message): void
    {
        if (! $this->standalone) {
            return;
        }

        $processingLog = $this->processingLog->fresh() ?? $this->processingLog;
        $this->markHistoricNestedJobFailed(new \RuntimeException($message));
        $this->markProcessingRunAsFailed(
            $processingLog,
            $message,
            ProcessingStep::PreparingSectionPublicationCandidates->value,
        );
    }

    private function markHistoricNestedJobRunning(): void
    {
        $nestedJob = $this->historicNestedJob();

        if (! $nestedJob instanceof HistoricImportNestedJob) {
            return;
        }

        $nestedJob->forceFill([
            'state' => 'running',
            'attempts' => $nestedJob->attempts + 1,
            'error_fingerprint' => null,
            'settled_at' => null,
        ])->save();
    }

    private function markHistoricNestedJobCompleted(): void
    {
        $nestedJob = $this->historicNestedJob();

        if (! $nestedJob instanceof HistoricImportNestedJob) {
            return;
        }

        $nestedJob->forceFill([
            'state' => 'completed',
            'error_fingerprint' => null,
            'settled_at' => now(),
        ])->save();
    }

    private function markHistoricNestedJobFailed(\Throwable $exception): void
    {
        $nestedJob = $this->historicNestedJob();

        if (! $nestedJob instanceof HistoricImportNestedJob) {
            return;
        }

        $nestedJob->forceFill([
            'state' => 'failed',
            'error_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            'settled_at' => now(),
        ])->save();
    }

    private function historicNestedJob(): ?HistoricImportNestedJob
    {
        if ($this->processingLog->historic_import_operation_id === null) {
            return null;
        }

        return HistoricImportNestedJob::query()
            ->where('historic_import_operation_id', $this->processingLog->historic_import_operation_id)
            ->where('media_processing_log_id', $this->processingLog->id)
            ->where('job_key', self::nestedJobKey($this->processingLog->processing_id))
            ->where('job_type', self::class)
            ->first();
    }
}
