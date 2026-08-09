<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricImportDisposition: string
{
    case Pending = 'pending';
    case InFlight = 'in_flight';
    case ExactComplete = 'exact_complete';
    case ExactAlreadyPresent = 'exact_already_present';
    case ApprovedExcluded = 'approved_excluded';
    case HeldForReview = 'held_for_review';
    case SourceIntegrityFailed = 'source_integrity_failed';
    case ProcessingFailed = 'processing_failed';
    case TimedOut = 'timed_out';
    case Interrupted = 'interrupted';
    case Unaccounted = 'unaccounted';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::InFlight], true);
    }

    public function satisfiesCloseout(HistoricImportItemExpectation $expectation): bool
    {
        return match ($expectation) {
            HistoricImportItemExpectation::Process => in_array(
                $this,
                [self::ExactComplete, self::ExactAlreadyPresent],
                true,
            ),
            HistoricImportItemExpectation::Exclude => $this === self::ApprovedExcluded,
        };
    }
}
