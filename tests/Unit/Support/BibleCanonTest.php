<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BibleCanon;
use PHPUnit\Framework\Attributes\Test;
use TechWilk\BibleVerseParser\BiblePassageParser;
use Tests\TestCase;

class BibleCanonTest extends TestCase
{
    private BibleCanon $bibleCanon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bibleCanon = app(BibleCanon::class);
    }

    #[Test]
    public function it_is_registered_as_a_singleton(): void
    {
        $this->assertSame($this->bibleCanon, app(BibleCanon::class));
    }

    #[Test]
    public function it_returns_all_66_books_in_canonical_order(): void
    {
        $books = $this->bibleCanon->allBooks();

        $this->assertCount(66, $books);
        $this->assertSame('Genesis', $books[0]['name']);
        $this->assertSame('Revelation', $books[65]['name']);
    }

    #[Test]
    public function it_returns_expected_chapter_counts(): void
    {
        $this->assertSame(50, $this->bibleCanon->chaptersInBook('Genesis'));
        $this->assertSame(1, $this->bibleCanon->chaptersInBook('Jude'));
        $this->assertSame(22, $this->bibleCanon->chaptersInBook('Revelation'));
    }

    #[Test]
    public function it_stays_aligned_with_the_parser_book_names(): void
    {
        $parser = new BiblePassageParser;

        foreach ($this->bibleCanon->allBooks() as $book) {
            $passages = $parser->parse($book['name'].' 1:1');

            $this->assertCount(1, $passages);
            $this->assertSame($book['name'], $passages[0]->from()->book()->name());
        }
    }

    #[Test]
    public function it_marks_enabled_and_disabled_options(): void
    {
        $bookOptions = $this->bibleCanon->bookOptions(collect(['Exodus', 'Psalms']));
        $chapterOptions = $this->bibleCanon->chapterOptions('John', collect([3, 5]));

        $this->assertTrue($bookOptions[0]['disabled']);
        $this->assertSame('Exodus', $bookOptions[1]['name']);
        $this->assertFalse($bookOptions[1]['disabled']);

        $this->assertSame(1, $chapterOptions[0]['id']);
        $this->assertTrue($chapterOptions[0]['disabled']);
        $this->assertFalse($chapterOptions[2]['disabled']);
        $this->assertFalse($chapterOptions[4]['disabled']);
    }

    #[Test]
    public function it_normalizes_valid_archive_filters(): void
    {
        $this->assertSame([
            'book' => 'John',
            'chapter' => 3,
            'preacherId' => 123,
            'series' => 'Series Name',
        ], $this->bibleCanon->normalizeArchiveFilters('  John  ', 3, 123, '  Series Name  '));
    }

    #[Test]
    public function it_rejects_invalid_archive_books_and_chapters(): void
    {
        $invalidBook = $this->bibleCanon->normalizeArchiveFilters('InvalidBook', 1, null, null);
        $invalidChapter = $this->bibleCanon->normalizeArchiveFilters('John', 22, null, null);
        $zeroChapter = $this->bibleCanon->normalizeArchiveFilters('John', 0, null, null);

        $this->assertNull($invalidBook['book']);
        $this->assertNull($invalidBook['chapter']);
        $this->assertSame('John', $invalidChapter['book']);
        $this->assertNull($invalidChapter['chapter']);
        $this->assertNull($zeroChapter['chapter']);
    }

    #[Test]
    public function it_normalizes_whitespace_only_archive_filters_to_null(): void
    {
        $filters = $this->bibleCanon->normalizeArchiveFilters('   ', null, null, '   ');

        $this->assertNull($filters['book']);
        $this->assertNull($filters['series']);
    }

    #[Test]
    public function it_expands_single_chapter_passages(): void
    {
        $passage = (new BiblePassageParser)->parse('John 3:16-21')[0];

        $this->assertSame([
            ['book' => 'John', 'chapter' => 3],
        ], $this->bibleCanon->expandPassageToChapters($passage));
    }

    #[Test]
    public function it_expands_cross_chapter_passages(): void
    {
        $passage = (new BiblePassageParser)->parse('John 15:9-16:3')[0];

        $this->assertSame([
            ['book' => 'John', 'chapter' => 15],
            ['book' => 'John', 'chapter' => 16],
        ], $this->bibleCanon->expandPassageToChapters($passage));
    }

    #[Test]
    public function it_expands_cross_book_passages_including_intervening_books(): void
    {
        $passage = (new BiblePassageParser)->parse('2 John 1:1 - Jude 1:4')[0];

        $this->assertSame([
            ['book' => '2 John', 'chapter' => 1],
            ['book' => '3 John', 'chapter' => 1],
            ['book' => 'Jude', 'chapter' => 1],
        ], $this->bibleCanon->expandPassageToChapters($passage));
    }
}
