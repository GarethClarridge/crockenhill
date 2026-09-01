<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ChurchService\Structure\ServiceStructureValidator;

/**
 * Decides whether a section's review state still permits automatic sermon
 * extraction.
 *
 * Review flags fall into two classes. Boundary-quality flags (low confidence,
 * micro-section, benediction-suspect) question whether the section's times are
 * right, so auto-extracting from them could publish the wrong audio — they
 * disqualify. Ordering flags question which OoS *item* a section is aligned
 * to, which OpenLP's grouped-by-type exports trip constantly; they say nothing
 * about the section's boundaries, so they must not demote extraction to the
 * coarser RMS baseline (which routed the 2026-07-05 corpus run to manual
 * review despite a validation-passing structure).
 *
 * Shared by SermonExtractionPlanResolver (persisted sections) and
 * DetectServiceStructure's sermon-bounds write-back (classified payloads) so
 * the two stay in lockstep.
 */
class SermonAutoExtractionPolicy
{
    /**
     * A merged interruption disqualifies extraction even when a caller has not
     * yet copied the flag into `needs_manual_review`: structure reconciliation
     * found the section itself unsound, so there is no span worth cutting.
     *
     * FLAG_SERMON_BOUNDARY_MATERIAL_RISK is deliberately absent. That flag says
     * a human should look at where the sermon ends, not that the inclusive span
     * is wrong -- the recorded policy is to preserve it and review afterwards.
     * Disqualifying on it would make a replay of a flagged run refuse to find
     * any sermon section at all.
     *
     * @var array<int, string>
     */
    private const MATERIAL_BOUNDARY_FLAGS = [
        ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
    ];

    /**
     * Flags that ask a human to look without disputing the span itself; these
     * alone do not block auto-extraction. A missing preached reading questions
     * what surrounds the sermon, not the sermon's own boundaries — extraction of
     * the sermon span is still right. A material boundary risk is the same
     * shape: the policy is to publish the inclusive span and let a reviewer
     * decide afterwards, so refusing to extract would leave nothing to review.
     */
    private const NON_DISQUALIFYING_REVIEW_FLAGS = [
        ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION,
        ServiceStructureValidator::FLAG_OOS_SAME_TYPE_INVERSION,
        ServiceStructureValidator::FLAG_MISSING_PREACHED_READING,
        ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK,
    ];

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
