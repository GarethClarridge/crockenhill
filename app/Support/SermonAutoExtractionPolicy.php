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
     * Flags that mark OoS ordering/alignment concerns rather than boundary
     * quality; these alone do not block auto-extraction.
     */
    private const NON_DISQUALIFYING_REVIEW_FLAGS = [
        ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION,
    ];

    /**
     * @param  array<int, string>  $reviewFlags
     */
    public static function reviewStatePermitsAutoExtraction(bool $needsManualReview, array $reviewFlags): bool
    {
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
