<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Services\Public\SermonRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SermonRepository::class);
        $this->repository->clearInternalCaches();
    }

    #[Test]
    public function it_clears_model_specific_caches_for_a_sermon(): void
    {
        Sermon::query()->delete();
        $preacher = Preacher::factory()->create(['slug' => 'john-doe']);
        $sermon = Sermon::factory()->create([
            'title' => 'Original Title',
            'preacher_id' => $preacher->id,
            'series' => 'My Great Series',
            'service' => SermonService::Morning,
        ]);

        // Warm specific caches
        $this->repository->getSermonsBySeries('My Great Series');
        $this->repository->getSermonsByService(SermonService::Morning);
        $this->repository->getSermonsByPreacher($preacher);

        // Update DB directly
        Sermon::query()->update(['title' => 'Updated Title']);
        $this->repository->clearInternalCaches();

        // Verify stale
        $this->assertEquals('Original Title', $this->repository->getSermonsBySeries('My Great Series')->first()->title);
        $this->assertEquals('Original Title', $this->repository->getSermonsByService(SermonService::Morning)->first()->title);
        $this->assertEquals('Original Title', $this->repository->getSermonsByPreacher($preacher)->first()->title);

        $this->repository->clearListingCaches($sermon);
        $this->repository->clearInternalCaches();

        // Verify fresh
        $this->assertEquals('Updated Title', $this->repository->getSermonsBySeries('My Great Series')->first()->title);
        $this->assertEquals('Updated Title', $this->repository->getSermonsByService(SermonService::Morning)->first()->title);
        $this->assertEquals('Updated Title', $this->repository->getSermonsByPreacher($preacher)->first()->title);
    }

    #[Test]
    public function it_clears_preacher_specific_cache(): void
    {
        Sermon::query()->delete();
        $preacher = Preacher::factory()->create(['slug' => 'jane-smith']);
        Sermon::factory()->create([
            'title' => 'Original Title',
            'preacher_id' => $preacher->id,
        ]);

        // Warm cache
        $this->repository->getSermonsByPreacher($preacher);

        // Update DB directly
        Sermon::query()->update(['title' => 'Updated Title']);
        $this->repository->clearInternalCaches();

        // Verify stale
        $this->assertEquals('Original Title', $this->repository->getSermonsByPreacher($preacher)->first()->title);

        $this->repository->clearListingCaches($preacher);
        $this->repository->clearInternalCaches();

        // Verify fresh
        $this->assertEquals('Updated Title', $this->repository->getSermonsByPreacher($preacher)->first()->title);
    }

    #[Test]
    public function it_invalidates_caches_for_both_original_and_new_preacher_and_series(): void
    {
        Sermon::query()->delete();
        $oldPreacher = Preacher::factory()->create(['slug' => 'old-preacher']);
        $newPreacher = Preacher::factory()->create(['slug' => 'new-preacher']);

        $sermonToUpdate = Sermon::factory()->create([
            'title' => 'Target Sermon',
            'preacher_id' => $oldPreacher->id,
            'series' => 'Old Series',
            'service' => SermonService::Morning,
        ]);

        // Create another sermon to ensure "New Series" cache is not empty
        Sermon::factory()->create([
            'title' => 'Other Sermon',
            'preacher_id' => $newPreacher->id,
            'series' => 'New Series',
            'service' => SermonService::Evening,
        ]);

        // Warm caches for both identities
        $this->repository->getSermonsByPreacher($oldPreacher);
        $this->repository->getSermonsByPreacher($newPreacher);
        $this->repository->getSermonsBySeries('Old Series');
        $this->repository->getSermonsBySeries('New Series');
        $this->repository->getSermonsByService(SermonService::Morning);
        $this->repository->getSermonsByService(SermonService::Evening);

        // Prepare a "dirty" model that reflects a change from Old to New
        $sermon = $sermonToUpdate->fresh();
        $sermon->preacher_id = $newPreacher->id;
        $sermon->series = 'New Series';
        $sermon->service = SermonService::Evening;

        // Update DB directly to bypass observers, setting everything to 'Cleared'
        Sermon::query()->where('id', $sermonToUpdate->id)->update([
            'title' => 'Cleared Target',
            'preacher_id' => $newPreacher->id,
            'series' => 'New Series',
            'service' => SermonService::Evening,
        ]);
        Sermon::query()->where('id', '!=', $sermonToUpdate->id)->update(['title' => 'Cleared Other']);
        $this->repository->clearInternalCaches();

        // Verify all are still stale
        $this->assertEquals('Target Sermon', $this->repository->getSermonsByPreacher($oldPreacher)->first()->title);
        $this->assertCount(1, $this->repository->getSermonsByPreacher($newPreacher));
        $this->assertEquals('Target Sermon', $this->repository->getSermonsBySeries('Old Series')->first()->title);
        $this->assertEquals('Other Sermon', $this->repository->getSermonsBySeries('New Series')->where('id', '!=', $sermonToUpdate->id)->first()->title);
        $this->assertEquals('Target Sermon', $this->repository->getSermonsByService(SermonService::Morning)->first()->title);
        $this->assertEquals('Other Sermon', $this->repository->getSermonsByService(SermonService::Evening)->first()->title);

        $this->repository->clearListingCaches($sermon);
        $this->repository->clearInternalCaches();

        // Verify both old and new keys are cleared
        $this->assertEmpty($this->repository->getSermonsByPreacher($oldPreacher));
        $this->assertEquals('Cleared Target', $this->repository->getSermonsByPreacher($newPreacher)->where('id', $sermonToUpdate->id)->first()->title);
        $this->assertEmpty($this->repository->getSermonsBySeries('Old Series'));
        $this->assertEquals('Cleared Target', $this->repository->getSermonsBySeries('New Series')->where('id', $sermonToUpdate->id)->first()->title);
        $this->assertEmpty($this->repository->getSermonsByService(SermonService::Morning));
        $this->assertEquals('Cleared Target', $this->repository->getSermonsByService(SermonService::Evening)->where('id', $sermonToUpdate->id)->first()->title);
    }

    #[Test]
    public function it_invalidates_scripture_chapter_caches_for_all_related_bible_books(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16, Romans 8:28',
            'preacher_id' => null,
            'series' => null,
        ]);

        // Clean up filters if any were created by observers, and create manually
        SermonScriptureFilter::query()->delete();
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'John', 'bible_chapter' => 3]);
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'Romans', 'bible_chapter' => 8]);

        // Warm caches
        $this->repository->getScriptureBooks();
        $this->repository->getScriptureChapters('John');
        $this->repository->getScriptureChapters('Romans');

        // Update DB directly
        SermonScriptureFilter::query()->delete();
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'Genesis', 'bible_chapter' => 1]);
        $this->repository->clearInternalCaches();

        // Verify stale
        $this->assertContains('John', $this->repository->getScriptureBooks());
        $this->assertContains(3, $this->repository->getScriptureChapters('John'));
        $this->assertContains(8, $this->repository->getScriptureChapters('Romans'));

        $this->repository->clearListingCaches($sermon);
        $this->repository->clearInternalCaches();

        // Verify fresh
        $books = $this->repository->getScriptureBooks();
        $this->assertContains('Genesis', $books);
        $this->assertNotContains('John', $books);
        $this->assertEmpty($this->repository->getScriptureChapters('John'));
        $this->assertEmpty($this->repository->getScriptureChapters('Romans'));
    }

    #[Test]
    public function it_invalidates_scripture_chapter_caches_across_preacher_and_series_combinations(): void
    {
        $preacher = Preacher::factory()->create();
        $series = 'The Gospel';
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3',
            'preacher_id' => $preacher->id,
            'series' => $series,
        ]);

        SermonScriptureFilter::query()->delete();
        SermonScriptureFilter::factory()->create(['sermon_id' => $sermon->id, 'bible_book' => 'John', 'bible_chapter' => 3]);

        // Warm various combinations
        $this->repository->getScriptureBooks($preacher->id, null);
        $this->repository->getScriptureBooks(null, $series);
        $this->repository->getScriptureBooks($preacher->id, $series);
        $this->repository->getScriptureChapters('John', $preacher->id, null);
        $this->repository->getScriptureChapters('John', null, $series);
        $this->repository->getScriptureChapters('John', $preacher->id, $series);

        // Update DB directly
        SermonScriptureFilter::query()->delete();
        $this->repository->clearInternalCaches();

        // Verify stale
        $this->assertContains('John', $this->repository->getScriptureBooks($preacher->id, null));
        $this->assertContains('John', $this->repository->getScriptureBooks(null, $series));
        $this->assertContains('John', $this->repository->getScriptureBooks($preacher->id, $series));
        $this->assertContains(3, $this->repository->getScriptureChapters('John', $preacher->id, null));
        $this->assertContains(3, $this->repository->getScriptureChapters('John', null, $series));
        $this->assertContains(3, $this->repository->getScriptureChapters('John', $preacher->id, $series));

        $this->repository->clearListingCaches($sermon);
        $this->repository->clearInternalCaches();

        // Verify fresh
        $this->assertEmpty($this->repository->getScriptureBooks($preacher->id, null));
        $this->assertEmpty($this->repository->getScriptureBooks(null, $series));
        $this->assertEmpty($this->repository->getScriptureBooks($preacher->id, $series));
        $this->assertEmpty($this->repository->getScriptureChapters('John', $preacher->id, null));
        $this->assertEmpty($this->repository->getScriptureChapters('John', null, $series));
        $this->assertEmpty($this->repository->getScriptureChapters('John', $preacher->id, $series));
    }
}
