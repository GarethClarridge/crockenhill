<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingDateIndexTest extends TestCase
{
    #[Test]
    public function the_meetings_table_has_an_index_on_meeting_date(): void
    {
        $this->assertTrue(Schema::hasTable('meetings'), 'Meetings table does not exist.');

        $indexes = DB::select('SHOW INDEX FROM meetings');
        $hasIndex = collect($indexes)->contains(function ($index) {
            return $index->Column_name === 'meeting_date';
        });

        $this->assertTrue($hasIndex, 'Meetings table is missing an index on the meeting_date column.');
    }
}
