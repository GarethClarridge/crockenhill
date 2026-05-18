<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryCacheInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SermonRepository::class);
    }

    #[Test]
    public function it_clears_global_listing_caches(): void
    {
        Cache::spy();

        $this->repository->clearListingCaches();

        $keys = [
            'latest_sermons',
            'all_sermons',
            'sermon_series',
            'sermon_scripture_books_all_all',
            'sermons_jsonld_recent_100',
        ];

        foreach ($keys as $key) {
            Cache::shouldHaveReceived('forget')->with($key)->atLeast()->once();
            Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:{$key}")->atLeast()->once();
        }
    }

    #[Test]
    public function it_clears_model_specific_caches_for_a_sermon(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'john-doe']);
        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'series' => 'My Great Series',
            'service' => SermonService::Morning,
        ]);

        Cache::spy();

        $this->repository->clearListingCaches($sermon);

        $modelKeys = [
            'sermons_series_my-great-series',
            'sermons_service_morning',
            'sermons_preacher_john-doe',
        ];

        foreach ($modelKeys as $key) {
            Cache::shouldHaveReceived('forget')->with($key)->once();
            Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:{$key}")->once();
        }
    }

    #[Test]
    public function it_clears_preacher_specific_cache(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'jane-smith']);

        Cache::spy();

        $this->repository->clearListingCaches($preacher);

        Cache::shouldHaveReceived('forget')->with('sermons_preacher_jane-smith')->once();
        Cache::shouldHaveReceived('forget')->with('illuminate:cache:flexible:created:sermons_preacher_jane-smith')->once();
    }

    #[Test]
    public function it_invalidates_caches_for_both_original_and_new_preacher_and_series(): void
    {
        $oldPreacher = Preacher::factory()->create(['slug' => 'old-preacher']);
        $newPreacher = Preacher::factory()->create(['slug' => 'new-preacher']);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $oldPreacher->id,
            'series' => 'Old Series',
            'service' => SermonService::Morning,
        ]);

        // Manually set new values without saving yet, so we have "original" vs "current"
        $sermon->preacher_id = $newPreacher->id;
        $sermon->series = 'New Series';
        $sermon->service = SermonService::Evening;

        Cache::spy();

        $this->repository->clearListingCaches($sermon);

        $expectedKeys = [
            'sermons_preacher_old-preacher',
            'sermons_preacher_new-preacher',
            'sermons_series_old-series',
            'sermons_series_new-series',
            'sermons_service_morning',
            'sermons_service_evening',
        ];

        foreach ($expectedKeys as $key) {
            Cache::shouldHaveReceived('forget')->with($key)->once();
            Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:{$key}")->once();
        }
    }

    #[Test]
    public function it_invalidates_scripture_chapter_caches_for_all_related_bible_books(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16, Romans 8:28',
            'preacher_id' => null,
            'series' => null,
        ]);

        Cache::spy();

        $this->repository->clearListingCaches($sermon);

        // Global books list
        Cache::shouldHaveReceived('forget')->with('sermon_scripture_books_all_all')->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with('illuminate:cache:flexible:created:sermon_scripture_books_all_all')->atLeast()->once();

        // John chapters
        Cache::shouldHaveReceived('forget')->with('sermon_scripture_chapters_john_all_all')->once();
        Cache::shouldHaveReceived('forget')->with('illuminate:cache:flexible:created:sermon_scripture_chapters_john_all_all')->once();
        // Romans chapters
        Cache::shouldHaveReceived('forget')->with('sermon_scripture_chapters_romans_all_all')->once();
        Cache::shouldHaveReceived('forget')->with('illuminate:cache:flexible:created:sermon_scripture_chapters_romans_all_all')->once();
    }

    #[Test]
    public function it_invalidates_scripture_chapter_caches_across_preacher_and_series_combinations(): void
    {
        $preacher = Preacher::factory()->create(['id' => 123]);
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3',
            'preacher_id' => $preacher->id,
            'series' => 'The Gospel',
        ]);

        $pId = 123;
        $sSlug = 'the-gospel';
        $bSlug = 'john';

        Cache::spy();

        $this->repository->clearListingCaches($sermon);

        // Book list combinations
        Cache::shouldHaveReceived('forget')->with("sermon_scripture_books_{$pId}_all")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:sermon_scripture_books_{$pId}_all")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("sermon_scripture_books_all_{$sSlug}")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:sermon_scripture_books_all_{$sSlug}")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("sermon_scripture_books_{$pId}_{$sSlug}")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:sermon_scripture_books_{$pId}_{$sSlug}")->atLeast()->once();

        // Chapter list combinations
        Cache::shouldHaveReceived('forget')->with("sermon_scripture_chapters_{$bSlug}_all_all")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:sermon_scripture_chapters_{$bSlug}_all_all")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("sermon_scripture_chapters_{$bSlug}_{$pId}_all")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:sermon_scripture_chapters_{$bSlug}_{$pId}_all")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("sermon_scripture_chapters_{$bSlug}_all_{$sSlug}")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:sermon_scripture_chapters_{$bSlug}_all_{$sSlug}")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("sermon_scripture_chapters_{$bSlug}_{$pId}_{$sSlug}")->atLeast()->once();
        Cache::shouldHaveReceived('forget')->with("illuminate:cache:flexible:created:sermon_scripture_chapters_{$bSlug}_{$pId}_{$sSlug}")->atLeast()->once();
    }
}
