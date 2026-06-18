<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceItemLivestreamIndexTest extends TestCase
{
    #[Test]
    public function it_has_an_explicit_index_on_livestream_service_section_id(): void
    {
        $this->assertTrue(
            Schema::hasIndex('church_service_items', 'church_service_items_livestream_service_section_id_index'),
            'church_service_items table is missing an explicit index on livestream_service_section_id column.'
        );
    }
}
