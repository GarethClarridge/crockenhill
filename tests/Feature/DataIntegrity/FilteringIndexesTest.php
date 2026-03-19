<?php

namespace Tests\Feature\DataIntegrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FilteringIndexesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_index_on_sermons_needs_preacher_review(): void
    {
        $this->assertTrue(
            Schema::hasIndex('sermons', 'sermons_needs_preacher_review_index'),
            'Index "sermons_needs_preacher_review_index" is missing on "sermons" table.'
        );
    }

    #[Test]
    public function it_has_index_on_pages_navigation(): void
    {
        $this->assertTrue(
            Schema::hasIndex('pages', 'pages_navigation_index'),
            'Index "pages_navigation_index" is missing on "pages" table.'
        );
    }
}
