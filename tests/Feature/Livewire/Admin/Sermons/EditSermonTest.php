<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Sermons;

use App\Actions\QueueScriptureEnrichment;
use App\Enums\SermonService;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditSermonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_can_update_title_and_custom_slug(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Original Title', 'slug' => 'original-title']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.title', 'New Updated Title')
            ->set('form.slug', 'custom-slug-override')
            ->call('save');

        $sermon->refresh();
        $this->assertEquals('New Updated Title', $sermon->title);
        $this->assertEquals('custom-slug-override', $sermon->slug);
    }

    #[Test]
    public function it_preserves_custom_slug_on_save(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Original Title', 'slug' => 'original-title']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.slug', 'custom-seo-slug')
            ->set('form.title', 'New Updated Title')
            ->call('save');

        $sermon->refresh();
        $this->assertEquals('custom-seo-slug', $sermon->slug);
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $sermon = Sermon::factory()->create();

        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertOk();
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Unique Sermon Title']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertStatus(200)
            ->assertSee('Edit Sermon');
    }

    #[Test]
    public function it_can_add_and_remove_points(): void
    {
        $sermon = Sermon::factory()->create(['points' => ['Point 1']]);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertSet('form.points', ['Point 1'])
            ->call('addPoint')
            ->assertSet('form.points', ['Point 1', ''])
            ->set('form.points.1', 'New Point')
            ->call('removePoint', 0)
            ->assertSet('form.points', ['New Point']);
    }

    #[Test]
    public function it_renders_dynamic_aria_labels_for_removing_sermon_points(): void
    {
        $sermon = Sermon::factory()->create(['points' => ['First Point', 'Second Point']]);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertSeeHtml('aria-label="Remove point: 1"')
            ->assertSeeHtml('aria-label="Remove point: 2"');
    }

    #[Test]
    public function it_validates_sermon_data(): void
    {
        $sermon = Sermon::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.title', '')
            ->set('form.preacherConfidence', 1.5) // Max 1
            ->set('form.duration', -10) // Min 0
            ->set('form.segmentStartTime', 100)
            ->set('form.segmentEndTime', 50) // Must be >= segmentStartTime
            ->call('save')
            ->assertHasErrors([
                'form.title' => 'required',
                'form.preacherConfidence' => 'max',
                'form.duration' => 'min',
                'form.segmentEndTime' => 'gte',
            ]);
    }

    #[Test]
    public function it_dispatches_scripture_enrichment_when_reference_changes(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);
        Config::set('services.api_bible.enabled', true);

        $this->actingAs($this->admin);

        $this->mock(QueueScriptureEnrichment::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once();
        });

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.reference', 'Romans 8:28')
            ->call('save')
            ->assertDispatched('notify');
    }

    #[Test]
    public function it_does_not_dispatch_scripture_enrichment_when_reference_remains_the_same(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);
        Config::set('services.api_bible.enabled', true);

        $this->actingAs($this->admin);

        $this->mock(QueueScriptureEnrichment::class, function ($mock) {
            $mock->shouldReceive('dispatch')->never();
        });

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.reference', 'John 3:16')
            ->call('save')
            ->assertDispatched('notify');
    }

    #[Test]
    public function it_clears_preacher_review_flag_on_save(): void
    {
        $sermon = Sermon::factory()->create(['needs_preacher_review' => true]);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->call('save');

        $this->assertFalse($sermon->fresh()->needs_preacher_review);
    }

    #[Test]
    public function it_can_save_basic_details(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Original Title',
            'service' => SermonService::Morning,
            'date' => '2025-01-01',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.title', 'Brand New Title')
            ->set('form.date', '2025-02-01')
            ->set('form.service', SermonService::Evening->value)
            ->call('save')
            ->assertDispatched('notify');

        $sermon->refresh();
        $this->assertEquals('Brand New Title', $sermon->title);
        $this->assertEquals('2025-02-01', $sermon->date->format('Y-m-d'));
        $this->assertEquals(SermonService::Evening, $sermon->service);
    }

    #[Test]
    public function it_resolves_preacher_by_name_if_id_is_missing(): void
    {
        $sermon = Sermon::factory()->create(['preacher' => 'Old Preacher', 'preacher_id' => null]);
        $preacher = Preacher::factory()->create(['name' => 'New Resolved Preacher']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.preacher', 'New Resolved Preacher')
            ->set('form.preacherId', null)
            ->call('save');

        $sermon->refresh();
        $this->assertEquals('New Resolved Preacher', $sermon->preacher);
        $this->assertEquals($preacher->id, $sermon->preacher_id);
    }
}
