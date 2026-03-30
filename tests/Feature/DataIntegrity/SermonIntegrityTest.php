<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SermonIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_date_index()
    {
        $this->assertTrue(
            collect(Schema::getIndexes('sermons'))->contains('name', 'sermons_date_index'),
            'Index sermons_date_index missing on sermons table'
        );
    }
}
