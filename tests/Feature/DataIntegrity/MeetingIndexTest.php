<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeetingIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_an_index_on_the_updated_at_column_of_the_meetings_table(): void
    {
        $this->assertTrue(
            Schema::hasIndex('meetings', ['updated_at']),
            'The meetings table is missing an index on the updated_at column.'
        );
    }
}
