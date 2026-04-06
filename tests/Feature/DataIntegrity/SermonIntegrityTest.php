<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_date_index(): void
    {
        $this->assertTrue(
            collect(Schema::getIndexes('sermons'))->contains('name', 'sermons_date_index'),
            'Index sermons_date_index missing on sermons table'
        );
    }
}
