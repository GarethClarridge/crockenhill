<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Which line-accounting rule produced a bookkeeping finding.
 *
 * These are the rules behind {@see OosEmailPlanHoldReason::Bookkeeping}. Each fires against one
 * identified source line, and recording which rule and which line is what lets a census say what
 * a hold is *about* rather than only that a plan carries one.
 */
enum OosEmailStructuralFindingRule: string
{
    use HasValues;

    /** An ignored line was declared without saying why it was ignored. */
    case IgnoredLineWithoutReason = 'ignored_line_without_reason';

    /** One line is claimed as evidence or an item and also declared ignored. */
    case LineIgnoredAndClaimed = 'line_ignored_and_claimed';

    /** A line ignored *between* two extracted items, which may be an item the model dropped. */
    case LineIgnoredInsideItemSpan = 'line_ignored_inside_item_span';

    /** A line the extraction never accounted for as evidence, an item or ignored context. */
    case LineUnclassified = 'line_unclassified';
}
