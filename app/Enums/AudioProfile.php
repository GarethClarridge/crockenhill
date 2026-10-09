<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a cut's sound is treated (VIDEO-BOUNDARY-CONSISTENCY §6.3.1).
 *
 * Speech (sermons, their readings, short talks) is mixed to one channel and every
 * part brought to the target loudness on its own, so a quiet reading is not
 * averaged away by a long sermon. Music keeps its channels and its dynamics.
 */
enum AudioProfile: string
{
    use HasValues;

    case Speech = 'speech';
    case Music = 'music';
}
