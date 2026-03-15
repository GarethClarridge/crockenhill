<?php

namespace Tests\Feature\Repositories;

use App\Models\Preacher;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new SermonRepository;
    }

    #[Test]
    public function it_returns_empty_array_when_no_series_exist(): void
    {
        Sermon::query()->delete();

        $result = $this->repository->getExistingSeries();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function it_returns_unique_series_names_sorted_alphabetically(): void
    {
        Sermon::query()->delete();

        Sermon::factory()->create(['series' => 'Romans Study']);
        Sermon::factory()->create(['series' => 'John Study']);
        Sermon::factory()->create(['series' => 'John Study']); // Duplicate
        Sermon::factory()->create(['series' => 'Acts Study']);

        $result = $this->repository->getExistingSeries();

        $this->assertCount(3, $result);
        $this->assertEquals(['Acts Study', 'John Study', 'Romans Study'], $result);
    }

    #[Test]
    public function it_filters_out_null_and_empty_series_names(): void
    {
        Sermon::query()->delete();

        Sermon::factory()->create(['series' => 'Valid Series']);
        Sermon::factory()->create(['series' => null]);
        Sermon::factory()->create(['series' => '']);

        $result = $this->repository->getExistingSeries();

        $this->assertCount(1, $result);
        $this->assertEquals(['Valid Series'], $result);
    }

    #[Test]
    public function it_caches_sermons_by_preacher_using_flexible_cache(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'test-preacher']);
        Sermon::factory()->count(2)->create(['preacher_id' => $preacher->id]);

        Cache::shouldReceive('flexible')
            ->once()
            ->with('sermons_preacher_test-preacher', [86400, 172800], \Closure::class)
            ->andReturn(collect());

        $this->repository->getSermonsByPreacher($preacher);
    }

    #[Test]
    public function it_clears_preacher_sermon_cache_when_preacher_model_passed_to_clear_caches(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'test-preacher']);

        Cache::shouldReceive('forget')->with('latest_sermons')->once();
        Cache::shouldReceive('forget')->with('all_sermons')->once();
        Cache::shouldReceive('forget')->with('sermon_series')->once();
        Cache::shouldReceive('forget')->with('sermons_preacher_test-preacher')->once();

        $this->repository->clearListingCaches($preacher);
    }

    #[Test]
    public function it_clears_preacher_sermon_cache_when_sermon_model_passed_to_clear_caches(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'test-preacher']);
        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'series' => null,
            'service' => null,
        ]);

        Cache::shouldReceive('forget')->with('latest_sermons')->once();
        Cache::shouldReceive('forget')->with('all_sermons')->once();
        Cache::shouldReceive('forget')->with('sermon_series')->once();
        Cache::shouldReceive('forget')->with('sermons_preacher_test-preacher')->once();

        $this->repository->clearListingCaches($sermon);
    }
}
