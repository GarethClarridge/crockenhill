<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ServiceSectionMetadata;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChildrensTalkSpeakerService;
use App\Services\ServiceSectionPublicationTransitionService;
use App\Services\StorageAdapterHelper;
use App\Services\VideoExtractionService;
use App\Support\ChurchServiceProcessingTimeline;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
        private MediaProcessingLog $processingLog
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('prepare-section-publications-'.$this->processingLog->id))
                ->releaseAfter(60)
                ->expireAfter(300),
        ];
    }

    public function handle(
        VideoExtractionService $videoExtractor,
        StorageAdapterHelper $storageHelper,
        ChildrensTalkSpeakerService $childrensTalkSpeakerService,
        ServiceSectionPublicationTransitionService $publicationTransitions
    ): void {
        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'Section publishing disabled');

            return;
        }

        $processingLog = $this->processingLog->fresh();
        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;
        $this->initializeStepLogging($this->processingLog->processing_id);

        if ($this->isCancelled()) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'Processing cancelled');

            Log::info('PrepareSectionPublicationCandidates job skipped: processing cancelled', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            return;
        }

        if ($this->processingLog->processing_type !== MediaType::Livestream) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'Section publication preparation only runs for livestream processing');

            return;
        }

        $this->logStepStart(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES);
        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::PreparingSectionPublicationCandidates->value);

        $extractTypes = config('media-processing.section_publishing.extract_types', ['childrens_talk']);
        $requireHighConfidence = (bool) config('media-processing.section_publishing.require_high_confidence', true);
        $retainHours = (int) config('media-processing.section_publishing.retain_unpublished_hours', 48);

        if (! is_array($extractTypes)) {
            $extractTypes = [];
        }

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        if ($sections->isEmpty()) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES, 'No classified sections available for publication review');

            return;
        }

        $pendingApprovalCount = 0;

        foreach ($sections as $section) {
            $sectionType = $section->section_type->value;
            $confidence = (string) ($section->metadata['confidence_level'] ?? 'none');
            $eligibleByType = in_array($sectionType, $extractTypes, true);
            $eligibleByConfidence = ! $requireHighConfidence || $confidence === 'high';

            if (! $eligibleByType || ! $eligibleByConfidence) {
                if (
                    $section->publication_status === ServiceSectionPublicationStatus::PUBLISHED
                    || $section->publication_status === ServiceSectionPublicationStatus::APPROVED
                    || $section->publication_status === ServiceSectionPublicationStatus::REJECTED
                ) {
                    continue;
                }

                $this->moveToNotApplicable($section, $publicationTransitions);

                continue;
            }

            if ($section->section_type === ServiceSectionType::CHILDRENS_TALK) {
                $this->extractCandidateMediaIfNeeded($section, $videoExtractor, $storageHelper);
                $childrensTalkSpeakerService->detectAndStore($section);
            }

            $eligibleByStatus = $section->status === ServiceSectionStatus::IDENTIFIED && ! $section->needs_manual_review;

            if (! $eligibleByStatus) {
                if (
                    $section->publication_status === ServiceSectionPublicationStatus::PUBLISHED
                    || $section->publication_status === ServiceSectionPublicationStatus::APPROVED
                    || $section->publication_status === ServiceSectionPublicationStatus::REJECTED
                ) {
                    $section->save();

                    continue;
                }

                $this->moveToNotApplicable($section, $publicationTransitions);

                continue;
            }

            if ($section->publication_status === ServiceSectionPublicationStatus::PUBLISHED) {
                $section->save();

                continue;
            }

            if (
                $section->publication_status !== ServiceSectionPublicationStatus::PENDING_APPROVAL
                && ! $publicationTransitions->transition($section, ServiceSectionPublicationStatus::PENDING_APPROVAL)
            ) {
                continue;
            }

            if ($section->section_type !== ServiceSectionType::CHILDRENS_TALK) {
                $this->extractCandidateMediaIfNeeded($section, $videoExtractor, $storageHelper);
            }

            $section->unpublished_expires_at = now()->addHours($retainHours);
            $section->save();
            $pendingApprovalCount++;
        }

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES,
            sprintf('Prepared %d publication candidate(s)', $pendingApprovalCount)
        );
    }

    private function moveToNotApplicable(
        ServiceSection $section,
        ServiceSectionPublicationTransitionService $publicationTransitions
    ): void {
        if ($section->publication_status === ServiceSectionPublicationStatus::PUBLISHED) {
            return;
        }

        if (! $publicationTransitions->transition($section, ServiceSectionPublicationStatus::NOT_APPLICABLE)) {
            return;
        }

        $section->unpublished_expires_at = now();
        $section->save();
    }

    private function extractCandidateMediaIfNeeded(
        ServiceSection $section,
        VideoExtractionService $videoExtractor,
        StorageAdapterHelper $storageHelper
    ): void {
        if ($this->shouldReuseExtractedMedia($section)) {
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

            $audioResult = $videoExtractor->extractOptimizedAudio(
                $localSourcePath,
                $segment,
                $this->processingLog->processing_id.'_section_'.$section->id.'.mp3',
                'local',
                $this->candidateAudioDirectory($section)
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
            $section->extracted_audio_path = $audioResult['audio_path'];
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
        return 'local';
    }

    private function candidateAudioDirectory(ServiceSection $section): string
    {
        return 'private/section-publications/'.$section->id;
    }

    private function candidateVideoPath(ServiceSection $section): string
    {
        return $this->candidateAudioDirectory($section).'/video.mp4';
    }

    private function shouldReuseExtractedMedia(ServiceSection $section): bool
    {
        if (! $section->hasExtractedMedia()) {
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

    public function failed(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::PREPARE_SECTION_PUBLICATION_CANDIDATES,
            $exception->getMessage()
        );

        Log::error('PrepareSectionPublicationCandidates job failed', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
