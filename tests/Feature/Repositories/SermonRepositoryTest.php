<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Services\Public\SermonRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        SermonScriptureFilter::query()->delete();
        Sermon::query()->delete();
        Preacher::query()->delete();

        $this->repository = app(SermonRepository::class);
        Cache::flush();
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

    #[Test]
    public function it_resolves_series_name_from_slug(): void
    {
        Sermon::factory()->create(['series' => 'Study in Romans', 'content_type' => SermonContentType::Sermon, 'reference' => null]);
        Sermon::factory()->create(['series' => 'Another Series Name', 'content_type' => SermonContentType::Sermon, 'reference' => null]);

        $resolved = $this->repository->resolveSeriesNameFromSlug('study-in-romans');
        $this->assertEquals('Study in Romans', $resolved);

        $resolvedNull = $this->repository->resolveSeriesNameFromSlug('non-existent-series');
        $this->assertNull($resolvedNull);
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
