<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WardenSermonDurationIndexTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function test_it_has_an_index_on_sermons_duration(): void
    {
        $this->assertTrue(
            Schema::hasIndex('sermons', 'sermons_duration_index'),
            'Index sermons_duration_index does not exist on sermons table.'
        );
    }
}
