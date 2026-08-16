<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What an extracted song title's shape says about *who* can act on it.
 *
 * A single "unmatched title" count conflates four populations with four different owners, and
 * reading it as an extraction error rate overstates the parser's fault by roughly four to one on
 * the measured corpus. The verdict exists so that never happens again: it routes a title, it does
 * not score it. `SongTitleHygieneReport::$defects` remains the complete record — the verdict is
 * only the highest-precedence family that fired.
 */
enum SongTitleHygieneVerdict: string
{
    use HasValues;

    /**
     * No song identity is present in the text to extract — a placeholder, a choice deferred to a
     * named person, or an item that is not a song at all. Nothing a parser or a resolver change
     * can recover, and counting one of these as an extraction defect is simply wrong.
     */
    case NotATitle = 'not_a_title';

    /**
     * The text does not faithfully carry one intact song reference: it is truncated, it is the
     * tail of a hard-wrapped line, surrounding conversation bled into it, it was decoded from the
     * wrong character set, or it names two songs in one item. This is the population extraction
     * and normalisation work acts on.
     */
    case Defective = 'defective';

    /**
     * A faithful, intact song reference wrapped in decoration the resolver's own cleaning does not
     * reach — a role label, a bullet, a duration marker, an attribution suffix, markdown emphasis.
     * The extraction is correct; the *resolver's* coverage is the gap. These are the titles
     * `SongTitleHygiene::normalise()` is expected to recover.
     */
    case Decorated = 'decorated';

    /**
     * A bare, well-formed reference with nothing wrong with it. A clean title that still fails to
     * resolve is evidence about the *catalogue*, not about the extraction.
     */
    case Clean = 'clean';
}
