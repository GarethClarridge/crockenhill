<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ChurchService\SectionStructureFlagRederiver;

/**
 * Review flags whose raise site has been deleted from the codebase.
 *
 * A flag is a question the pipeline is asking an operator. When the code that could ask it
 * is gone, the sections still carrying it are holding a question nobody can answer and
 * nothing will ever ask again — and because a flag is only ever cleared by the pass that
 * owns it, deleting the raise site leaves them held for ever. Four sections were still in
 * the live review queue on 2026-09-03 for exactly this reason.
 *
 * Listing one here is an assertion that no code path can produce it any more, so it is only
 * ever added alongside the search that proves it. Each entry names the commit that removed
 * its raiser:
 *
 *  - `reading_reference_conflict` — raised by `ResolveReadingReferences` when the reference
 *    the transcript yielded disagreed with the one printed in the order of service. That job
 *    was deleted with the heuristic pipeline in `01dd1dcd0` (2026-07-20).
 *  - `heuristic_demotion` — raised by the same heuristic classification branches, removed in
 *    the same commit. `ServiceReviewDashboardQuery` still reads it in order to render and
 *    gate it, which becomes unreachable once no row carries it.
 *
 * @see SectionStructureFlagRederiver
 */
class RetiredSectionReviewFlags
{
    /**
     * @var array<int, string>
     */
    public const ALL = [
        'reading_reference_conflict',
        'heuristic_demotion',
    ];

    public static function isRetired(string $reviewFlag): bool
    {
        return in_array($reviewFlag, self::ALL, true);
    }
}
