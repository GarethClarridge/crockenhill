<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonScriptureFilterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function factory_creates_valid_records(): void
    {
        $filter = SermonScriptureFilter::factory()->create();

        $this->assertInstanceOf(Sermon::class, $filter->sermon);
        $this->assertSame('John', $filter->bible_book);
        $this->assertSame(3, $filter->bible_chapter);
    }

    #[Test]
    public function it_belongs_to_a_sermon(): void
    {
        $sermon = Sermon::factory()->create(['reference' => null]);
        $filter = SermonScriptureFilter::factory()->for($sermon)->forPassage('Romans', 8)->create();

        $this->assertTrue($filter->sermon->is($sermon));
        $this->assertCount(1, $sermon->fresh()->scriptureFilters);
    }
}
