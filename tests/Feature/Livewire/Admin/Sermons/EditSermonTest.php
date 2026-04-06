<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Sermons;

use App\Actions\QueueScriptureEnrichment;
use App\Enums\SermonService;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditSermonTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_updates_slug_automatically_when_title_changes(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Original Title', 'slug' => 'original-title']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('title', 'New Updated Title')
            ->assertSet('slug', 'new-updated-title');
    }

    #[Test]
    public function it_does_not_update_slug_automatically_if_manually_overridden(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Original Title', 'slug' => 'original-title']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('slug', 'custom-seo-slug')
            ->set('title', 'New Updated Title')
            ->assertSet('slug', 'custom-seo-slug');
    }

    #[Test]
    public function it_authorizes_admin_access(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $sermon = Sermon::factory()->create();

        $this->actingAs($user);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertForbidden();
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
            ->assertSet('points', ['Point 1'])
            ->call('addPoint')
            ->assertSet('points', ['Point 1', ''])
            ->set('points.1', 'New Point')
            ->call('removePoint', 0)
            ->assertSet('points', ['New Point']);
    }

    #[Test]
    public function it_validates_sermon_data(): void
    {
        $sermon = Sermon::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('title', '')
            ->set('preacherConfidence', 1.5) // Max 1
            ->set('duration', -10) // Min 0
            ->set('segmentStartTime', 100)
            ->set('segmentEndTime', 50) // Must be >= segmentStartTime
            ->call('save')
            ->assertHasErrors([
                'title' => 'required',
                'preacherConfidence' => 'max',
                'duration' => 'min',
                'segmentEndTime' => 'gte',
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
            ->set('reference', 'Romans 8:28')
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
            ->set('reference', 'John 3:16')
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
            'service' => SermonService::MORNING,
            'date' => '2025-01-01',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('title', 'Brand New Title')
            ->set('date', '2025-02-01')
            ->set('service', SermonService::EVENING->value)
            ->call('save')
            ->assertDispatched('notify');

        $sermon->refresh();
        $this->assertEquals('Brand New Title', $sermon->title);
        $this->assertEquals('2025-02-01', $sermon->date->format('Y-m-d'));
        $this->assertEquals(SermonService::EVENING, $sermon->service);
    }

    #[Test]
    public function it_resolves_preacher_by_name_if_id_is_missing(): void
    {
        $sermon = Sermon::factory()->create(['preacher' => 'Old Preacher', 'preacher_id' => null]);
        $preacher = Preacher::factory()->create(['name' => 'New Resolved Preacher']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('preacher', 'New Resolved Preacher')
            ->set('preacherId', null)
            ->call('save');

        $sermon->refresh();
        $this->assertEquals('New Resolved Preacher', $sermon->preacher);
        $this->assertEquals($preacher->id, $sermon->preacher_id);
    }
}
