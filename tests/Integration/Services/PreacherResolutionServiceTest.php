<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Services\PreacherResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PreacherResolutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PreacherResolutionService;
    }

    #[Test]
    public function it_creates_a_new_preacher_and_alias_for_an_unknown_name(): void
    {
        $preacher = $this->service->resolve('John Smith');

        $this->assertInstanceOf(Preacher::class, $preacher);
        $this->assertEquals('John Smith', $preacher->name);
        $this->assertEquals('john-smith', $preacher->slug);

        $this->assertDatabaseHas('preacher_aliases', [
            'alias' => 'john smith',
            'preacher_id' => $preacher->id,
        ]);
    }

    #[Test]
    public function it_returns_existing_preacher_when_alias_matches(): void
    {
        $existing = Preacher::factory()->create(['name' => 'Jane Doe', 'slug' => 'jane-doe']);
        PreacherAlias::create(['alias' => 'jane doe', 'preacher_id' => $existing->id]);
        $countBefore = Preacher::count();

        $resolved = $this->service->resolve('Jane Doe');

        $this->assertEquals($existing->id, $resolved->id);
        $this->assertEquals($countBefore, Preacher::count());
    }

    #[Test]
    public function it_normalizes_whitespace_and_casing_in_input(): void
    {
        $preacher = $this->service->resolve('  new   guest  ');

        $this->assertEquals('New Guest', $preacher->name);
        $this->assertEquals('new-guest', $preacher->slug);

        $this->assertDatabaseHas('preacher_aliases', ['alias' => 'new guest']);
    }

    #[Test]
    public function it_falls_back_to_visiting_speaker_for_empty_name(): void
    {
        $preacher = $this->service->resolve('');

        $this->assertEquals('Visiting Speaker', $preacher->name);
        $this->assertDatabaseHas('preacher_aliases', ['alias' => 'visiting speaker']);
    }

    #[Test]
    public function it_resolves_to_the_same_preacher_for_repeat_calls_with_same_name(): void
    {
        $preacherCountBefore = Preacher::count();
        $aliasCountBefore = PreacherAlias::count();

        $first = $this->service->resolve('Tim Keller');
        $second = $this->service->resolve('Tim Keller');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals($preacherCountBefore + 1, Preacher::count());
        $this->assertEquals($aliasCountBefore + 1, PreacherAlias::count());
    }
}
