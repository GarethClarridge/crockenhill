<?php

declare(strict_types=1);

namespace App\Enums;

enum LivestreamSegmentClassification: string
{
    case Song = 'song';
    case Speech = 'speech';
    case Silence = 'silence';
}
