<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Meetings;

use App\Enums\MeetingType;
use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateMeetingTest extends TestCase
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
        Livewire::test(CreateMeeting::class)
            ->assertOk();
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->assertStatus(200)
            ->assertSee('Create Meeting');
    }

    #[Test]
    public function it_can_create_a_meeting(): void
    {
        Log::spy();

        $this->actingAs($this->admin);
        $page = Page::factory()->create();

        Livewire::test(CreateMeeting::class)
            ->set('form.slug', 'new-meeting-test')
            ->set('form.type', MeetingType::Adults->value)
            ->set('form.day', 'Monday')
            ->set('form.startTime', '19:00')
            ->set('form.who', 'Adults')
            ->set('form.pageId', $page->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.meetings.index'));

        Log::assertLogged('warning', function ($message, $context) {
            return str_contains($message, 'New meeting created by admin') &&
                   $context['admin_id'] === $this->admin->id &&
                   $context['slug'] === 'new-meeting-test';
        });

        $this->assertDatabaseHas('meetings', [
            'slug' => 'new-meeting-test',
            'type' => MeetingType::Adults->value,
            'day' => 'Monday',
            'who' => 'Adults',
            'page_id' => $page->id,
        ]);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->call('save')
            ->assertHasErrors(['form.slug' => 'required', 'form.who' => 'required'])
            ->assertHasNoErrors(['form.day']);
    }

    #[Test]
    public function it_validates_slug_format(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('form.slug', 'Invalid Slug!')
            ->call('save')
            ->assertHasErrors(['form.slug' => 'regex']);
    }

    #[Test]
    public function it_validates_unique_slug(): void
    {
        Meeting::factory()->create(['slug' => 'existing-slug']);
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('form.slug', 'existing-slug')
            ->call('save')
            ->assertHasErrors(['form.slug' => 'unique']);
    }

    #[Test]
    public function it_accepts_valid_start_and_end_times(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('form.slug', 'timed-meeting')
            ->set('form.type', MeetingType::Adults->value)
            ->set('form.day', 'Monday')
            ->set('form.who', 'Adults')
            ->set('form.startTime', '10:30')
            ->set('form.endTime', '11:30')
            ->call('save')
            ->assertHasNoErrors(['form.startTime', 'form.endTime']);
    }

    #[Test]
    public function it_rejects_end_time_before_start_time(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('form.slug', 'bad-time-meeting')
            ->set('form.type', MeetingType::Adults->value)
            ->set('form.day', 'Monday')
            ->set('form.who', 'Adults')
            ->set('form.startTime', '11:30')
            ->set('form.endTime', '10:30')
            ->call('save')
            ->assertHasErrors(['form.endTime' => 'after_or_equal']);
    }
}
