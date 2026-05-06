<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Repositories\SermonRepository;
use App\Support\BibleCanon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SermonRepository::class);
        Cache::flush();
    }

    // ── Archive Filter Normalization ─────────────────────────────────────────

    #[Test]
    public function it_normalizes_archive_filters_with_valid_data(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        $bibleCanon->shouldReceive('hasBook')->with('John')->andReturn(true);
        $bibleCanon->shouldReceive('chaptersInBook')->with('John')->andReturn(21);

        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            '  John  ',
            3,
            123,
            '  Series Name  '
        );

        $this->assertEquals([
            'book' => 'John',
            'chapter' => 3,
            'preacherId' => 123,
            'series' => 'Series Name',
        ], $result);
    }

    #[Test]
    public function it_nullifies_invalid_book_and_corresponding_chapter(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        $bibleCanon->shouldReceive('hasBook')->with('InvalidBook')->andReturn(false);

        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            'InvalidBook',
            1,
            null,
            null
        );

        $this->assertNull($result['book']);
        $this->assertNull($result['chapter']);
    }

    #[Test]
    public function it_nullifies_out_of_range_chapter(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        $bibleCanon->shouldReceive('hasBook')->with('John')->andReturn(true);
        $bibleCanon->shouldReceive('chaptersInBook')->with('John')->andReturn(21);

        $result = $this->repository->normalizeArchiveFilters($bibleCanon, 'John', 22, null, null);
        $this->assertSame('John', $result['book']);
        $this->assertNull($result['chapter']);

        $result = $this->repository->normalizeArchiveFilters($bibleCanon, 'John', 0, null, null);
        $this->assertNull($result['chapter']);
    }

    // ── Series Retrieval ─────────────────────────────────────────────────────

    #[Test]
    public function it_returns_unique_series_names_sorted_alphabetically(): void
    {
        Sermon::factory()->create(['series' => 'Romans Study', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => 'John Study', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => 'John Study', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => 'Acts Study', 'content_type' => SermonContentType::Sermon, 'reference' => null]);

        $result = $this->repository->getExistingSeries();

        $this->assertCount(3, $result);
        $this->assertEquals(['Acts Study', 'John Study', 'Romans Study'], $result);
    }

    #[Test]
    public function it_filters_out_null_and_empty_series_names(): void
    {
        Sermon::factory()->create(['series' => 'Valid Series', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => null]);
        Sermon::factory()->create(['series' => '']);

        $result = $this->repository->getExistingSeries();

        $this->assertCount(1, $result);
        $this->assertEquals(['Valid Series'], $result);
    }

    #[Test]
    public function it_excludes_childrens_talks_from_series_retrieval(): void
    {
        Sermon::factory()->create(['series' => 'Sermon Series', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => 'Children Series', 'content_type' => SermonContentType::ChildrensTalk, 'reference' => null]);

        $result = $this->repository->getExistingSeries();

        $this->assertContains('Sermon Series', $result);
        $this->assertNotContains('Children Series', $result);
    }

    // ── Preacher & Service Retrieval ─────────────────────────────────────────

    #[Test]
    public function it_returns_sermons_for_a_specific_preacher(): void
    {
        $preacher = Preacher::factory()->create();
        $otherPreacher = Preacher::factory()->create();

        $preacherSermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::Sermon,
            'reference' => null,
        ]);
        Sermon::factory()->create([
            'preacher_id' => $otherPreacher->id,
            'content_type' => SermonContentType::Sermon,
            'reference' => null,
        ]);

        $result = $this->repository->getSermonsByPreacher($preacher);

        $this->assertCount(1, $result);
        $this->assertEquals($preacherSermon->id, $result->first()->id);
    }

    #[Test]
    public function it_returns_sermons_by_service(): void
    {
        Sermon::factory()->create(['service' => SermonService::Morning, 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['service' => SermonService::Evening, 'content_type' => SermonContentType::Sermon, 'reference' => null]);

        $result = $this->repository->getSermonsByService(SermonService::Morning);

        $this->assertCount(1, $result);
        $this->assertEquals(SermonService::Morning, $result->first()->service);
    }

    // ── Latest & Grouped Sermons ─────────────────────────────────────────────

    #[Test]
    public function it_returns_latest_sermons_grouped_by_date(): void
    {
        $today = Carbon::today();

        for ($i = 0; $i < 7; $i++) {
            Sermon::factory()->create([
                'date' => $today->copy()->subDays($i),
                'content_type' => SermonContentType::Sermon,
                'reference' => null,
            ]);
        }

        $result = $this->repository->getLatestSermons();

        $this->assertCount(6, $result);
        $this->assertEquals($today->format('Y-m-d'), $result->keys()->first());
    }

    #[Test]
    public function it_filters_out_childrens_talks_from_latest_sermons(): void
    {
        $today = Carbon::today();

        Sermon::factory()->create([
            'title' => 'Main Sermon',
            'content_type' => SermonContentType::Sermon,
            'date' => $today,
            'reference' => null,
        ]);
        Sermon::factory()->create([
            'title' => "Children's Talk",
            'content_type' => SermonContentType::ChildrensTalk,
            'date' => $today,
            'reference' => null,
        ]);

        $result = $this->repository->getLatestSermons();

        $this->assertCount(1, $result);
        $this->assertEquals('Main Sermon', $result->first()->first()->title);
    }

    // ── Slug Generation ──────────────────────────────────────────────────────

    #[Test]
    public function it_generates_unique_slugs(): void
    {
        Sermon::factory()->create(['slug' => 'test-sermon', 'reference' => null]);

        $slug = $this->repository->generateUniqueSlug('Test Sermon');
        $this->assertSame('test-sermon-1', $slug);

        Sermon::factory()->create(['slug' => 'test-sermon-1', 'reference' => null]);
        $slug = $this->repository->generateUniqueSlug('Test Sermon');
        $this->assertSame('test-sermon-2', $slug);
    }

    #[Test]
    public function it_excludes_current_sermon_from_slug_uniqueness(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'test-sermon', 'reference' => null]);

        $slug = $this->repository->generateUniqueSlug('Test Sermon', $sermon->id);

        $this->assertEquals('test-sermon', $slug);
    }

    // ── Scripture Metadata Retrieval ─────────────────────────────────────────

    #[Test]
    public function it_retrieves_scripture_books_without_filters(): void
    {
        SermonScriptureFilter::factory()->create(['bible_book' => 'Genesis']);
        SermonScriptureFilter::factory()->create(['bible_book' => 'Exodus']);
        SermonScriptureFilter::factory()->create(['bible_book' => 'Genesis']);

        $books = $this->repository->getScriptureBooks();

        $this->assertCount(2, $books);
        $this->assertContains('Genesis', $books);
        $this->assertContains('Exodus', $books);
    }

    #[Test]
    public function it_retrieves_scripture_books_with_preacher_filter(): void
    {
        $preacher = Preacher::factory()->create();
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id, 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'John']);

        $otherSermon = Sermon::factory()->create(['preacher_id' => null, 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $otherSermon->id, 'bible_book' => 'Mark']);

        $books = $this->repository->getScriptureBooks($preacher->id);

        $this->assertCount(1, $books);
        $this->assertContains('John', $books);
        $this->assertNotContains('Mark', $books);
    }

    #[Test]
    public function it_retrieves_scripture_books_with_series_filter(): void
    {
        $sermon = Sermon::factory()->create(['series' => 'Gospel', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'John']);

        $otherSermon = Sermon::factory()->create(['series' => 'Epistles', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $otherSermon->id, 'bible_book' => 'Romans']);

        $books = $this->repository->getScriptureBooks(null, 'Gospel');

        $this->assertCount(1, $books);
        $this->assertContains('John', $books);
        $this->assertNotContains('Romans', $books);
    }

    #[Test]
    public function it_retrieves_scripture_chapters_with_filters(): void
    {
        $sermon = Sermon::factory()->create(['series' => 'Gospel', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'John', 'bible_chapter' => 1]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'John', 'bible_chapter' => 3]);

        $otherSermon = Sermon::factory()->create(['series' => 'Epistles', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $otherSermon->id, 'bible_book' => 'John', 'bible_chapter' => 5]);

        $chapters = $this->repository->getScriptureChapters('John', null, 'Gospel');

        $this->assertCount(2, $chapters);
        $this->assertContains(1, $chapters);
        $this->assertContains(3, $chapters);
        $this->assertNotContains(5, $chapters);
    }

    // ── Caching & Invalidation ───────────────────────────────────────────────

    #[Test]
    public function it_caches_preacher_sermon_listing(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'caching-preacher']);
        Sermon::factory()->create(['preacher_id' => $preacher->id, 'reference' => null]);

        $this->repository->getSermonsByPreacher($preacher);
        $this->assertTrue(Cache::has('sermons_preacher_caching-preacher'));

        Sermon::query()->where('preacher_id', $preacher->id)->update(['title' => 'Updated Title']);

        $result = $this->repository->getSermonsByPreacher($preacher);
        $this->assertNotEquals('Updated Title', $result->first()->title);
    }

    #[Test]
    public function it_invalidates_caches_when_sermon_is_modified(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'invalidation-preacher']);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id, 'reference' => null]);

        $this->repository->getSermonsByPreacher($preacher);
        $this->assertTrue(Cache::has('sermons_preacher_invalidation-preacher'));

        $this->repository->clearListingCaches($sermon);

        $this->assertFalse(Cache::has('sermons_preacher_invalidation-preacher'));
    }

    #[Test]
    public function it_caches_json_ld_results(): void
    {
        $this->repository->getRecentSermonsForJsonLd();
        $this->assertTrue(Cache::has('sermons_jsonld_recent_100'));

        $this->repository->clearListingCaches();
        $this->assertFalse(Cache::has('sermons_jsonld_recent_100'));
    }
}
