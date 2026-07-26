<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Meetings;

use App\Enums\MeetingType;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListMeetingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(ListMeetings::class)
            ->assertOk();
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->assertStatus(200)
            ->assertSee('Meetings');
    }

    #[Test]
    public function it_lists_meetings(): void
    {
        $page = Page::factory()->create(['heading' => 'Prayer Meeting']);
        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'who' => 'Everyone',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->assertSee('Prayer Meeting')
            ->assertSee('Everyone');
    }

    #[Test]
    public function it_filters_by_search_term_on_page_heading(): void
    {
        $page1 = Page::factory()->create(['heading' => 'Bible Study']);
        $meeting1 = Meeting::factory()->create(['page_id' => $page1->id]);

        $page2 = Page::factory()->create(['heading' => 'Youth Group']);
        $meeting2 = Meeting::factory()->create(['page_id' => $page2->id]);

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('search', 'Bible')
            ->assertSee('Bible Study')
            ->assertDontSee('Youth Group');
    }

    #[Test]
    public function it_filters_by_search_term_on_day(): void
    {
        $meeting1 = Meeting::factory()->create(['day' => 'Monday']);
        $meeting2 = Meeting::factory()->create(['day' => 'Tuesday']);

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('search', 'Monday')
            ->assertSee('Monday')
            ->assertDontSee('Tuesday');
    }

    #[Test]
    public function it_filters_by_search_term_on_who(): void
    {
        $meeting1 = Meeting::factory()->create(['who' => 'Children']);
        $meeting2 = Meeting::factory()->create(['who' => 'Seniors']);

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('search', 'Children')
            ->assertSee('Children')
            ->assertDontSee('Seniors');
    }

    #[Test]
    public function it_filters_by_type(): void
    {
        Meeting::query()->delete();
        $meeting1 = Meeting::factory()->create(['type' => MeetingType::Adults->value, 'who' => 'Adult Meeting']);
        $meeting2 = Meeting::factory()->create(['type' => MeetingType::Occasional->value, 'who' => 'Occasional Meeting']);

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('typeFilter', MeetingType::Adults->value)
            ->assertSee('Adult Meeting')
            ->assertDontSee('Occasional Meeting');
    }

    #[Test]
    public function it_sorts_meetings(): void
    {
        Meeting::query()->delete();
        Meeting::factory()->create(['slug' => 'b-meeting']);
        Meeting::factory()->create(['slug' => 'a-meeting']);
        Meeting::factory()->create(['slug' => 'c-meeting']);

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('sortBy', 'slug')
            ->set('sortDirection', 'asc')
            ->assertSeeInOrder(['a-meeting', 'b-meeting', 'c-meeting'])
            ->set('sortDirection', 'desc')
            ->assertSeeInOrder(['c-meeting', 'b-meeting', 'a-meeting']);
    }

    #[Test]
    public function it_sorts_by_schedule(): void
    {
        Meeting::query()->delete();
        Meeting::factory()->create(['day' => 'Monday', 'start_time' => '10:00:00', 'end_time' => '11:00:00']);
        Meeting::factory()->create(['day' => 'Monday', 'start_time' => '09:00:00', 'end_time' => '10:00:00']);
        Meeting::factory()->create(['day' => 'Wednesday', 'start_time' => '10:00:00', 'end_time' => '11:00:00']);

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('sortBy', 'schedule')
            ->set('sortDirection', 'asc')
            ->assertSeeInOrder(['Monday', 'Monday', 'Wednesday']);
    }

    #[Test]
    public function it_can_delete_a_meeting(): void
    {
        Log::spy();
        $meeting = Meeting::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->call('delete', $meeting)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'Meeting deleted') &&
                ($context['admin_id'] ?? null) === $this->admin->id &&
                ($context['meeting_id'] ?? null) === $meeting->id &&
                ($context['slug'] ?? null) === $meeting->slug);
    }
}
