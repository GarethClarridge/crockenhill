<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What one defect of a detector's class is counted in.
 *
 * Recall needs a denominator, and the detectors do not share one: a transcript
 * loop is a stretch of time, a mistyped section is a section, a dead picture is
 * a whole sermon's recording. "Recall ≥ 0.95" therefore means a different thing
 * for each, and averaging them would produce a number with no referent —
 * defective minutes and defective sermons are not addable.
 *
 * So the unit is declared per detector and reported with every rate, and the
 * harness refuses to aggregate recall across detectors that do not share one.
 * Severity still sets the bar; the unit says what the bar is measured in.
 */
enum DetectorUnit: string
{
    use HasValues;

    /** One processing run: the whole service as it was processed. */
    case Run = 'run';

    /** One detected section of a service. */
    case Section = 'section';

    /** One published sermon and its recording. */
    case Sermon = 'sermon';

    /**
     * One minute of transcript.
     *
     * The transcript screens report spans rather than whole-run verdicts, and
     * H10's window sampling bounds defective minutes rather than defective runs,
     * so counting these per run would claim a precision the sampling cannot
     * support.
     */
    case Minute = 'minute';

    public function label(): string
    {
        return match ($this) {
            self::Run => 'Run',
            self::Section => 'Section',
            self::Sermon => 'Sermon',
            self::Minute => 'Transcript minute',
        };
    }
}
