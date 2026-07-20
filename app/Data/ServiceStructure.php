<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LivestreamSegmentClassification;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureValidator;

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
     */
    public function __construct(
        public array $sections,
        public array $notes = [],
        public ?string $model = null,
    ) {}

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<string>  $notes
     */
    public static function fromSections(array $sections, array $notes = [], ?string $model = null): self
    {
        usort(
            $sections,
            static fn (ServiceStructureSection $left, ServiceStructureSection $right): int => $left->startTime <=> $right->startTime
        );

        return new self($sections, $notes, $model);
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
        ];
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
     * Map this structure to the exact ClassifiedSection shape
     * ServiceSectionSyncService::sync() expects (documented on
     * ServiceSectionClassifier).
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
                'needs_manual_review' => $this->reviewFlagsRequireManualReview($section),
                'source_segment_ids' => $sourceSegmentIds,
                'metadata' => $this->sectionMetadata($section, $transcript, $synthesisedSegment, $sourceSegmentIds === []),
            ];
        }

        return $classified;
    }

    private function reviewFlagsRequireManualReview(ServiceStructureSection $section): bool
    {
        foreach ($section->reviewFlags as $reviewFlag) {
            if ($reviewFlag === ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION) {
                continue;
            }

            if (
                in_array($reviewFlag, [
                    ServiceStructureValidator::FLAG_LOW_CONFIDENCE,
                    ServiceStructureValidator::FLAG_MICRO_SECTION,
                ], true)
                && ! $section->type->requiresStructuralUncertaintyReview()
            ) {
                continue;
            }

            return true;
        }

        return false;
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
