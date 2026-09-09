<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Data\SuspectTranscriptBlock;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionStructureFlagRederiver;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use App\Support\SectionReviewFlagPolicy;

/**
 * Ask a reviewer to look at a sermon whose delivered span contains text the
 * transcript itself contradicts {@see ServiceTranscriptRepetitionScreen}.
 *
 * The sibling of {@see FlagIncompleteSermonEvidence}, and the two answer
 * different questions about the same span. That one asks how much of the span
 * has *no* evidence; this one asks how much of it has evidence that cannot be
 * true. A run can pass the first and fail this one — indeed 88 of the 125
 * sermons the 2026-09-09 correctness review screened carried no section-level
 * hold at all, because their blind fraction was zero. A zero blind fraction
 * establishes that the decoder produced text, never that the text is real.
 *
 * On the section rather than the run for the same reason as its sibling: that is
 * where a reviewer meets the sermon, and it survives
 * {@see SectionStructureFlagRederiver}, which re-derives only
 * {@see ServiceStructureValidator::REANNOTATED_FLAGS}. A recompute that could
 * quietly withdraw this would repeat the invisibility it exists to end.
 *
 * Children's talks are held on the same rule and not by a separate one. The
 * review's §4368 — the George Washington Carver talk, which loops — carried no
 * flag at all, and the sermons table is polymorphic, so a rule that only knew
 * about {@see ServiceSectionType::Sermon} would hold the preaching and publish
 * the talk. Songs are deliberately out of scope: a loop over sung audio is
 * evidence about a *clip boundary*, which the song publication policy already
 * judges under P8-Q10/Q16, and holding all 545 of them here would bury the
 * spoken content this exists to protect.
 */
class FlagSuspectTranscriptRepetition
{
    /** Spoken content whose transcript loops or claims impossible word rates. */
    public const FLAG = 'transcript_repetition_suspect';

    /**
     * The section types whose published output is derived from transcript text,
     * and which therefore inherit a looping decode.
     *
     * @var list<ServiceSectionType>
     */
    private const HELD_TYPES = [ServiceSectionType::Sermon, ServiceSectionType::ChildrensTalk];

    /**
     * Raise or clear the hold across the run's spoken-content sections.
     *
     * Clearing is the half that keeps the flag meaningful: recovered audio is
     * exactly the event that makes a standing hold wrong, and this is the only
     * path by which a repaired sermon stops asking for review.
     *
     * Raised and withdrawn are counted separately because one run can do both:
     * a screen that finds a loop in the sermon may find none over a children's
     * talk that was held by an earlier pass, and reporting the two together
     * would read as though the talk had just been held.
     *
     * @param  list<SuspectTranscriptBlock>  $blocks  Every block screened from the run's transcript
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run, array $blocks): array
    {
        $sections = $run->serviceSections()
            ->whereIn('section_type', array_map(static fn (ServiceSectionType $type): string => $type->value, self::HELD_TYPES))
            ->orderBy('start_time')
            ->get();

        $deliveredSermonSpans = $run->recordedSermonExtractionSpans();
        $outcome = ['raised' => 0, 'withdrawn' => 0];

        foreach ($sections as $section) {
            $overlapping = $this->overlapping($blocks, $this->spansFor($section, $deliveredSermonSpans));

            if (! $this->hold($section, $overlapping)) {
                continue;
            }

            $outcome[$overlapping === [] ? 'withdrawn' : 'raised']++;
        }

        return $outcome;
    }

    /**
     * The spans the section's published output is cut from.
     *
     * A sermon takes its recorded extraction plan rather than its own bounds: a
     * concatenated cut joins the preached reading to the sermon and drops the
     * hymn between them, so a loop inside that hymn never reaches the listener
     * and must not hold the sermon. Every other held type is delivered as the
     * single interval it occupies.
     *
     * @param  list<array{start: float, end: float}>|null  $deliveredSermonSpans
     * @return list<array{start: float, end: float}>
     */
    private function spansFor(ServiceSection $section, ?array $deliveredSermonSpans): array
    {
        if ($section->section_type === ServiceSectionType::Sermon && $deliveredSermonSpans !== null) {
            return $deliveredSermonSpans;
        }

        return [['start' => (float) $section->start_time, 'end' => (float) $section->end_time]];
    }

    /**
     * @param  list<SuspectTranscriptBlock>  $blocks
     * @param  list<array{start: float, end: float}>  $spans
     * @return list<SuspectTranscriptBlock>
     */
    private function overlapping(array $blocks, array $spans): array
    {
        return array_values(array_filter($blocks, static function (SuspectTranscriptBlock $block) use ($spans): bool {
            foreach ($spans as $span) {
                if ($block->overlaps($span['start'], $span['end'])) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<SuspectTranscriptBlock>  $blocks
     * @return bool Whether the section changed.
     */
    private function hold(ServiceSection $section, array $blocks): bool
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $flags = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));

        $without = array_values(array_filter($flags, static fn (string $flag): bool => $flag !== self::FLAG));
        $suspect = $blocks !== [];
        $seconds = round(SuspectTranscriptBlock::coveredSeconds($blocks), 2);

        $metadata['review_flags'] = $suspect ? [...$without, self::FLAG] : $without;

        if ($suspect) {
            $metadata['transcript_repetition_seconds'] = $seconds;
            $metadata['transcript_repetition_blocks'] = array_map(
                static fn (SuspectTranscriptBlock $block): array => $block->toArray(),
                $blocks,
            );
        } else {
            unset($metadata['transcript_repetition_seconds'], $metadata['transcript_repetition_blocks']);
        }

        $needsReview = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        if ($this->alreadyRecorded($section, $suspect, $seconds, count($blocks), $needsReview)) {
            return false;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = $needsReview;
        $section->save();

        return true;
    }

    /**
     * Whether the section already says exactly this.
     *
     * Compared field by field rather than by array equality, because stored
     * metadata has been through JSON and a `round()` result of 242.0 returns as
     * the integer 242 — an array comparison would then report a change on every
     * pass while writing nothing new.
     */
    private function alreadyRecorded(
        ServiceSection $section,
        bool $suspect,
        float $seconds,
        int $blockCount,
        bool $needsReview,
    ): bool {
        $metadata = $section->metadata?->toArray() ?? [];
        $flags = is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [];

        if (in_array(self::FLAG, $flags, true) !== $suspect || $needsReview !== $section->needs_manual_review) {
            return false;
        }

        if (! $suspect) {
            return ! array_key_exists('transcript_repetition_seconds', $metadata)
                && ! array_key_exists('transcript_repetition_blocks', $metadata);
        }

        $recorded = is_array($metadata['transcript_repetition_blocks'] ?? null)
            ? $metadata['transcript_repetition_blocks']
            : [];

        return (float) ($metadata['transcript_repetition_seconds'] ?? -1) === $seconds
            && count($recorded) === $blockCount;
    }
}
