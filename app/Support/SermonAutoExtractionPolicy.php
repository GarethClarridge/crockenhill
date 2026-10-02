<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\FlagPublishedReferenceContradictsSermon;
use App\Actions\FlagSermonAudioLengthMismatch;
use App\Actions\FlagSermonPartsNotExtracted;
use App\Actions\FlagSermonTextPredatesEvidence;
use App\Actions\HoldSectionForContentReview;
use App\Services\ChurchService\Structure\ServiceStructureValidator;

/**
 * Decides whether a section's review state still permits automatic sermon
 * extraction.
 *
 * Review flags fall into two classes. Boundary-quality flags (low confidence,
 * micro-section, benediction-suspect) question whether the section's times are
 * right, so auto-extracting from them could publish the wrong audio — they
 * disqualify. Ordering flags question which OoS *item* a section is aligned
 * to, which OpenLP's grouped-by-type exports can confuse; they do not
 * dispute the section boundaries. Unresolved membership and coverage park
 * extraction until reviewed; they never select alternate bounds.
 *
 * Shared by SermonExtractionPlanResolver (persisted sections) and
 * DetectServiceStructure's sermon-bounds write-back (classified payloads) so
 * the two stay in lockstep.
 */
class SermonAutoExtractionPolicy
{
    public const COMPOSITION_REVIEW_FLAG = 'sermon_composition_review_required';

    /**
     * A merged interruption disqualifies extraction even when a caller has not
     * yet copied the flag into `needs_manual_review`: structure reconciliation
     * found the section itself unsound, so there is no span worth cutting.
     *
     * @var array<int, string>
     */
    private const MATERIAL_BOUNDARY_FLAGS = [
        self::COMPOSITION_REVIEW_FLAG,
        ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
        ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK,
    ];

    /**
     * Flags that ask a human to look without disputing the span itself; these
     * alone do not block auto-extraction. A missing preached reading questions
     * what surrounds the sermon, not the sermon's own boundaries — extraction of
     * the sermon span is still right. Unresolved composition membership is handled separately before extraction.
     *
     * The last three are about the sermon's *text* and its *media*, and none
     * moves the span. Disqualifying on them inverts the repair each one asks for.
     *
     * Text, missing-parts and audio-length flags ask for regeneration from the
     * accepted sections. Blocking that regeneration would prevent their repair.
     * Content holds and material boundary risks remain separate gates.
     *
     * `published_reference_contradicts_sermon` questions the sermon's metadata, not
     * its cut, so it must never stop the media being extracted.
     *
     * `structure_talk_audio_dropout` is a dead feed inside the talk. Nothing can
     * restore it and the cut is not in question; the operator accepts or excludes
     * the talk with its media in hand.
     */
    private const NON_DISQUALIFYING_REVIEW_FLAGS = [
        ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION,
        ServiceStructureValidator::FLAG_OOS_SAME_TYPE_INVERSION,
        ServiceStructureValidator::FLAG_MISSING_PREACHED_READING,
        ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN,
        ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT,
        'transcript_repetition_suspect',
        FlagSermonTextPredatesEvidence::FLAG,
        FlagSermonPartsNotExtracted::FLAG,
        FlagSermonAudioLengthMismatch::FLAG,
        FlagPublishedReferenceContradictsSermon::FLAG,
    ];

    /**
     * Whether an operator's named repair may cut from a content-held section.
     *
     * The authority answers the hold and nothing else: every other flag on the
     * section is judged as it would be without the hold.
     *
     * @param  array<int, string>  $reviewFlags
     */
    public static function reviewStatePermitsHeldSpanRepair(array $reviewFlags): bool
    {
        if (! HoldSectionForContentReview::isHeld($reviewFlags)) {
            return false;
        }

        $remainingFlags = array_values(array_diff($reviewFlags, [HoldSectionForContentReview::FLAG]));

        return self::reviewStatePermitsAutoExtraction($remainingFlags !== [], $remainingFlags);
    }

    /**
     * @param  array<int, string>  $reviewFlags
     */
    public static function reviewStatePermitsAutoExtraction(bool $needsManualReview, array $reviewFlags): bool
    {
        if (array_intersect($reviewFlags, self::MATERIAL_BOUNDARY_FLAGS) !== []) {
            return false;
        }

        if (! $needsManualReview) {
            return true;
        }

        // Review requested without recorded structure flags (e.g. by an
        // operator or an older pipeline stage) — stay conservative.
        if ($reviewFlags === []) {
            return false;
        }

        return array_diff($reviewFlags, self::NON_DISQUALIFYING_REVIEW_FLAGS) === [];
    }
}
