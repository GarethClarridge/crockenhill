<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LivestreamSegmentClassification;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Support\SectionReviewFlagPolicy;

/**
 * A detected service structure: the LLM's typed, timed reading of the whole
 * recording, ordered chronologically. Proposals only — nothing here reaches
 * `ServiceSectionSyncService::sync()` without passing the deterministic gate.
 */
final readonly class ServiceStructure extends JsonData
{
    /**
     * @param  list<ServiceStructureSection>  $sections  Ordered by start time
     * @param  list<string>  $notes  Run-level detector notes
     * @param  string|null  $model  The model that produced this structure
     * @param  string|null  $summary  Automatic summary of the complete service
     * @param  list<array{title: string, details: string|null}>  $notices  Extracted notice items
     * @param  list<array{title: string, start_time: float, end_time: float}>  $chapterMarkers  Content-aware recording chapters
     * @param  ServiceSermonAbsence|null  $sermonAbsence  The detector's assertion that this service held no sermon
     */
    public function __construct(
        public array $sections,
        public array $notes = [],
        public ?string $model = null,
        public ?string $summary = null,
        public array $notices = [],
        public array $chapterMarkers = [],
        public ?ServiceSermonAbsence $sermonAbsence = null,
    ) {}

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<string>  $notes
     * @param  array<int|string, mixed>  $notices
     * @param  array<int|string, mixed>  $chapterMarkers
     */
    public static function fromSections(
        array $sections,
        array $notes = [],
        ?string $model = null,
        ?string $summary = null,
        array $notices = [],
        array $chapterMarkers = [],
        ?ServiceSermonAbsence $sermonAbsence = null,
    ): self {
        usort(
            $sections,
            static fn (ServiceStructureSection $left, ServiceStructureSection $right): int => $left->startTime <=> $right->startTime
        );

        return new self(
            $sections,
            $notes,
            $model,
            $summary,
            self::normaliseNotices($notices),
            self::normaliseChapterMarkers($chapterMarkers),
            self::reconcileSermonAbsence($sermonAbsence, $sections),
        );
    }

    public static function fromArray(mixed $value): self
    {
        $payload = self::arrayValue($value);

        $sections = [];

        foreach (is_array($payload['sections'] ?? null) ? $payload['sections'] : [] as $sectionPayload) {
            $section = ServiceStructureSection::fromArray($sectionPayload);

            if ($section instanceof ServiceStructureSection) {
                $sections[] = $section;
            }
        }

        return self::fromSections(
            $sections,
            self::stringList($payload['notes'] ?? []),
            self::stringOrNull($payload['model'] ?? null),
            self::stringOrNull($payload['summary'] ?? null),
            self::arrayValue($payload['notices'] ?? null),
            self::arrayValue($payload['chapter_markers'] ?? null),
            ServiceSermonAbsence::fromArray($payload['sermon_absence'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sections' => array_map(
                static fn (ServiceStructureSection $section): array => $section->toArray(),
                $this->sections
            ),
            'notes' => $this->notes,
            'model' => $this->model,
            'summary' => $this->summary,
            'notices' => $this->notices,
            'chapter_markers' => $this->chapterMarkers,
            'sermon_absence' => $this->sermonAbsence?->toArray(),
        ];
    }

    /**
     * Whether this structure asserts that the service genuinely held no sermon.
     */
    public function assertsSermonAbsence(): bool
    {
        return $this->sermonAbsence instanceof ServiceSermonAbsence;
    }

    /**
     * @return list<ServiceStructureSection>
     */
    public function sectionsOfType(ServiceSectionType $type): array
    {
        return array_values(array_filter(
            $this->sections,
            static fn (ServiceStructureSection $section): bool => $section->type === $type
        ));
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    /**
     * Map this structure to the shape ServiceSectionSyncService::sync() expects.
     *
     * source_segment_ids are resolved by time overlap with the run's
     * LivestreamSegment rows. sync() validation requires a non-empty list, so
     * a section overlapping no segment gets a single synthesised covering
     * segment (marked in both the segment's and the section's metadata) —
     * this also keeps the manual segment-confirmation flow workable.
     *
     * @param  ChurchServiceTranscript|null  $transcript  When given, each section carries its transcript excerpt for downstream evidence (song matching, reading resolution)
     * @param  bool  $allowSegmentSynthesis  Shadow mode passes false so mapping never writes to the database; unresolved sections keep an empty id list (they never reach sync)
     * @return array<int, array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     summary: ?string,
     *     metadata: array<string, mixed>
     * }>
     */
    public function toClassifiedSections(
        MediaProcessingLog $processingLog,
        ?ChurchServiceTranscript $transcript = null,
        bool $allowSegmentSynthesis = true,
    ): array {
        $segments = LivestreamSegment::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('segment_order')
            ->orderBy('id')
            ->get(['id', 'start_time', 'end_time', 'segment_index']);

        $nextSegmentIndex = 1 + (int) $segments->max('segment_index');

        $classified = [];

        foreach ($this->sections as $index => $section) {
            $sourceSegmentIds = $segments
                ->filter(fn (LivestreamSegment $segment): bool => (float) $segment->start_time < $section->endTime
                    && (float) $segment->end_time > $section->startTime)
                ->pluck('id')
                ->values()
                ->all();

            $synthesisedSegment = false;

            if ($sourceSegmentIds === [] && $allowSegmentSynthesis) {
                $sourceSegmentIds = [$this->synthesiseCoveringSegment($processingLog, $section, $nextSegmentIndex++)];
                $synthesisedSegment = true;
            }

            $classified[] = [
                'church_service_item_id' => $section->oosItemId,
                'section_type' => $section->type->value,
                'section_order' => $index + 1,
                'title' => $section->title,
                'start_time' => $section->startTime,
                'end_time' => $section->endTime,
                'duration' => $section->duration(),
                'confidence' => $section->confidence,
                'status' => ServiceSectionStatus::Identified->value,
                'summary' => $section->summary,
                'needs_manual_review' => $this->reviewFlagsRequireManualReview($section),
                'source_segment_ids' => $sourceSegmentIds,
                'metadata' => $this->sectionMetadata($section, $transcript, $synthesisedSegment, $sourceSegmentIds === []),
            ];
        }

        return $classified;
    }

    private function reviewFlagsRequireManualReview(ServiceStructureSection $section): bool
    {
        return SectionReviewFlagPolicy::requiresManualReview(
            $section->type,
            $section->reviewFlags,
            $section->sermonReference,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionMetadata(
        ServiceStructureSection $section,
        ?ChurchServiceTranscript $transcript,
        bool $synthesisedSegment,
        bool $segmentsUnresolved = false,
    ): array {
        $metadata = [
            'classification_mode' => 'llm_structure',
            'confidence_source' => 'llm_structure',
            'confidence_score' => $section->confidence,
            'model' => $this->model,
            'ai_notes' => $section->notes,
            'summary' => $section->summary,
            'reading_reference' => null,
            'reading_reference_source' => null,
            'sermon_reference' => null,
            'sermon_reference_source' => null,
            // Always present so a re-run's sync merge replaces stale flags.
            'review_flags' => $section->reviewFlags,
        ];

        if ($section->reviewFlags !== []) {
            $metadata['review_reason'] = $section->reviewFlags[0];
        }

        if ($section->snapDeltas !== null) {
            $metadata['snap_deltas'] = $section->snapDeltas;
        }

        if ($section->readingReference !== null) {
            $metadata['reading_reference'] = $section->readingReference;
            $metadata['reading_reference_source'] = 'llm_structure';
        }

        if ($section->sermonReference !== null) {
            // SermonExtractionPlanResolver's strongest pairing evidence: the
            // reading whose reference overlaps this is the preached text.
            $metadata['sermon_reference'] = $section->sermonReference;
            $metadata['sermon_reference_source'] = 'llm_structure';
        }

        if ($section->songTitle !== null) {
            $metadata['song_title'] = $section->songTitle;
            // MatchSongsFromTranscript's first-choice input: its title-hint
            // path confirms this against the songs table via the existing
            // normalised matching before any OCR or transcription fallback.
            $metadata['song_title_hint'] = $section->songTitle;
        }

        if ($synthesisedSegment) {
            $metadata['synthesised_source_segment'] = true;
        }

        if ($segmentsUnresolved) {
            $metadata['source_segments_unresolved'] = true;
        }

        if ($transcript instanceof ChurchServiceTranscript) {
            $excerpt = trim($transcript->sliceText($section->startTime, $section->endTime));

            if ($excerpt !== '') {
                $metadata['transcript'] = $excerpt;
                $metadata['transcript_scope'] = 'section_excerpt';
            }
        }

        return $metadata;
    }

    /**
     * A sermon section and an absence assertion cannot both be true. The
     * sections are the detector's own timed reading of the recording and every
     * downstream stage works from them, so they win: a stray assertion beside a
     * detected sermon is dropped rather than allowed to stop extraction of a
     * sermon that is demonstrably there.
     *
     * @param  list<ServiceStructureSection>  $sections
     */
    private static function reconcileSermonAbsence(
        ?ServiceSermonAbsence $sermonAbsence,
        array $sections,
    ): ?ServiceSermonAbsence {
        if (! $sermonAbsence instanceof ServiceSermonAbsence) {
            return null;
        }

        foreach ($sections as $section) {
            if ($section->type === ServiceSectionType::Sermon) {
                return null;
            }
        }

        return $sermonAbsence;
    }

    /**
     * @param  array<int|string, mixed>  $notices
     * @return list<array{title: string, details: string|null}>
     */
    private static function normaliseNotices(array $notices): array
    {
        $normalised = [];

        foreach ($notices as $notice) {
            if (! is_array($notice)) {
                continue;
            }

            $title = self::stringOrNull($notice['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $normalised[] = [
                'title' => $title,
                'details' => self::stringOrNull($notice['details'] ?? null),
            ];
        }

        return $normalised;
    }

    /**
     * @param  array<int|string, mixed>  $chapterMarkers
     * @return list<array{title: string, start_time: float, end_time: float}>
     */
    private static function normaliseChapterMarkers(array $chapterMarkers): array
    {
        $normalised = [];

        foreach ($chapterMarkers as $chapterMarker) {
            if (! is_array($chapterMarker)) {
                continue;
            }

            $title = self::stringOrNull($chapterMarker['title'] ?? null);
            $startTime = self::floatOrNull($chapterMarker['start_time'] ?? null);
            $endTime = self::floatOrNull($chapterMarker['end_time'] ?? null);

            if ($title === null || $startTime === null || $endTime === null) {
                continue;
            }

            $startTime = max(0.0, $startTime);
            $endTime = max(0.0, $endTime);

            if ($endTime <= $startTime) {
                continue;
            }

            $normalised[] = [
                'title' => $title,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ];
        }

        return $normalised;
    }

    private function synthesiseCoveringSegment(
        MediaProcessingLog $processingLog,
        ServiceStructureSection $section,
        int $segmentIndex,
    ): int {
        $segment = LivestreamSegment::query()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_index' => $segmentIndex,
            'segment_order' => $segmentIndex,
            'start_time' => $section->startTime,
            'end_time' => $section->endTime,
            'duration' => $section->duration(),
            'classification' => $section->type === ServiceSectionType::Song
                ? LivestreamSegmentClassification::Song->value
                : LivestreamSegmentClassification::Speech->value,
            'is_sermon_candidate' => $section->type === ServiceSectionType::Sermon,
            'metadata' => ['synthesised_from_structure' => true],
        ]);

        return (int) $segment->id;
    }
}
