<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarRoutesRemovedTest extends TestCase
{
    #[Test]
    public function duplicate_calendar_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('calendar.uncategorized'));
        $this->assertFalse(Route::has('admin.calendar.uncategorized'));
        $this->assertFalse(Route::has('admin.calendar.categorize'));
        $this->assertFalse(Route::has('admin.calendar.patterns'));
        $this->assertFalse(Route::has('admin.calendar.sync'));
    }
}
