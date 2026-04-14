<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Services\SermonScriptureFilterIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonScriptureFilterIndexServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonScriptureFilterIndexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SermonScriptureFilterIndexService::class);
    }

    #[Test]
    public function single_chapter_references_index_one_chapter(): void
    {
        $this->assertSame([
            ['bible_book' => 'John', 'bible_chapter' => 3],
        ], $this->service->entriesForReference('John 3:16'));
    }

    #[Test]
    public function cross_chapter_references_index_each_covered_chapter(): void
    {
        $this->assertSame([
            ['bible_book' => 'John', 'bible_chapter' => 15],
            ['bible_book' => 'John', 'bible_chapter' => 16],
        ], $this->service->entriesForReference('John 15:9-16:3'));
    }

    #[Test]
    public function multi_segment_references_index_each_segment(): void
    {
        $this->assertSame([
            ['bible_book' => 'Mark', 'bible_chapter' => 8],
            ['bible_book' => 'Mark', 'bible_chapter' => 9],
            ['bible_book' => 'Mark', 'bible_chapter' => 10],
        ], $this->service->entriesForReference('Mark 8:31-33, 9:30-37, 10:32-34'));
    }

    #[Test]
    public function cross_book_references_index_intervening_books_and_chapters(): void
    {
        $this->assertSame([
            ['bible_book' => '2 John', 'bible_chapter' => 1],
            ['bible_book' => '3 John', 'bible_chapter' => 1],
            ['bible_book' => 'Jude', 'bible_chapter' => 1],
        ], $this->service->entriesForReference('2 John 1:1 - Jude 1:4'));
    }

    #[Test]
    public function overlapping_segments_are_deduplicated(): void
    {
        $this->assertSame([
            ['bible_book' => 'John', 'bible_chapter' => 3],
            ['bible_book' => 'John', 'bible_chapter' => 4],
        ], $this->service->entriesForReference('John 3:1-4:3, John 3:5-18'));
    }

    #[Test]
    public function empty_and_unparseable_references_clear_rows(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);
        SermonScriptureFilter::factory()->for($sermon)->forPassage('Genesis', 1)->create();

        $sermon->reference = '';
        $this->service->syncForSermon($sermon);
        $this->assertDatabaseCount('sermon_scripture_filters', 0);

        SermonScriptureFilter::factory()->for($sermon)->forPassage('Exodus', 2)->create();

        $sermon->reference = 'not a real reference';
        $this->service->syncForSermon($sermon);
        $this->assertDatabaseCount('sermon_scripture_filters', 0);
    }

    #[Test]
    public function childrens_talks_do_not_keep_browse_rows(): void
    {
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'reference' => 'John 3:16',
        ]);

        SermonScriptureFilter::factory()->for($sermon)->forPassage('John', 3)->create();

        $this->service->syncForSermon($sermon);

        $this->assertDatabaseCount('sermon_scripture_filters', 0);
    }

    #[Test]
    public function precomputed_entries_do_not_bypass_the_content_type_guard(): void
    {
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'reference' => 'John 3:16',
        ]);

        $this->service->syncForSermon($sermon, [
            ['bible_book' => 'John', 'bible_chapter' => 3],
        ]);

        $this->assertDatabaseCount('sermon_scripture_filters', 0);
    }
}
