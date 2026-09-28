<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Support\Facades\DB;

/**
 * A reviewer splits one section into two of the same type at a chosen time: the counterpart of
 * {@see MergeAdjacentServiceSections}, for items the detector merged (1311's three testimonies
 * detected as one talk).
 *
 * The section keeps the first part with its title, item and history; the second part is a new
 * section after it, which the reviewer then retitles and confirms. Neither part can say which
 * of them a content hold's evidence lies in, so both keep it. Media is reset as the merges
 * reset it.
 */
class SplitServiceSection
{
    public function __construct(
        private readonly HoldSectionForContentReview $contentHolds,
        private readonly VideoStorageService $videoStorageService,
        private readonly ExtractedSectionMediaChecker $mediaChecker,
    ) {}

    /**
     * Returns null on success, or why the section cannot be split there.
     */
    public function execute(ServiceSection $section, float $at, int $userId): ?string
    {
        if ($section->publication_status === ServiceSectionPublicationStatus::Published) {
            return 'Published sections cannot be split.';
        }

        $minimumSeconds = (float) config('media-processing.service_structure.min_section_seconds', 15);

        if ($at - (float) $section->start_time < $minimumSeconds || (float) $section->end_time - $at < $minimumSeconds) {
            return sprintf('Split at least %d seconds inside the section, so neither part is shorter than that.', $minimumSeconds);
        }

        $second = DB::transaction(function () use ($section, $at, $userId): ServiceSection {
            $endTime = (float) $section->end_time;

            // Orders are unique per run, so later sections move up from the last one down.
            ServiceSection::query()
                ->where('media_processing_log_id', $section->media_processing_log_id)
                ->where('section_order', '>', $section->section_order)
                ->orderByDesc('section_order')
                ->get(['id', 'section_order'])
                ->each(static fn (ServiceSection $later) => ServiceSection::query()
                    ->whereKey($later->id)
                    ->update(['section_order' => $later->section_order + 1]));

            $second = ServiceSection::query()->create([
                'media_processing_log_id' => $section->media_processing_log_id,
                'section_type' => $section->section_type,
                'section_order' => $section->section_order + 1,
                'title' => $section->title,
                'start_time' => $at,
                'end_time' => $endTime,
                'duration' => $endTime - $at,
                'status' => $section->status,
                'needs_manual_review' => true,
                'source_segment_ids' => $section->source_segment_ids,
                'confidence' => $section->confidence,
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
                'metadata' => ServiceSectionMetadata::fromArray([
                    'review_flags' => [],
                    'split_from_section_id' => $section->id,
                ]),
            ]);

            $metadata = $section->metadata?->toArray() ?? [];
            $metadata['manually_split'] = [
                'at' => $at,
                'from' => ['start_time' => (float) $section->start_time, 'end_time' => $endTime],
                'new_section_id' => $second->id,
                'by_user_id' => $userId,
                'split_at' => now()->toIso8601String(),
            ];

            $section->end_time = $at;
            $section->duration = $at - (float) $section->start_time;

            if ($this->mediaChecker->hasExtractedMedia($section)) {
                $section->extracted_video_path = null;
                $section->extracted_audio_path = null;
                $section->extracted_at = null;
                $section->publication_status = ServiceSectionPublicationStatus::NotApplicable;
            }

            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
            $section->save();

            $this->contentHolds->carry($section, $second);

            return $second;
        });

        $processingLog = $second->processingLog;

        if (is_string($processingLog->source_file_path)
            && $processingLog->source_file_path !== ''
            && $this->videoStorageService->sourceVideoExistsForPath($processingLog->source_file_path)
        ) {
            PrepareSectionPublicationCandidates::dispatchStandalone($processingLog);
        }

        return null;
    }
}
