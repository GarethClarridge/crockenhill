<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CalendarEvent;
use App\Services\CalendarCategorizationResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarCategorizationResultTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_can_be_instantiated_with_event_and_sync_status(): void
    {
        $event = CalendarEvent::factory()->create();

        $result = new CalendarCategorizationResult($event, true);

        $this->assertSame($event, $result->event);
        $this->assertTrue($result->googleSynced);
    }

    #[Test]
    public function it_correctly_stores_google_synced_false(): void
    {
        $event = CalendarEvent::factory()->create();

        $result = new CalendarCategorizationResult($event, false);

        $this->assertSame($event, $result->event);
        $this->assertFalse($result->googleSynced);
    }
}
