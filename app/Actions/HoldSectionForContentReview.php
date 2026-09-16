<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionReviewFlagRecalculator;
use App\Services\ChurchService\SectionStructureFlagRederiver;
use App\Services\Import\HistoricReleaseReviewHolds;
use App\Support\SectionReviewFlagPolicy;
use InvalidArgumentException;

/**
 * Hold a section whose content an operator has proven wrong, where no automatic
 * screen can see the defect.
 *
 * Every other hold on a section is raised by a detector, so it can only contain
 * what that detector finds. The 2026-09-10/11 correctness review proved defects
 * precisely where the detectors are blind: five sermon transcripts that loop fewer
 * than five times, a prayer and a spoken introduction absorbed into song clips,
 * and a clip that joins two songs. Waiting for each detector to improve would
 * leave audio-confirmed defects releasable in the meantime.
 *
 * **Only types the release gate reads directly can be held.**
 * {@see HistoricReleaseReviewHolds} refuses a sermon on a hold on its own sermon
 * or children's-talk section, and a song video on a hold on its own song section.
 * A hold on a reading or a prayer refuses nothing unless it carries a
 * span-questioning flag, so accepting one here would record containment that does
 * not exist.
 *
 * **Why it survives the recomputes.** The flag is one
 * {@see SectionReviewFlagPolicy} does not demote, so a recompute that re-weighs
 * stored flags keeps the section held; {@see SectionStructureFlagRederiver} copies
 * flags it does not own across untouched; and the paths that settle a different
 * question (a song retyped as a spoken announcement, a children's-talk speaker
 * named) ask {@see self::isHeld()} rather than forcing the column false.
 * {@see SectionReviewFlagRecalculator} is the regression case for the first.
 *
 * Released only by an operator: {@see ConfirmServiceSection} strips every review
 * flag. The recorded reasons stay under {@see self::METADATA_KEY} as history.
 */
class HoldSectionForContentReview
{
    /** An operator proved this section's content wrong. */
    public const FLAG = 'content_defect_hold';

    /** The reason and evidence behind each hold, kept after release. */
    public const METADATA_KEY = 'content_holds';

    /**
     * @var list<ServiceSectionType>
     */
    public const HOLDABLE_TYPES = [
        ServiceSectionType::Sermon,
        ServiceSectionType::ChildrensTalk,
        ServiceSectionType::Song,
    ];

    /**
     * @param  array<int, mixed>  $reviewFlags
     */
    public static function isHeld(array $reviewFlags): bool
    {
        return in_array(self::FLAG, $reviewFlags, true);
    }

    public static function canHold(ServiceSection $section): bool
    {
        return in_array($section->section_type, self::HOLDABLE_TYPES, true);
    }

    /**
     * Carry a removed section's live holds onto the section that replaces it.
     *
     * A merge keeps one section and deletes the other, and the survivor takes the
     * removed section's span with it. Its holds have to travel too: the review
     * column is merged by both mergers, but the flag and the recorded reasons were
     * left on the row being deleted, so a later confirmation would have released
     * content nobody settled.
     *
     * Released holds stay released — their reasons are history on the row that is
     * going away, and re-raising them here would undo an operator's decision.
     */
    public function carry(ServiceSection $from, ServiceSection $to): void
    {
        $metadata = $from->metadata?->toArray() ?? [];

        if (! self::isHeld(self::reviewFlagsIn($metadata))) {
            return;
        }

        foreach (self::holdsIn($metadata) as $hold) {
            $reason = $hold['reason'] ?? null;
            $evidence = $hold['evidence'] ?? null;

            if (is_string($reason) && is_string($evidence)) {
                $this($to, $reason, $evidence);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private static function reviewFlagsIn(array $metadata): array
    {
        return array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private static function holdsIn(array $metadata): array
    {
        return array_values(array_filter(
            is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [],
            'is_array',
        ));
    }

    /**
     * Raise the hold, returning whether the section changed.
     *
     * @throws InvalidArgumentException when the section's type cannot be refused at
     *                                  release, or the hold cannot be explained
     */
    public function __invoke(ServiceSection $section, string $reason, string $evidence): bool
    {
        if (! self::canHold($section)) {
            throw new InvalidArgumentException(
                "Section {$section->id} is a {$section->section_type->value}; the release gate does not refuse on a hold there.",
            );
        }

        $reason = trim($reason);
        $evidence = trim($evidence);

        if ($reason === '' || $evidence === '') {
            throw new InvalidArgumentException('A content hold needs both a reason and an evidence reference.');
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $flags = self::reviewFlagsIn($metadata);
        $holds = self::holdsIn($metadata);

        $alreadyRecorded = array_filter(
            $holds,
            static fn (array $hold): bool => ($hold['reason'] ?? null) === $reason
                && ($hold['evidence'] ?? null) === $evidence,
        ) !== [];

        if ($alreadyRecorded && self::isHeld($flags) && $section->needs_manual_review) {
            return false;
        }

        if (! $alreadyRecorded) {
            $holds[] = [
                'reason' => $reason,
                'evidence' => $evidence,
                'held_at' => now()->toIso8601String(),
            ];
        }

        $metadata['review_flags'] = self::isHeld($flags) ? $flags : [...$flags, self::FLAG];
        $metadata[self::METADATA_KEY] = $holds;

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );
        $section->save();

        return true;
    }
}
