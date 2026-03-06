<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherIndexTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_renders_the_preachers_index_page_successfully(): void
    {
        $response = $this->get('/christ/sermons/preachers');

        $response->assertStatus(200);
        $response->assertSee('Preachers');
    }

    #[Test]
    public function it_only_shows_active_preachers(): void
    {
        $activePreacher = Preacher::factory()->create(['name' => 'Active Preacher', 'is_active' => true]);
        $inactivePreacher = Preacher::factory()->create(['name' => 'Inactive Preacher', 'is_active' => false]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertSee('Active Preacher');
        $response->assertDontSee('Inactive Preacher');
    }

    #[Test]
    public function it_orders_preachers_by_sermon_count_descending_then_by_name(): void
    {
        // Clear existing preachers to have a clean state
        Preacher::query()->delete();
        Sermon::query()->delete();

        $preacherA = Preacher::factory()->create(['name' => 'Preacher A', 'is_active' => true]);
        $preacherB = Preacher::factory()->create(['name' => 'Preacher B', 'is_active' => true]);
        $preacherC = Preacher::factory()->create(['name' => 'Preacher C', 'is_active' => true]);

        // Preacher B has 2 sermons
        Sermon::factory()->count(2)->create(['preacher_id' => $preacherB->id]);
        // Preacher A has 1 sermon
        Sermon::factory()->create(['preacher_id' => $preacherA->id]);
        // Preacher C has 1 sermon (same as A, but C comes after A alphabetically)
        Sermon::factory()->create(['preacher_id' => $preacherC->id]);

        $response = $this->get('/christ/sermons/preachers');

        // Order should be: B (2 sermons), A (1 sermon), C (1 sermon)
        $response->assertSeeInOrder([
            'Preacher B',
            'Preacher A',
            'Preacher C',
        ]);
    }

    #[Test]
    public function it_shows_page_content_from_preachers_slug_if_it_exists(): void
    {
        // Clear pages to avoid unique slug constraint failure if multiple tests run in same session
        Page::query()->where('slug', 'preachers')->delete();

        Page::factory()->create([
            'slug' => 'preachers',
            'body' => 'This is the special preachers intro content.',
            'area' => 'christ',
        ]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertSee('This is the special preachers intro content.');
    }

    #[Test]
    public function it_displays_sermon_counts_for_each_preacher(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Counting Preacher', 'is_active' => true]);
        Sermon::factory()->count(13)->create(['preacher_id' => $preacher->id]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertSee('Counting Preacher');
        $response->assertSeeInOrder([
            'Counting Preacher',
            '13',
        ]);
    }
}
