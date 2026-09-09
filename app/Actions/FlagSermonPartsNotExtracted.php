<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\SectionReviewFlagPolicy;

/**
 * Hold a sermon whose stored media covers fewer parts than its plan now names.
 *
 * Recording a sermon continuation (P8-Q15) changes what the extraction plan
 * would cut, but it does not re-cut anything: the 442 historic sermons are
 * completed runs that will never pass through the pipeline again. Without this,
 * marking a part would be inert — the run would go on holding a sermon missing
 * sixteen minutes, and the release gate would see a clean section and ship it.
 * A marker is not a repair, in the same way the P8-Q14 holds were not.
 *
 * The hold is *derived*, never stored as a verdict: it compares the plan the
 * resolver produces now against the spans the media was actually cut from. So it
 * withdraws itself the moment a re-extraction makes the two agree, and no
 * reconciliation pass is owed. That is deliberately unlike a flag keyed to a
 * database stamp, which P8-Q3 and the regeneration pass both found can be
 * cleared while the bytes a reader sees stay wrong.
 *
 * @see FlagSermonTextPredatesEvidence for the sibling that holds the *text*
 *   when the transcript beneath it changed; this one holds the *span*.
 */
class FlagSermonPartsNotExtracted
{
    /** The plan names sermon material the stored media does not contain. */
    public const FLAG = 'sermon_parts_not_extracted';

    /**
     * Sub-second differences are keyframe and snapping noise, not a missing part.
     * The smallest real omission in the corpus is 1.9 minutes.
     */
    private const TOLERANCE_SECONDS = 1.0;

    public function __construct(
        private readonly SermonExtractionPlanResolver $plans,
    ) {}

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run): array
    {
        $unextractedSeconds = $this->unextractedSeconds($run);
        $owed = $unextractedSeconds > self::TOLERANCE_SECONDS;
        $outcome = ['raised' => 0, 'withdrawn' => 0];

        $sections = $run->serviceSections()
            ->where('section_type', ServiceSectionType::Sermon->value)
            ->orderBy('start_time')
            ->get();

        foreach ($sections as $section) {
            if ($this->apply($section, $owed)) {
                $outcome[$owed ? 'raised' : 'withdrawn']++;
            }
        }

        return $outcome;
    }

    /**
     * Planned sermon seconds the stored media does not cover.
     *
     * Zero where the run was never extracted from a plan at all: there is no
     * stored span to disagree with, so nothing is owed and nothing is claimed.
     */
    public function unextractedSeconds(MediaProcessingLog $run): float
    {
        $recorded = $run->recordedSermonExtractionSpans();

        if ($recorded === null) {
            return 0.0;
        }

        $plan = $this->plans->resolve($run);

        if ($plan['source'] !== 'service_sections') {
            return 0.0;
        }

        $uncovered = 0.0;

        foreach ($plan['segments'] as $segment) {
            $uncovered += $this->uncoveredSeconds(
                (float) $segment['start_time'],
                (float) $segment['end_time'],
                $recorded,
            );
        }

        return $uncovered;
    }

    /**
     * How much of one planned span no recorded span covers.
     *
     * Recorded spans are subtracted in start order, so a planned part split
     * across two recorded spans is not counted as missing twice.
     *
     * Public because a screen must be able to say, before it writes anything,
     * whether a part it is about to record is already inside the published
     * media. Three of the corpus's six parts are: the sermon-end rule had
     * already run the span forward over them.
     *
     * @param  list<array{start: float, end: float}>  $recorded
     */
    public function uncoveredSeconds(float $start, float $end, array $recorded): float
    {
        usort($recorded, static fn (array $first, array $second): int => $first['start'] <=> $second['start']);

        $uncovered = 0.0;
        $cursor = $start;

        foreach ($recorded as $span) {
            if ($span['end'] <= $cursor) {
                continue;
            }

            if ($span['start'] >= $end) {
                break;
            }

            if ($span['start'] > $cursor) {
                $uncovered += $span['start'] - $cursor;
            }

            $cursor = max($cursor, $span['end']);

            if ($cursor >= $end) {
                return $uncovered;
            }
        }

        return $uncovered + max(0.0, $end - $cursor);
    }

    private function apply(ServiceSection $section, bool $owed): bool
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $flags = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));

        $without = array_values(array_filter($flags, static fn (string $flag): bool => $flag !== self::FLAG));
        $metadata['review_flags'] = $owed ? [...$without, self::FLAG] : $without;

        $needsReview = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        if (in_array(self::FLAG, $flags, true) === $owed && $needsReview === $section->needs_manual_review) {
            return false;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = $needsReview;
        $section->save();

        return true;
    }
}
