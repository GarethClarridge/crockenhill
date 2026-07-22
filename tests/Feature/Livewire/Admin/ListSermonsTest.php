<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\SermonContentType;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListSermonsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    #[Test]
    public function it_renders_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSermons::class)
            ->assertStatus(200)
            ->assertSee('Sermons');
    }

    #[Test]
    public function recording_actions_use_the_add_page_when_service_tracking_is_enabled(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSermons::class)
            ->assertSeeHtml(route('admin.services.add', ['intent' => 'recording']));

        config(['service-tracking.enabled' => false]);

        Livewire::test(ListSermons::class)
            ->assertSeeHtml(route('admin.services.upload-recording'));
    }

    #[Test]
    public function it_forbids_non_admins_from_the_sermons_admin_route(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.sermons.index'))
            ->assertForbidden();
    }

    #[Test]
    public function it_redirects_guests_from_the_sermons_admin_route(): void
    {
        $this->get(route('admin.sermons.index'))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Listing
    // -------------------------------------------------------------------------

    #[Test]
    public function it_lists_sermons_from_last_12_months_by_default(): void
    {
        $this->actingAs($this->admin);

        $recent = Sermon::factory()->withDate(now()->subMonths(6))->create(['title' => 'Recent Sermon']);
        $old = Sermon::factory()->withDate(now()->subMonths(14))->create(['title' => 'Old Sermon']);

        Livewire::test(ListSermons::class)
            ->assertSee('Recent Sermon')
            ->assertDontSee('Old Sermon');
    }

    #[Test]
    public function it_shows_all_sermons_when_last_12_months_filter_is_off(): void
    {
        $this->actingAs($this->admin);

        $old = Sermon::factory()->withDate(now()->subMonths(14))->create(['title' => 'Old Sermon']);

        Livewire::test(ListSermons::class)
            ->set('last12Months', false)
            ->assertSee('Old Sermon');
    }

    #[Test]
    public function it_shows_sermons_and_childrens_talks_in_admin_listing(): void
    {
        $this->actingAs($this->admin);

        $sermon = Sermon::factory()->create([
            'title' => 'Admin Sermon',
            'content_type' => SermonContentType::Sermon,
        ]);
        $childrensTalk = Sermon::factory()->create([
            'title' => "Admin Children's Talk",
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        Livewire::test(ListSermons::class)
            ->set('last12Months', false)
            ->assertSee('Admin Sermon')
            ->assertSee("Admin Children's Talk")
            ->assertSee('Sermon')
            ->assertSee("Children's Talk")
            ->assertSee(route('sermons.show', ['sermon' => $sermon->slug]))
            ->assertSee(route('childrens-corner.show', ['sermon' => $childrensTalk->slug]));
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    #[Test]
    public function it_filters_by_search_term(): void
    {
        $this->actingAs($this->admin);

        Sermon::factory()->withDate(now()->subWeeks(2))->create(['title' => 'Grace Abounding']);
        Sermon::factory()->withDate(now()->subWeeks(2))->create(['title' => 'The Good Shepherd']);

        Livewire::test(ListSermons::class)
            ->set('last12Months', false)
            ->set('search', 'Grace')
            ->assertSee('Grace Abounding')
            ->assertDontSee('The Good Shepherd');
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    #[Test]
    public function it_filters_sermons_that_need_preacher_review(): void
    {
        $this->actingAs($this->admin);

        $needsReview = Sermon::factory()->withDate(now()->subWeeks(1))->needsPreacherReview()->create(['title' => 'Needs Review']);
        $reviewed = Sermon::factory()->withDate(now()->subWeeks(1))->create(['title' => 'Already Reviewed', 'needs_preacher_review' => false]);

        Livewire::test(ListSermons::class)
            ->set('needsReviewFilter', true)
            ->assertSee('Needs Review')
            ->assertDontSee('Already Reviewed');
    }

    #[Test]
    public function it_filters_sermons_with_video(): void
    {
        $this->actingAs($this->admin);

        $withVideo = Sermon::factory()->withDate(now()->subWeeks(1))->withVideo()->create(['title' => 'Has Video']);
        $audioOnly = Sermon::factory()->withDate(now()->subWeeks(1))->create(['title' => 'Audio Only']);

        Livewire::test(ListSermons::class)
            ->set('hasVideoFilter', true)
            ->assertSee('Has Video')
            ->assertDontSee('Audio Only');
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    #[Test]
    public function admin_can_delete_a_sermon(): void
    {
        $this->actingAs($this->admin);

        $sermon = Sermon::factory()->create();

        Livewire::test(ListSermons::class)
            ->call('delete', $sermon)
            ->assertDispatched('notify', type: 'success', message: 'Sermon deleted');

        $this->assertModelMissing($sermon);
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(ListSermons::class)
            ->assertOk();
    }

    #[Test]
    public function it_shows_a_polished_empty_state_when_no_results_found(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSermons::class)
            ->set('last12Months', false)
            ->set('search', 'NonExistentSermon')
            ->assertSet('hasFilters', true)
            ->assertSee('No sermons found')
            ->assertSee("Your search and filters didn't return any results")
            ->assertSee('Clear all filters');
    }

    #[Test]
    public function it_does_not_show_clear_filters_button_when_empty_and_no_filters_active(): void
    {
        $this->actingAs($this->admin);

        Sermon::query()->delete();

        Livewire::test(ListSermons::class)
            ->set('last12Months', true) // Default is true, which IS a filter in this logic
            ->assertSet('hasFilters', false)
            ->assertSee('No sermons found')
            ->assertDontSee('Clear all filters');
    }
}
