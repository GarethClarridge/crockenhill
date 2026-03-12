<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChildrensTalkSpeakerService;
use App\Services\StorageAdapterHelper;
use App\Services\VideoExtractionService;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PrepareSectionPublicationCandidates implements ShouldQueue
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
        ChildrensTalkSpeakerService $childrensTalkSpeakerService
    ): void {
        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            return;
        }

        $processingLog = $this->processingLog->fresh();
        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;

        if ($this->processingLog->processing_type !== MediaType::Livestream) {
            return;
        }

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

                $this->moveToNotApplicable($section);

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

                $this->moveToNotApplicable($section);

                continue;
            }

            if ($section->publication_status === ServiceSectionPublicationStatus::PUBLISHED) {
                $section->save();

                continue;
            }

            if (
                $section->publication_status !== ServiceSectionPublicationStatus::PENDING_APPROVAL
                && ! $section->transitionTo(ServiceSectionPublicationStatus::PENDING_APPROVAL)
            ) {
                continue;
            }

            if ($section->section_type !== ServiceSectionType::CHILDRENS_TALK) {
                $this->extractCandidateMediaIfNeeded($section, $videoExtractor, $storageHelper);
            }

            $section->unpublished_expires_at = now()->addHours($retainHours);
            $section->save();
        }
    }

    private function moveToNotApplicable(ServiceSection $section): void
    {
        if ($section->publication_status === ServiceSectionPublicationStatus::PUBLISHED) {
            return;
        }

        if (! $section->transitionTo(ServiceSectionPublicationStatus::NOT_APPLICABLE)) {
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
        if (
            is_string($section->extracted_video_path)
            && $section->extracted_video_path !== ''
            && is_string($section->extracted_audio_path)
            && $section->extracted_audio_path !== ''
            && Storage::disk($this->sermonDisk())->exists($section->extracted_video_path)
            && Storage::disk($this->sermonDisk())->exists($section->extracted_audio_path)
        ) {
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
                $this->processingLog->processing_id.'_section_'.$section->id.'.mp3'
            );

            $videoStoragePath = 'sermons/sections/'.$section->id.'/video.mp4';
            $videoReadStream = Storage::disk($tempDisk)->readStream($tempVideoPath);
            if (! is_resource($videoReadStream)) {
                throw new \RuntimeException('Failed to read extracted section video stream');
            }

            Storage::disk($this->sermonDisk())->put($videoStoragePath, $videoReadStream);
            fclose($videoReadStream);
            Storage::disk($tempDisk)->delete($tempVideoPath);

            $section->extracted_video_path = $videoStoragePath;
            $section->extracted_audio_path = $audioResult['audio_path'];
            $section->extracted_at = now();
        } finally {
            if ($isS3TempDisk) {
                $storageHelper->cleanupTempFile($localSourcePath);
            }
        }
    }

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', 'public');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PrepareSectionPublicationCandidates job failed', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
