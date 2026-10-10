<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

/**
 * The mark on a question that asked about a talk edge every draft agreed on.
 *
 * From 2026-09-28 (plan DR6) such edges were asked, because agreement cannot see an error every
 * draft shares. All fifteen answered by canary 15 confirmed the talk, and the operator ruled on
 * 2026-10-10 that they are no longer asked. Questions banked before then still carry this mark
 * until their run is recomposed, so the review page and the batch report still recognise them.
 */
final class TalkEdgeChecks
{
    public const CHECK = 'talk_edge';
}
