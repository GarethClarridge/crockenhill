<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A reviewer's answer to {@see ServiceStructureValidator::FLAG_TALK_INTERRUPTED} when the two
 * talks are one: the talk that began absorbs the readings and prayers after it and the talk's
 * ending, in one step.
 *
 * {@see MergeAdjacentServiceSections} cannot do this, because it joins two neighbours of one
 * type and a reading lies between. The talk that began is kept whatever the lengths, because
 * it carries the talk's title and item and the ending is the part the detector invented.
 * Media and content holds move exactly as they do in the same-type merge.
 */
class MergeInterruptedTalk
{
    /**
     * What may lie between a talk and its ending, as the validator's flag reads it.
     *
     * @var list<ServiceSectionType>
     */
    private const INTERRUPTION_TYPES = [
        ServiceSectionType::BibleReading,
        ServiceSectionType::Prayer,
    ];

    public function __construct(
        private readonly HoldSectionForContentReview $contentHolds,
        private readonly ServiceSectionSyncService $syncService,
        private readonly VideoStorageService $videoStorageService,
        private readonly ExtractedSectionMediaChecker $mediaChecker,
    ) {}

    /**
     * Each talk that a run of readings and prayers separates from a later talk, keyed by its
     * id, with that later talk's id.
     *
     * @param  Collection<int, ServiceSection>  $sections  One run's sections in order.
     * @return array<int, int>
     */
    public static function candidates(Collection $sections): array
    {
        $candidates = [];
        $ordered = array_values($sections->all());

        foreach ($ordered as $index => $section) {
            $absorbed = self::absorbedAfter($ordered, $index);

            if ($absorbed !== null && ! self::anyPublished([$section, ...$absorbed])) {
                $candidates[$section->id] = $absorbed[array_key_last($absorbed)]->id;
            }
        }

        return $candidates;
    }

    /**
     * Returns null on success, or why the talk cannot absorb what follows it.
     */
    public function execute(ServiceSection $talk, int $userId): ?string
    {
        if ($talk->section_type !== ServiceSectionType::ShortTalk) {
            return 'Only a talk can absorb the readings and prayers that interrupted it.';
        }

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $talk->media_processing_log_id)
            ->orderBy('section_order')
            ->get()
            ->values();

        $position = $sections->search(static fn (ServiceSection $section): bool => $section->id === $talk->id);
        $sections = array_values($sections->all());
        $absorbed = $position === false ? null : self::absorbedAfter($sections, $position);

        if ($absorbed === null) {
            return 'This talk is not followed by readings or prayers and then another talk.';
        }

        if (self::anyPublished([$talk, ...$absorbed])) {
            return 'Published sections cannot be merged.';
        }

        $ending = $absorbed[array_key_last($absorbed)];

        DB::transaction(function () use ($talk, $absorbed, $ending, $userId): void {
            $metadata = $talk->metadata?->toArray() ?? [];
            $metadata['review_flags'] = array_values(array_diff(
                (array) ($metadata['review_flags'] ?? []),
                [ServiceStructureValidator::FLAG_TALK_INTERRUPTED],
            ));
            $metadata['absorbed_talk_interruption'] = array_map(static fn (ServiceSection $section): array => [
                'section_id' => $section->id,
                'type' => $section->section_type->value,
                'title' => $section->title,
                'start_time' => (float) $section->start_time,
                'end_time' => (float) $section->end_time,
            ], $absorbed);
            $metadata['manually_merged'] = true;
            $metadata['manually_merged_at'] = now()->toIso8601String();
            $metadata['manually_merged_by_user_id'] = $userId;

            $talk->end_time = max((float) $talk->end_time, (float) $ending->end_time);
            $talk->duration = max(0.0, (float) $talk->end_time - (float) $talk->start_time);
            $talk->source_segment_ids = array_values(array_unique(array_merge(
                $talk->source_segment_ids,
                ...array_map(static fn (ServiceSection $section): array => $section->source_segment_ids, $absorbed),
            )));
            $talk->needs_manual_review = $talk->needs_manual_review || $ending->needs_manual_review;

            if ($this->mediaChecker->hasExtractedMedia($talk)) {
                $talk->extracted_video_path = null;
                $talk->extracted_audio_path = null;
                $talk->extracted_at = null;
                $talk->publication_status = ServiceSectionPublicationStatus::NotApplicable;
            }

            $talk->metadata = ServiceSectionMetadata::fromArray($metadata);
            $talk->save();

            foreach ($absorbed as $section) {
                $this->contentHolds->carry($section, $talk);
                $this->syncService->removeSection($section);
            }
        });

        $processingLog = $talk->processingLog;

        if (is_string($processingLog->source_file_path)
            && $processingLog->source_file_path !== ''
            && $this->videoStorageService->sourceVideoExistsForPath($processingLog->source_file_path)
        ) {
            PrepareSectionPublicationCandidates::dispatchStandalone($processingLog);
        }

        return null;
    }

    /**
     * @param  list<ServiceSection>  $sections
     */
    private static function anyPublished(array $sections): bool
    {
        foreach ($sections as $section) {
            if ($section->publication_status === ServiceSectionPublicationStatus::Published) {
                return true;
            }
        }

        return false;
    }

    /**
     * The readings and prayers after the talk at `$index` and the talk that ends them, or null
     * when the talk is not interrupted.
     *
     * @param  list<ServiceSection>  $sections
     * @return non-empty-list<ServiceSection>|null
     */
    private static function absorbedAfter(array $sections, int $index): ?array
    {
        if ($sections[$index]->section_type !== ServiceSectionType::ShortTalk) {
            return null;
        }

        $absorbed = [];

        foreach (array_slice($sections, $index + 1) as $section) {
            if (in_array($section->section_type, self::INTERRUPTION_TYPES, true)) {
                $absorbed[] = $section;

                continue;
            }

            if ($section->section_type === ServiceSectionType::ShortTalk && $absorbed !== []) {
                return [...$absorbed, $section];
            }

            return null;
        }

        return null;
    }
}
