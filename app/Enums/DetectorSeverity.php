<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a defect of this class does to someone reading the published service,
 * which is what decides how hard its detector has to work.
 *
 * Graded by consequence to the reader, never by which subsystem produced it: a
 * looping transcript and a wrongly identified song arrive through different code
 * but do the same damage, which is to publish something the service did not
 * contain.
 *
 * Deliberately carries no numbers. §4.3a's acceptance thresholds live in a
 * checked-in, hash-bound `detector-acceptance-thresholds.json` so that changing
 * one is a reviewable commit; putting them here — or in `config()` behind an
 * `env()` — would make a "predeclared" threshold something anyone could move
 * after seeing a candidate's results, which is the one thing predeclaration
 * exists to prevent.
 */
enum DetectorSeverity: string
{
    use HasValues;

    /** Wrong content published as fact: invented words, wrong song, wrong service. */
    case PublishedWrongContent = 's1';

    /** Real content lost from the output: a swallowed sermon opening, a truncated clip. */
    case ContentLost = 's2';

    /** Metadata or presentation wrong while the content itself is intact. */
    case WrongMetadata = 's3';

    /** Technical quality only: sample rates, container quirks, keyframe alignment. */
    case TechnicalQuality = 's4';

    public function label(): string
    {
        return match ($this) {
            self::PublishedWrongContent => 'S1 — published wrong content',
            self::ContentLost => 'S2 — content lost',
            self::WrongMetadata => 'S3 — wrong metadata',
            self::TechnicalQuality => 'S4 — technical quality',
        };
    }

    /**
     * Whether a detector at this severity is scored against recall and
     * false-positive thresholds at all.
     *
     * S4 is reporting-only by §4.3a's ruling: the defects are real but no hold
     * is warranted, so there is nothing for a recall floor to protect.
     */
    public function isScored(): bool
    {
        return $this !== self::TechnicalQuality;
    }
}
