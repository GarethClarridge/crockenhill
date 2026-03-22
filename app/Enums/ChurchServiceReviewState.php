<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceReviewState: string
{
    case NOT_REVIEWED = 'not_reviewed';
    case REVIEWED = 'reviewed';
    case REOPENED = 'reopened';
}
