<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CalendarEvent;

readonly class CalendarCategorizationResult
{
    public function __construct(
        public readonly CalendarEvent $event,
        public readonly bool $googleSynced,
    ) {}
}
