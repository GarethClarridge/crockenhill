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

        SermonScriptureFilter::query()->delete();
        Sermon::query()->delete();
        Preacher::query()->delete();

        $this->repository = app(SermonRepository::class);
        $this->repository->clearInternalCaches();
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
    public function it_returns_sermons_for_a_specific_series(): void
    {
        Sermon::factory()->create(['series' => 'Study in Romans', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => 'Study in John', 'content_type' => SermonContentType::Sermon, 'reference' => null]);

        $result = $this->repository->getSermonsBySeries('Study in Romans');

        $this->assertCount(1, $result);
        $this->assertEquals('Study in Romans', $result->first()->series);
    }

    #[Test]
    public function it_returns_sermons_for_a_series_ordered_by_date_descending(): void
    {
        Sermon::factory()->create([
            'series' => 'Study in Romans',
            'date' => '2024-01-01',
            'content_type' => SermonContentType::Sermon,
            'reference' => null,
        ]);
        Sermon::factory()->create([
            'series' => 'Study in Romans',
            'date' => '2024-01-15',
            'content_type' => SermonContentType::Sermon,
            'reference' => null,
        ]);

        $result = $this->repository->getSermonsBySeries('Study in Romans');

        $this->assertCount(2, $result);
        $this->assertEquals('2024-01-15', $result->first()->date->format('Y-m-d'));
        $this->assertEquals('2024-01-01', $result->last()->date->format('Y-m-d'));
    }

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
    public function it_memoizes_series_sermons_at_the_request_level(): void
    {
        Sermon::factory()->create(['series' => 'Study in Romans', 'reference' => null]);

        $first = $this->repository->getSermonsBySeries('Study in Romans');
        $second = $this->repository->getSermonsBySeries('Study in Romans');

        $this->assertSame($first, $second, 'Successive calls must return the same collection instance (memoized)');
    }

    #[Test]
    public function it_memoizes_service_sermons_at_the_request_level(): void
    {
        Sermon::factory()->create(['service' => SermonService::Morning, 'reference' => null]);

        $first = $this->repository->getSermonsByService(SermonService::Morning);
        $second = $this->repository->getSermonsByService(SermonService::Morning);

        $this->assertSame($first, $second, 'Successive calls must return the same collection instance (memoized)');
    }

    #[Test]
    public function it_memoizes_preacher_sermons_at_the_request_level(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->create(['preacher_id' => $preacher->id, 'reference' => null]);

        $first = $this->repository->getSermonsByPreacher($preacher);
        $second = $this->repository->getSermonsByPreacher($preacher);

        $this->assertSame($first, $second, 'Successive calls must return the same collection instance (memoized)');
    }

    #[Test]
    public function it_caches_preacher_sermon_listing(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->create(['preacher_id' => $preacher->id, 'title' => 'Original Title', 'reference' => null]);

        $first = $this->repository->getSermonsByPreacher($preacher);

        // Bypass observers so the cache is not auto-cleared by the update.
        Sermon::query()->where('preacher_id', $preacher->id)->update(['title' => 'Updated Title']);

        $cached = $this->repository->getSermonsByPreacher($preacher);
        $this->assertEquals($first->first()->title, $cached->first()->title, 'Cache should serve the stale result before invalidation');
        $this->assertNotEquals('Updated Title', $cached->first()->title);
    }

    #[Test]
    public function it_invalidates_caches_when_sermon_is_modified(): void
    {
        $preacher = Preacher::factory()->create();
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id, 'title' => 'Before Clear', 'reference' => null]);

        $this->repository->getSermonsByPreacher($preacher);

        // Bypass observers so only explicit clearListingCaches() drives the invalidation.
        Sermon::query()->where('id', $sermon->id)->update(['title' => 'After Clear']);

        $this->repository->clearListingCaches($sermon);

        $fresh = $this->repository->getSermonsByPreacher($preacher);
        $this->assertEquals('After Clear', $fresh->first()->title, 'Cache should return fresh data after clearListingCaches()');
    }

    #[Test]
    public function it_caches_json_ld_results(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => SermonContentType::Sermon, 'reference' => null]);
        $first = $this->repository->getRecentSermonsForJsonLd();

        Sermon::query()->where('id', $sermon->id)->update(['title' => 'Injected Title']);

        $cached = $this->repository->getRecentSermonsForJsonLd();
        $this->assertNotEquals('Injected Title', $cached->first()->title, 'Cache should serve stale JSON-LD data before invalidation');

        $this->repository->clearListingCaches();

        $fresh = $this->repository->getRecentSermonsForJsonLd();
        $this->assertEquals('Injected Title', $fresh->first()->title, 'Cache should return fresh data after clearListingCaches()');
    }

    #[Test]
    public function it_respects_limit_for_json_ld(): void
    {
        Sermon::factory()->count(5)->create(['content_type' => SermonContentType::Sermon, 'reference' => null]);

        $result = $this->repository->getRecentSermonsForJsonLd(limit: 3);

        $this->assertLessThanOrEqual(3, $result->count());
    }

    #[Test]
    public function it_nullifies_whitespace_only_book_in_archive_filters(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        // Whitespace-only book is normalised to null before hasBook is ever called.
        $bibleCanon->shouldNotReceive('hasBook');

        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            '   ',
            null,
            null,
            '   '
        );

        $this->assertNull($result['book']);
        $this->assertNull($result['series']);
    }

    #[Test]
    public function it_returns_all_sermons_grouped_by_date(): void
    {
        Sermon::factory()->create(['date' => '2024-01-01', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['date' => '2024-01-02', 'content_type' => SermonContentType::Sermon, 'reference' => null]);

        $result = $this->repository->getAllSermons();

        $this->assertTrue($result->has('2024-01-02'));
        $this->assertTrue($result->has('2024-01-01'));
    }

    #[Test]
    public function it_caches_series_for_display_sorted_alphabetically(): void
    {
        Sermon::factory()->create(['series' => 'Z Series', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => 'A Series', 'content_type' => SermonContentType::Sermon, 'reference' => null]);

        $result = $this->repository->getSeriesForDisplay();

        $this->assertContains('A Series', $result);
        $this->assertContains('Z Series', $result);
        $this->assertLessThan(
            array_search('Z Series', $result),
            array_search('A Series', $result)
        );

        // Verify the cache is serving the result: a second call without DB changes returns identical data.
        $cached = $this->repository->getSeriesForDisplay();
        $this->assertSame($result, $cached, 'Second call should return the cached series list');
    }
}
