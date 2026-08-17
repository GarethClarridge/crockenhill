<?php

declare(strict_types=1);

namespace App\Data;

/**
 * How one adjudicated source email divided the two evaluation arms.
 *
 * The verdict is about faithfulness to the verbatim email and nothing else: whether the arm
 * reproduced the service boundaries, identities, content scope, ordered items, item types and
 * source-line bindings the email itself states. Agreement with what was later sung or projected is
 * a different question and never decides a verdict here.
 *
 * Four classes rather than two, because a raw disagreement can also be **both arms wrong,
 * differently**. Collapsing that into "the other arm won" is what makes `b + c` look like raw
 * discordance `M` when it is only ever `≤ M`.
 */
enum OosSourceFaithfulnessVerdict: string
{
    /** Both arms reproduced the email. Cell `a`. */
    case BothFaithful = 'both_faithful';

    /** Only the baseline reproduced the email. Cell `c`. */
    case BaselineOnlyFaithful = 'baseline_only_faithful';

    /** Only the candidate reproduced the email. Cell `b`. */
    case CandidateOnlyFaithful = 'candidate_only_faithful';

    /** Neither arm reproduced the email, whether identically or differently. Cell `d`. */
    case NeitherFaithful = 'neither_faithful';

    public function baselineFaithful(): bool
    {
        return $this === self::BothFaithful || $this === self::BaselineOnlyFaithful;
    }

    public function candidateFaithful(): bool
    {
        return $this === self::BothFaithful || $this === self::CandidateOnlyFaithful;
    }
}
