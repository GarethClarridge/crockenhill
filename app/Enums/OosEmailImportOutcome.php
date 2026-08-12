<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Per-plan outcome when importing a (possibly multi-service) inbound OoS email.
 *
 * Terminal outcomes leave nothing outstanding for the plan; {@see self::HeldForReview}
 * and {@see self::Failed} mean the plan still needs attention.
 */
enum OosEmailImportOutcome: string
{
    use HasValues;

    case Created = 'created';
    case Merged = 'merged';
    case EvidenceRetained = 'evidence_retained';
    case HeldForReview = 'held_for_review';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Created, self::Merged, self::EvidenceRetained => true,
            self::HeldForReview, self::Failed => false,
        };
    }
}
