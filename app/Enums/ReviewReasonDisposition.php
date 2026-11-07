<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an actionable review reason stands before the next round (video plan §4.1d RA1).
 * Only the first two take the reason out of human work; a section leaves the queue only
 * when every one of its reasons has.
 */
enum ReviewReasonDisposition: string
{
    /** The stored evidence already shows the reason no longer holds. */
    case ResolvedByCurrentEvidence = 'resolved_by_current_evidence';

    /** A built but unapplied fix changes this section's output or structure; verify after the round. */
    case ResolvedByPendingApplication = 'resolved_by_pending_application';

    /** An existing automatic stage can repair or re-evidence it (RA2–RA5). */
    case AutomaticRepair = 'automatic_repair';

    /** A ruling reserves the decision for a person. */
    case HumanDecision = 'human_decision';

    /** The input the decision depends on is missing; it is named on the record. */
    case Unassessable = 'unassessable';

    public function clearsWithoutHumanWork(): bool
    {
        return match ($this) {
            self::ResolvedByCurrentEvidence, self::ResolvedByPendingApplication => true,
            default => false,
        };
    }
}
