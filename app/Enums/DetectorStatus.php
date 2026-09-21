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

    /** Known class, no response yet. The open state. */
    case Unbuilt = 'unbuilt';

    public function label(): string
    {
        return match ($this) {
            self::Promoted => 'Promoted',
            self::Prototype => 'Prototype',
            self::DecidedNotToDetect => 'Decided not to detect',
            self::Unbuilt => 'Unbuilt',
        };
    }

    /**
     * Whether this status obliges the entry to name the class that emits it.
     */
    public function requiresOwningClass(): bool
    {
        return $this === self::Promoted;
    }
}
