<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\PreacherSource;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSermonTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_renders_successfully()
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSermons::class)
            ->assertStatus(200)
            ->assertSee('Sermons');
    }

    #[Test]
    public function it_requires_admin_to_view()
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.sermons.index'))
            ->assertForbidden();
    }

    #[Test]
    public function it_can_search_sermons()
    {
        $this->actingAs($this->admin);

        Sermon::factory()->create(['title' => 'Searchable Title', 'date' => now()]);
        Sermon::factory()->create(['title' => 'Other Sermon', 'date' => now()]);

        Livewire::test(ListSermons::class)
            ->set('search', 'Searchable')
            ->assertSee('Searchable Title')
            ->assertDontSee('Other Sermon');
    }

    #[Test]
    public function it_can_filter_by_preacher()
    {
        $this->actingAs($this->admin);

        $johnDoe = Preacher::factory()->create(['name' => 'John Doe']);
        $janeSmith = Preacher::factory()->create(['name' => 'Jane Smith']);

        Sermon::factory()->create(['preacher' => 'John Doe', 'preacher_id' => $johnDoe->id, 'title' => 'Sermon A', 'date' => now()]);
        Sermon::factory()->create(['preacher' => 'Jane Smith', 'preacher_id' => $janeSmith->id, 'title' => 'Sermon B', 'date' => now()]);

        Livewire::test(ListSermons::class)
            ->set('preacherFilter', $johnDoe->id)
            ->assertSee('Sermon A')
            ->assertDontSee('Sermon B');
    }

    #[Test]
    public function it_can_delete_a_sermon()
    {
        $this->actingAs($this->admin);

        $sermon = Sermon::factory()->create();

        Livewire::test(ListSermons::class)
            ->call('delete', $sermon)
            ->assertDispatched('notify');

        $this->assertModelMissing($sermon);
    }

    #[Test]
    public function it_resets_invalid_sort_input_to_safe_defaults()
    {
        $this->actingAs($this->admin);

        Sermon::factory()->create(['title' => 'Alpha Sermon', 'date' => now()]);

        Livewire::test(ListSermons::class)
            ->set('sortBy', 'invalid_column')
            ->set('sortDirection', 'sideways')
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDirection', 'desc')
            ->assertSee('Alpha Sermon');
    }

    #[Test]
    public function edit_form_shows_review_banner_when_preacher_review_needed()
    {
        $this->actingAs($this->admin);

        $sermon = Sermon::factory()->create([
            'needs_preacher_review' => true,
            'preacher_source' => PreacherSource::Default,
            'preacher_confidence' => null,
            'show_summary' => true,
            'show_points' => true,
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertSee('Preacher review required')
            ->assertSee('No speaker could be automatically identified');
    }

    #[Test]
    public function edit_form_shows_confidence_score_when_ai_identified_speaker()
    {
        $this->actingAs($this->admin);

        $sermon = Sermon::factory()->create([
            'needs_preacher_review' => true,
            'preacher_source' => PreacherSource::SpeakerModel,
            'preacher_confidence' => 0.73,
            'show_summary' => true,
            'show_points' => true,
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertSee('Preacher review required')
            ->assertSee('73%');
    }

    #[Test]
    public function edit_form_does_not_show_review_banner_when_no_review_needed()
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();
        $sermon = Sermon::factory()->withPreacher($preacher)->create([
            'needs_preacher_review' => false,
            'show_summary' => true,
            'show_points' => true,
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertDontSee('Preacher review required');
    }

    #[Test]
    public function saving_edit_form_clears_preacher_review_flag()
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create(['name' => 'John Doe']);
        $sermon = Sermon::factory()->create([
            'needs_preacher_review' => true,
            'preacher_source' => PreacherSource::Default,
            'show_summary' => true,
            'show_points' => true,
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.preacherId', $preacher->id)
            ->call('save');

        $this->assertFalse($sermon->fresh()->needs_preacher_review);
    }
}
