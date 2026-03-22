<?php

namespace Tests\Feature\Livewire\Admin;

use App\Enums\SermonService;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Livewire\Admin\Users\ListUsers;
use App\Livewire\Admin\CalendarEvents\ListCalendarEvents;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ListSongs;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\ChurchService;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\User;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Models\Sermon;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchSecurityAndGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function it_escapes_wildcards_in_pages_search(): void
    {
        $this->actingAs($this->admin);

        Page::factory()->create(['heading' => 'Normal Page', 'slug' => 'normal']);
        Page::factory()->create(['heading' => 'Special % Page', 'slug' => 'special']);

        Livewire::test(ListPages::class)
            ->set('search', '%')
            ->assertSee('Special % Page')
            ->assertDontSee('Normal Page');
    }

    #[Test]
    public function it_escapes_wildcards_in_preachers_search(): void
    {
        $this->actingAs($this->admin);

        Preacher::factory()->create(['name' => 'John Doe']);
        Preacher::factory()->create(['name' => 'Jane _ Doe']);

        Livewire::test(ListPreachers::class)
            ->set('search', '_')
            ->assertSee('Jane _ Doe')
            ->assertDontSee('John Doe');
    }

    #[Test]
    public function it_escapes_wildcards_in_users_search(): void
    {
        $this->actingAs($this->admin);

        User::factory()->create(['name' => 'Alice']);
        User::factory()->create(['name' => 'Bob %']);

        Livewire::test(ListUsers::class)
            ->set('search', '%')
            ->assertSee('Bob %')
            ->assertDontSee('Alice');
    }

    #[Test]
    public function it_escapes_wildcards_in_calendar_events_search(): void
    {
        $this->actingAs($this->admin);

        CalendarEvent::factory()->create(['title' => 'Morning Prayer', 'start_datetime' => now()]);
        CalendarEvent::factory()->create(['title' => 'Evening % Prayer', 'start_datetime' => now()]);

        Livewire::test(ListCalendarEvents::class)
            ->set('search', '%')
            ->assertSee('Evening % Prayer')
            ->assertDontSee('Morning Prayer');
    }

    #[Test]
    public function it_groups_search_conditions_correctly_in_pages(): void
    {
        $this->actingAs($this->admin);

        // Page in area 'christ' matching search
        Page::factory()->create(['heading' => 'Grace', 'area' => 'christ', 'slug' => 'grace']);
        // Page in area 'community' matching search (should be filtered out by areaFilter)
        Page::factory()->create(['heading' => 'Grace Community', 'area' => 'community', 'slug' => 'grace-comm']);

        Livewire::test(ListPages::class)
            ->set('areaFilter', 'christ')
            ->set('search', 'Grace')
            ->assertSee('Grace')
            ->assertDontSee('Grace Community');
    }

    #[Test]
    public function it_groups_search_conditions_correctly_in_sermons(): void
    {
        $this->actingAs($this->admin);

        // Sermon matching search in 'morning' service
        Sermon::factory()->create([
            'title' => 'The Grace of God',
            'service' => SermonService::MORNING,
            'date' => now()->subDays(1),
        ]);

        // Sermon matching search in 'evening' service (should be filtered out by serviceFilter)
        Sermon::factory()->create([
            'title' => 'Evening Grace',
            'service' => SermonService::EVENING,
            'date' => now()->subDays(2),
        ]);

        Livewire::test(ListSermons::class)
            ->set('serviceFilter', SermonService::MORNING->value)
            ->set('search', 'Grace')
            ->assertSee('The Grace of God')
            ->assertDontSee('Evening Grace');
    }

    #[Test]
    public function it_escapes_wildcards_in_church_services_search(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create(['original_filename' => 'normal.mp4', 'date' => now()->subDays(1)]);
        ChurchService::factory()->create(['original_filename' => 'special %.mp4', 'date' => now()->subDays(2)]);

        Livewire::test(ListChurchServices::class)
            ->set('search', '%')
            ->assertSee('special %.mp4')
            ->assertDontSee('normal.mp4');
    }

    #[Test]
    public function it_escapes_wildcards_in_songs_search(): void
    {
        $this->actingAs($this->admin);

        Song::factory()->create(['title' => 'Normal Song', 'canonical_key' => 'normal-song']);
        Song::factory()->create(['title' => 'Special _ Song', 'canonical_key' => 'special-song']);

        Livewire::test(ListSongs::class)
            ->set('search', '_')
            ->assertSee('Special _ Song')
            ->assertDontSee('Normal Song');
    }

    #[Test]
    public function it_escapes_wildcards_in_meetings_search(): void
    {
        $this->actingAs($this->admin);

        $page1 = Page::factory()->create(['heading' => 'Normal Meeting', 'slug' => 'normal']);
        Meeting::factory()->create(['page_id' => $page1->id, 'slug' => 'normal']);

        $page2 = Page::factory()->create(['heading' => 'Special % Meeting', 'slug' => 'special']);
        Meeting::factory()->create(['page_id' => $page2->id, 'slug' => 'special']);

        Livewire::test(ListMeetings::class)
            ->set('search', '%')
            ->assertSee('Special % Meeting')
            ->assertDontSee('Normal Meeting');
    }
}
