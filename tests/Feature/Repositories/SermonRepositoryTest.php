<?php

namespace Tests\Feature\Repositories;

use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
