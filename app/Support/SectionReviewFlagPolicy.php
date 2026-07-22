<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;

/**
 * Canonical rule for whether a section's structure review flags still warrant
 * manual review, given the section's type.
 *
 * Review flags fall into three buckets:
 *  - Always demoted: OoS cross-type inversion questions which OoS *item* a
 *    section aligns to, never the section's own quality.
 *  - Demoted on non-structural-uncertainty types (welcome/notices/prayer/other):
 *    low-confidence, micro-section, and — per OD-1 — OoS structure mismatch.
 *    For this filler the exact type has no downstream (publishing) effect, so a
 *    boundary/ordering doubt implies no operator action.
 *  - Everything else forces review.
 *
 * Shared by the detection path (ServiceStructure::toClassifiedSections) and the
 * backfill/cleanup commands so stored and freshly-detected sections agree.
 */
class SectionReviewFlagPolicy
{
    /**
     * Flags that never force manual review regardless of section type.
     *
     * @var array<int, string>
     */
    private const ALWAYS_DEMOTED_FLAGS = [
        ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION,
    ];

    /**
     * Flags that force manual review only on section types whose structural
     * uncertainty has a downstream effect (song/sermon/reading/children's talk).
     *
     * @var array<int, string>
     */
    private const CONDITIONALLY_DEMOTED_FLAGS = [
        ServiceStructureValidator::FLAG_LOW_CONFIDENCE,
        ServiceStructureValidator::FLAG_MICRO_SECTION,
        ServiceStructureValidator::FLAG_OOS_STRUCTURE_MISMATCH,
    ];

    /**
     * @param  array<int, string>  $reviewFlags
     */
    public static function requiresManualReview(ServiceSectionType $type, array $reviewFlags): bool
    {
        $requiresStructuralReview = $type->requiresStructuralUncertaintyReview();

        foreach ($reviewFlags as $reviewFlag) {
            if (in_array($reviewFlag, self::ALWAYS_DEMOTED_FLAGS, true)) {
                continue;
            }

            if (
                in_array($reviewFlag, self::CONDITIONALLY_DEMOTED_FLAGS, true)
                && ! $requiresStructuralReview
            ) {
                continue;
            }

            return true;
        }

        return false;
    }
}
