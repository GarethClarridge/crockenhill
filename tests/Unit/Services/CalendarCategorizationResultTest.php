<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\CalendarCategorizationResult;
use App\Models\CalendarEvent;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarCategorizationResultTest extends TestCase
{
    #[Test]
    public function it_can_be_instantiated_with_event_and_sync_status(): void
    {
        $event = new CalendarEvent(['title' => 'Test Event', 'google_event_id' => 'evt_123']);

        $result = new CalendarCategorizationResult($event, true);

        $this->assertSame($event, $result->event);
        $this->assertTrue($result->googleSynced);
    }

    #[Test]
    public function it_correctly_stores_google_synced_false(): void
    {
        $event = new CalendarEvent(['title' => 'Test Event', 'google_event_id' => 'evt_456']);

        $result = new CalendarCategorizationResult($event, false);

        $this->assertSame($event, $result->event);
        $this->assertFalse($result->googleSynced);
    }
}
