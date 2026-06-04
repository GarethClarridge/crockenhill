<?php

declare(strict_types=1);

namespace App\Enums;

enum ChurchServiceReviewState: string
{
    case NotReviewed = 'not_reviewed';
    case Reviewed = 'reviewed';
    case Reopened = 'reopened';
}
