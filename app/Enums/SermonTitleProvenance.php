<?php

declare(strict_types=1);

namespace App\Enums;

enum SermonTitleProvenance: string
{
    case Generated = 'generated';
    case AiAnalysis = 'ai_analysis';
    case Curated = 'curated';
}
