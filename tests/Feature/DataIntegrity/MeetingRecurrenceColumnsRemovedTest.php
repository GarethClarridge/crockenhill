<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingRecurrenceColumnsRemovedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recurrence_columns_are_absent_from_the_meetings_table(): void
    {
        $this->assertFalse(Schema::hasColumn('meetings', 'meeting_date'));
        $this->assertFalse(Schema::hasColumn('meetings', 'is_recurring'));
        $this->assertFalse(Schema::hasColumn('meetings', 'frequency'));
    }
}
