<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\TalkTypeInput;
use App\Data\TalkTypeProposal;

/**
 * Semantic assessment of a short-talk section the ordered rules could not decide.
 *
 * The result is a proposal only: it never carries bounds, item bindings, review or publication
 * instructions. A genuine provider rate limit propagates; every malformed, empty, refused or
 * truncated answer is returned as a `failed` proposal with no type and no confidence.
 */
interface TalkTypeClassifier
{
    /**
     * @param  TalkTypeProposal|null  $ruleProposal  the unresolved rule result whose evidence the model may weigh
     */
    public function classify(TalkTypeInput $input, ?TalkTypeProposal $ruleProposal = null): TalkTypeProposal;
}
