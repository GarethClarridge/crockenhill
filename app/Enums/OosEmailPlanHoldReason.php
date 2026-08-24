<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a service plan was not auto-importable, recorded by the parser that decided it rather
 * than reconstructed downstream from reason strings.
 *
 * The archive report's hold census is built from these. Reconstructing the census by matching
 * validation-reason text conflated F65 bookkeeping holds with attempt-disagreement holds and
 * reported a failed corrective API call as a five-field parser disagreement, so the producer
 * that knows the answer records it.
 */
enum OosEmailPlanHoldReason: string
{
    use HasValues;

    /** A reason that impeaches the extracted order itself (F65 content subset). */
    case ContentInvalid = 'content_invalid';

    /** The extraction produced no items at all. */
    case NoItems = 'no_items';

    /** Bookkeeping only: an unaccounted line, an ignored aside — never an invalid order (F65). */
    case Bookkeeping = 'bookkeeping';

    /** Two structurally valid attempts disagreed, whether or not adjudication resolved them. */
    case AttemptDisagreement = 'attempt_disagreement';

    /** The email did not assert whether its order is complete. */
    case UnknownContentScope = 'unknown_content_scope';

    /** No usable service/date pair to import against. */
    case MissingIdentity = 'missing_identity';

    /**
     * A finding reduced this plan's confidence below the configured review threshold.
     *
     * Not the auto-import threshold, which it was compared against until 2026-08-24: the semantic
     * compiler's 0.75 is a ceiling that findings cap downwards, so against 0.90 this reason was
     * true of every plan and distinguished nothing.
     */
    case LowConfidence = 'low_confidence';

    /**
     * Whether this reason describes the extraction itself rather than the identity resolved for
     * it. Archive identity resolution can supply a manifest date, confidence and content scope,
     * so the identity-owned reasons are recomputed after it runs while these survive unchanged —
     * a merged line or an attempt disagreement is not undone by naming the service.
     */
    public function ownedByExtraction(): bool
    {
        return match ($this) {
            self::ContentInvalid, self::NoItems, self::Bookkeeping, self::AttemptDisagreement => true,
            self::UnknownContentScope, self::MissingIdentity, self::LowConfidence => false,
        };
    }
}
