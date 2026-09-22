<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Whether a case book entry holds its detector's defect.
 *
 * Clean cases matter as much as defective ones: they are the only denominator a
 * false-positive rate has, such as the videos the old dead-picture detector
 * rejected that review found fine.
 */
enum DetectorCaseTruth: string
{
    use HasValues;

    case Defective = 'defective';

    case Clean = 'clean';
}
