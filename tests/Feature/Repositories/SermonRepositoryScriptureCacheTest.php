<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryScriptureCacheTest extends TestCase
{
    use DatabaseTransactions;

    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(SermonRepository::class);
        Cache::flush();
        $this->repository->clearInternalCaches();
    }

    #[Test]
    public function it_invalidates_global_scripture_books_cache(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => SermonContentType::Sermon]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'Genesis']);

        // First call populates cache
        $books = $this->repository->getScriptureBooks();
        $this->assertContains('Genesis', $books);

        // Manually delete filter from DB to verify cache is used
        SermonScriptureFilter::query()->delete();
        $this->repository->clearInternalCaches();

        $cachedBooks = $this->repository->getScriptureBooks();
        $this->assertContains('Genesis', $cachedBooks);

        // Clear cache and verify fresh data (empty list)
        $this->repository->clearScriptureChapterCaches($sermon);
        $this->repository->clearInternalCaches();

        $freshBooks = $this->repository->getScriptureBooks();
        $this->assertNotContains('Genesis', $freshBooks);
        $this->assertEmpty($freshBooks);
    }

    #[Test]
    public function it_invalidates_preacher_and_series_filtered_books_cache(): void
    {
        $preacher = Preacher::factory()->create();
        $series = 'Romans Series';
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'preacher_id' => $preacher->id,
            'series' => $series,
        ]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'Romans']);

        // Populate cache for specific filter
        $this->repository->getScriptureBooks($preacher->id, $series);

        // Modify DB directly
        SermonScriptureFilter::query()->where('sermon_id', $sermon->id)->delete();
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'Exodus']);
        $this->repository->clearInternalCaches();

        // Should still be 'Romans' from cache
        $this->assertContains('Romans', $this->repository->getScriptureBooks($preacher->id, $series));

        // Clear cache
        $this->repository->clearScriptureChapterCaches($sermon);
        $this->repository->clearInternalCaches();

        // Should now be 'Exodus'
        $fresh = $this->repository->getScriptureBooks($preacher->id, $series);
        $this->assertContains('Exodus', $fresh);
        $this->assertNotContains('Romans', $fresh);
    }

    #[Test]
    public function it_invalidates_chapter_list_cache(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => SermonContentType::Sermon]);
        SermonScriptureFilter::factory()->create([
            'sermon_id' => $sermon->id,
            'bible_book' => 'John',
            'bible_chapter' => 3,
        ]);

        $this->repository->getScriptureChapters('John');

        // Modify DB
        SermonScriptureFilter::query()->where('sermon_id', $sermon->id)->delete();
        SermonScriptureFilter::factory()->create([
            'sermon_id' => $sermon->id,
            'bible_book' => 'John',
            'bible_chapter' => 4,
        ]);
        $this->repository->clearInternalCaches();

        // Stale
        $this->assertContains(3, $this->repository->getScriptureChapters('John'));

        // Clear
        $this->repository->clearScriptureChapterCaches($sermon);
        $this->repository->clearInternalCaches();

        // Fresh
        $this->assertContains(4, $this->repository->getScriptureChapters('John'));
    }

    #[Test]
    public function it_invalidates_old_and_new_identities_when_preacher_or_series_changes(): void
    {
        $preacherOld = Preacher::factory()->create();
        $preacherNew = Preacher::factory()->create();
        $seriesOld = 'Old Series';
        $seriesNew = 'New Series';

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'preacher_id' => $preacherOld->id,
            'series' => $seriesOld,
        ]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'Acts']);

        // Seed the new-identity with data so its cache holds a non-empty collection.
        $sermonNew = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'preacher_id' => $preacherNew->id,
            'series' => $seriesNew,
        ]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermonNew->id, 'bible_book' => 'Acts']);

        // Warm both caches with real data
        $this->repository->getScriptureBooks($preacherOld->id, $seriesOld);
        $this->repository->getScriptureBooks($preacherNew->id, $seriesNew);

        // Change the sermon identity
        $sermon->preacher_id = $preacherNew->id;
        $sermon->series = $seriesNew;

        // Clear caches using the model with dirty state (SermonRepository uses getOriginal() to find old keys)
        $this->repository->clearScriptureChapterCaches($sermon);
        $this->repository->clearInternalCaches();

        // Verify both are cleared by checking if they hit the DB (which we'll simulate by making them empty)
        SermonScriptureFilter::query()->delete();

        $this->assertEmpty($this->repository->getScriptureBooks($preacherOld->id, $seriesOld));
        $this->assertEmpty($this->repository->getScriptureBooks($preacherNew->id, $seriesNew));
    }

    #[Test]
    public function it_invalidates_old_and_new_books_when_reference_changes(): void
    {
        // Reference changed from John to Mark
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'reference' => 'John 3',
        ]);

        // Clean up any auto-synced filters from observer
        SermonScriptureFilter::query()->where('sermon_id', $sermon->id)->delete();
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'John', 'bible_chapter' => 3]);
        // Seed a real Mark entry so the Mark cache holds a non-empty collection.
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'Mark', 'bible_chapter' => 1]);

        // Populate caches
        $this->repository->getScriptureChapters('John');
        $this->repository->getScriptureChapters('Mark');

        // Change reference
        $sermon->reference = 'Mark 1';

        $this->repository->clearScriptureChapterCaches($sermon);
        $this->repository->clearInternalCaches();

        // Verify both are cleared
        SermonScriptureFilter::query()->delete();

        $this->assertEmpty($this->repository->getScriptureChapters('John'));
        $this->assertEmpty($this->repository->getScriptureChapters('Mark'));
    }
}
