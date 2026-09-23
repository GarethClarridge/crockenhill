<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Data\ServiceSectionMetadata;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\ContentHoldRechecker;
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
 * **What each hold records.** Its reason and evidence, the check that found it
 * ({@see ContentHoldCheck}), and the content it was found on: the span, the bound
 * item and a fingerprint of the transcript the check read. A re-detection carries
 * the record to whichever section now holds that content, and after a repair
 * {@see ContentHoldRechecker} re-runs a check that exists in code and clears the
 * record, saying why, when it passes. Decisions and judgements stay live.
 *
 * Released by an operator through {@see ConfirmServiceSection}, which strips every
 * review flag, or when every live record has been cleared by its check. The
 * records stay under {@see self::METADATA_KEY} as history.
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
        ServiceSectionType::ShortTalk,
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
     * Records are copied whole, so what found each one travels with it. Cleared
     * and released records stay that way: re-raising them would undo a check's or
     * an operator's decision.
     */
    public function carry(ServiceSection $from, ServiceSection $to): void
    {
        $metadata = $from->metadata?->toArray() ?? [];

        if (! self::isHeld(self::reviewFlagsIn($metadata))) {
            return;
        }

        $target = $to->metadata?->toArray() ?? [];
        $targetFlags = self::reviewFlagsIn($target);

        $target[self::METADATA_KEY] = self::mergeRecords(self::holdsIn($target), self::holdsIn($metadata));
        $target['review_flags'] = self::isHeld($targetFlags) ? $targetFlags : [...$targetFlags, self::FLAG];

        $this->persist($to, $target);
    }

    /**
     * Whether a record still holds its content: neither cleared by its check nor
     * released with its section by an operator.
     *
     * @param  array<string, mixed>  $record
     */
    public static function isLive(array $record): bool
    {
        return ! isset($record['cleared_at']) && ! isset($record['released_at']);
    }

    /**
     * Records keyed by what they claim, the first copy kept.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    public static function mergeRecords(array $records, array $incoming): array
    {
        $merged = [];

        foreach ([...$records, ...$incoming] as $record) {
            $merged[json_encode([$record['reason'] ?? null, $record['evidence'] ?? null], JSON_THROW_ON_ERROR)] ??= $record;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    public static function holdsIn(array $metadata): array
    {
        return array_values(array_filter(
            is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [],
            'is_array',
        ));
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
     * Raise the hold, returning whether the section changed.
     *
     * @throws InvalidArgumentException when the section's type cannot be refused at
     *                                  release, or the hold cannot be explained
     */
    public function __invoke(ServiceSection $section, string $reason, string $evidence, ContentHoldCheck $foundBy): bool
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

        if ($alreadyRecorded) {
            $holds = array_map(
                static fn (array $hold): array => ($hold['reason'] ?? null) === $reason && ($hold['evidence'] ?? null) === $evidence
                    ? array_diff_key($hold, array_flip(['cleared_at', 'cleared_by', 'cleared_reason', 'released_at']))
                    : $hold,
                $holds,
            );
        } else {
            $holds[] = [
                'reason' => $reason,
                'evidence' => $evidence,
                'held_at' => now()->toIso8601String(),
                'found_by' => $foundBy->value,
                'start_time' => (float) $section->start_time,
                'end_time' => (float) $section->end_time,
                'church_service_item_id' => $section->church_service_item_id,
                'transcript_sha256' => $section->processingLog->serviceTranscriptSha256(),
            ];
        }

        $metadata['review_flags'] = self::isHeld($flags) ? $flags : [...$flags, self::FLAG];
        $metadata[self::METADATA_KEY] = $holds;

        $this->persist($section, $metadata);

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function persist(ServiceSection $section, array $metadata): void
    {
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );
        $section->save();
    }
}
