<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;

/**
 * Hold spoken content whose saved text was derived from a full-service
 * transcript the run no longer holds.
 *
 * Raised where {@see FlagSuspectTranscriptRepetition} is withdrawn, and that
 * pairing is the point. Repetition recovery rewrites the *full-service*
 * transcript, so the loop is genuinely gone and the repetition hold genuinely no
 * longer applies — but the sermon text a reader sees was sliced from the old
 * transcript and still contains every repetition. Withdrawing one hold without
 * raising the other would take a third of the review queue to zero while
 * changing nothing anybody reads.
 *
 * Cleared by {@see CreateSermonTranscriptFromService}, which is the writer that
 * re-slices the text and so the only event that makes this false. That is
 * deliberately a different trigger from the one that raises it: recovery settles
 * the evidence, regeneration settles the text.
 *
 * Separating those triggers was right; treating "wait for P8-Q15" as a
 * corpus-wide gate on the second one was not. Measured 2026-09-09: of the 149
 * runs owed a re-derivation only **2** contain a P8-Q15 section, and **91** carry
 * no span doubt anywhere on the run — no macro, micro, boundary, interruption,
 * low-confidence, repetition or incomplete-evidence hold. Those 91 can be
 * re-sliced from spans that are already settled. The gate is per-run and
 * answerable from the data, not a single corpus-wide wait.
 */
class FlagSermonTextPredatesEvidence
{
    /** Saved text sliced from a transcript this run has since replaced. */
    public const FLAG = 'sermon_text_predates_evidence';

    /**
     * @var list<ServiceSectionType>
     */
    private const HELD_TYPES = [ServiceSectionType::Sermon, ServiceSectionType::ChildrensTalk];

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run): array
    {
        $owed = $run->sermonDerivationIsOwed();
        $outcome = ['raised' => 0, 'withdrawn' => 0];

        $sections = $run->serviceSections()
            ->whereIn('section_type', array_map(static fn (ServiceSectionType $type): string => $type->value, self::HELD_TYPES))
            ->orderBy('start_time')
            ->get();

        foreach ($sections as $section) {
            if ($this->apply($section, $owed)) {
                $outcome[$owed ? 'raised' : 'withdrawn']++;
            }
        }

        return $outcome;
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
