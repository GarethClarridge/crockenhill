<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How far a defect class from §4.3a's table has travelled towards a pipeline
 * response.
 *
 * {@see DecidedNotToDetect} is the reason this is an enum rather than a boolean
 * on the catalogue. A class nobody has ruled on and a class an operator has
 * deliberately decided not to detect look identical from the outside — both have
 * no detector — but only one of them is an open question. Recording the decision
 * as a status keeps §4.3a's "tested change **or** a recorded decision not to
 * detect" honest: the entry carries the decision text and the date, so the
 * distinction survives the next person reading the table.
 */
enum DetectorStatus: string
{
    use HasValues;

    /** In the pipeline, emitting its signals, and subject to H10 evaluation. */
    case Promoted = 'promoted';

    /** Works as a one-off script under `storage/scratch`; not in the pipeline. */
    case Prototype = 'prototype';

    /** An operator ruled that this class is not worth detecting. Carries the decision. */
    case DecidedNotToDetect = 'decided_not_to_detect';

    /**
     * The class was eliminated in code and cannot recur, so there is nothing to
     * detect. Carries the fix and its commit.
     *
     * This is deliberately not {@see DecidedNotToDetect}. Both end with no
     * detector, but they are different claims: one says the defect can no longer
     * happen, the other says it can and we have chosen to live with it. Folding
     * them together would let genuinely unguarded classes hide among fixed ones,
     * which is the failure this whole enum exists to prevent.
     *
     * Its evidence is a regression test, not a signal, so a fixed class carries
     * its cases in `regressionCases` and emits nothing.
     */
    case FixedAtSource = 'fixed_at_source';

    /** Known class, no response yet. The open state. */
    case Unbuilt = 'unbuilt';

    public function label(): string
    {
        return match ($this) {
            self::Promoted => 'Promoted',
            self::Prototype => 'Prototype',
            self::DecidedNotToDetect => 'Decided not to detect',
            self::FixedAtSource => 'Fixed at source',
            self::Unbuilt => 'Unbuilt',
        };
    }

    /**
     * Whether this status obliges the entry to name the class that emits it.
     */
    public function requiresOwningClass(): bool
    {
        return $this->emitsSignals();
    }

    /**
     * Whether an entry in this status puts signals on a surface.
     *
     * The answer governs the catalogue in both directions. A status that emits
     * must name its surface and its signals, or no adapter could ever match it;
     * a status that does not must name neither, or the catalogue would claim a
     * detector where the plan records a fix, a ruling or an open question.
     *
     * Only {@see Promoted} qualifies, and {@see Prototype} deliberately does
     * not: a script under `storage/scratch` writes nothing to any of the four
     * surfaces the harness reads, so giving it signals would put a detector in
     * the catalogue that no adapter can ever find.
     */
    public function emitsSignals(): bool
    {
        return $this === self::Promoted;
    }

    /**
     * Whether an entry in this status must record why it has no detector.
     *
     * An unbuilt class is the open state and explains itself. A ruling and a fix
     * are both assertions that no detector is needed, and an assertion with no
     * stated grounds is indistinguishable from nobody having looked.
     */
    public function requiresDecision(): bool
    {
        return $this === self::DecidedNotToDetect || $this === self::FixedAtSource;
    }
}
