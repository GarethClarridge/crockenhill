<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\SermonEvidenceCoverage;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionStructureFlagRederiver;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\SectionReviewFlagPolicy;

/**
 * Ask a reviewer to look at a sermon whose delivered span is materially blind
 * {@see SermonEvidenceCoverage::REVIEW_FRACTION}.
 *
 * The flag goes on the section rather than the run because that is where a
 * reviewer meets the sermon, and it survives
 * {@see SectionStructureFlagRederiver}, which
 * re-derives only {@see ServiceStructureValidator::REANNOTATED_FLAGS} and
 * retains everything else. That matters: an evidence flag a later recompute
 * could quietly withdraw would repeat the defect it exists to catch.
 *
 * Deliberately not a {@see ServiceStructureValidator} flag: those describe the
 * detector's confidence in a boundary, and are re-derived from the banked
 * structure. This one describes the recording behind the boundary, is raised
 * only once the extraction plan exists, and must persist until the evidence
 * itself is recovered.
 *
 * Shared because a sermon transcript has two writers. The pipeline job derives
 * it once, but any later re-derivation from a changed full-service transcript
 * derives it again — and a re-derivation that wrote the transcript without
 * re-asking this question would leave a materially blind sermon carrying no
 * flag, which is precisely the invisibility the measure exists to end.
 */
class FlagIncompleteSermonEvidence
{
    /** A sermon whose delivered span is materially blind. */
    public const FLAG = 'sermon_evidence_incomplete';

    /**
     * Raise the flag on the run's sermon section, or clear it when the evidence
     * no longer warrants review.
     *
     * Clearing matters as much as raising: recovered evidence is exactly the
     * event that makes a standing flag wrong, and a flag that only ever
     * accumulates stops meaning "look at this".
     *
     * @return bool Whether the section changed.
     */
    public function __invoke(MediaProcessingLog $run, SermonEvidenceCoverage $coverage): bool
    {
        $section = $run->serviceSections()
            ->where('section_type', ServiceSectionType::Sermon)
            ->orderBy('start_time')
            ->first();

        if (! $section instanceof ServiceSection) {
            return false;
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $flags = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));

        $without = array_values(array_filter($flags, static fn (string $flag): bool => $flag !== self::FLAG));
        $warrants = $coverage->warrantsReview();
        $fraction = round($coverage->unobservableFraction(), 4);
        $seconds = round($coverage->unobservableSeconds, 2);

        $metadata['review_flags'] = $warrants ? [...$without, self::FLAG] : $without;

        if ($warrants) {
            $metadata['sermon_evidence_unobservable_fraction'] = $fraction;
            $metadata['sermon_evidence_unobservable_seconds'] = $seconds;
        } else {
            unset($metadata['sermon_evidence_unobservable_fraction'], $metadata['sermon_evidence_unobservable_seconds']);
        }

        $needsReview = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );

        if ($this->alreadyRecorded($section, $warrants, $fraction, $seconds, $needsReview)) {
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
     * Compared field by field rather than by array equality, because the stored
     * metadata has been through JSON: a `round()` result of 242.0 comes back as
     * the integer 242, and a strict array comparison therefore reports a change
     * on every pass while writing nothing new.
     */
    private function alreadyRecorded(
        ServiceSection $section,
        bool $warrants,
        float $fraction,
        float $seconds,
        bool $needsReview,
    ): bool {
        $metadata = $section->metadata?->toArray() ?? [];
        $flags = is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [];

        if (in_array(self::FLAG, $flags, true) !== $warrants || $needsReview !== $section->needs_manual_review) {
            return false;
        }

        if (! $warrants) {
            return ! array_key_exists('sermon_evidence_unobservable_fraction', $metadata)
                && ! array_key_exists('sermon_evidence_unobservable_seconds', $metadata);
        }

        return (float) ($metadata['sermon_evidence_unobservable_fraction'] ?? -1) === $fraction
            && (float) ($metadata['sermon_evidence_unobservable_seconds'] ?? -1) === $seconds;
    }
}
