<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_only_shows_active_preachers(): void
    {
        Preacher::factory()->create(['name' => 'Active Preacher', 'is_active' => true]);
        Preacher::factory()->create(['name' => 'Inactive Preacher', 'is_active' => false]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertOk();
        $response->assertSee('Active Preacher');
        $response->assertDontSee('Inactive Preacher');
    }

    #[Test]
    public function it_orders_preachers_by_sermon_count_descending_then_by_name(): void
    {
        Sermon::query()->delete();
        Preacher::query()->delete();

        $preacherA = Preacher::factory()->create(['name' => 'Preacher A', 'is_active' => true]);
        $preacherB = Preacher::factory()->create(['name' => 'Preacher B', 'is_active' => true]);
        $preacherC = Preacher::factory()->create(['name' => 'Preacher C', 'is_active' => true]);

        Sermon::factory()->count(2)->create(['preacher_id' => $preacherB->id]);
        Sermon::factory()->create(['preacher_id' => $preacherA->id]);
        Sermon::factory()->create(['preacher_id' => $preacherC->id]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertOk();
        $response->assertSeeInOrder([
            'Preacher B',
            'Preacher A',
            'Preacher C',
        ]);
    }

    #[Test]
    public function it_shows_page_content_from_the_preachers_page(): void
    {
        Page::query()->where('slug', 'preachers')->delete();

        Page::factory()->create([
            'slug' => 'preachers',
            'area' => 'christ',
            'body' => 'This is the special preachers intro content.',
        ]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertOk();
        $response->assertSee('This is the special preachers intro content.');
    }

    #[Test]
    public function it_displays_sermon_counts_for_each_preacher(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Counting Preacher', 'is_active' => true]);
        Sermon::factory()->count(13)->create(['preacher_id' => $preacher->id]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertOk();
        $response->assertSeeInOrder([
            'Counting Preacher',
            '13',
        ]);
    }

    #[Test]
    public function it_renders_absolute_preacher_links_using_named_routes(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen', 'slug' => 'john-owen', 'is_active' => true]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertOk();
        $expectedUrl = route('sermons.preacher', $preacher->slug);
        $response->assertSee('href="'.$expectedUrl.'"', false);
        $response->assertDontSee('href="preachers/john-owen"', false);
    }
}
