<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\CalendarEvent;

readonly class CalendarCategorizationResult
{
    public function __construct(
        public CalendarEvent $event,
        public bool $googleSynced,
    ) {}
}
