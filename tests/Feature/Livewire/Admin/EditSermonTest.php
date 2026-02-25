<?php

namespace Tests\Feature\Livewire\Admin;

use App\Enums\SermonService;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditSermonTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Sermon $sermon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->sermon = Sermon::factory()->create([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'date' => '2025-06-15',
            'service' => SermonService::MORNING->value,
            'preacher' => 'John Smith',
            'reference' => 'John 3:16',
            'series' => null,
            'summary' => null,
            'points' => null,
            'show_summary' => true,
            'show_points' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Rendering & mount
    // -------------------------------------------------------------------------

    #[Test]
    public function it_renders_with_sermon_data_pre_populated(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->assertSet('title', 'Original Title')
            ->assertSet('slug', 'original-title')
            ->assertSet('preacher', 'John Smith')
            ->assertSet('reference', 'John 3:16')
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Save — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_saves_updated_sermon_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('title', 'Updated Title')
            ->set('slug', 'updated-title')
            ->set('date', '2025-07-01')
            ->set('service', SermonService::EVENING->value)
            ->set('preacher', 'David Johnson')
            ->set('reference', 'Romans 8:28')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Sermon updated');

        $this->sermon->refresh();
        $this->assertEquals('Updated Title', $this->sermon->title);
        $this->assertEquals('updated-title', $this->sermon->slug);
        $this->assertEquals('David Johnson', $this->sermon->preacher);
        $this->assertEquals('Romans 8:28', $this->sermon->reference);
    }

    #[Test]
    public function it_links_sermon_to_preacher_model_when_preacher_id_is_set(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create(['name' => 'Mark Drury']);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('preacherId', $preacher->id)
            ->set('preacher', $preacher->name)
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Sermon updated');

        $this->sermon->refresh();
        $this->assertEquals($preacher->id, $this->sermon->preacher_id);
        $this->assertEquals(\App\Enums\PreacherSource::MANUAL, $this->sermon->preacher_source);
        $this->assertFalse($this->sermon->needs_preacher_review);
    }

    // -------------------------------------------------------------------------
    // Slug auto-update
    // -------------------------------------------------------------------------

    #[Test]
    public function slug_is_auto_updated_when_title_changes(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('title', 'My New Sermon Title')
            ->assertSet('slug', 'my-new-sermon-title');
    }

    // -------------------------------------------------------------------------
    // Points management
    // -------------------------------------------------------------------------

    #[Test]
    public function it_can_add_a_point(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->call('addPoint')
            ->assertCount('points', 1);
    }

    #[Test]
    public function it_can_remove_a_point(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->call('addPoint')
            ->call('addPoint')
            ->assertCount('points', 2)
            ->call('removePoint', 0)
            ->assertCount('points', 1);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('title', '')
            ->set('slug', '')
            ->set('date', '')
            ->set('preacher', '')
            ->call('save')
            ->assertHasErrors(['title', 'slug', 'date', 'preacher']);
    }

    #[Test]
    public function it_validates_slug_uniqueness_against_other_sermons(): void
    {
        $this->actingAs($this->admin);

        $other = Sermon::factory()->create(['slug' => 'taken-slug']);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('slug', 'taken-slug')
            ->call('save')
            ->assertHasErrors(['slug']);
    }

    #[Test]
    public function it_allows_saving_with_the_sermons_own_slug(): void
    {
        $this->actingAs($this->admin);

        // The unique rule should exclude the current sermon's own ID
        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('slug', 'original-title')
            ->call('save')
            ->assertHasNoErrors(['slug']);
    }
}
