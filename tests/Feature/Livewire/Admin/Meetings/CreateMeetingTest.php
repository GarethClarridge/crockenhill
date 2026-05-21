<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Meetings;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateMeetingTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->actingAs($this->admin);
        $page = Page::factory()->create();

        Log::shouldReceive('warning')
            ->once()
            ->with('New meeting created by admin', \Mockery::on(function ($args) {
                return $args['admin_id'] === $this->admin->id &&
                       $args['slug'] === 'new-meeting-test';
            }));

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
    public function it_validates_recurring_frequency(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('form.isRecurring', true)
            ->set('form.frequency', null)
            ->call('save')
            ->assertHasErrors(['form.frequency' => 'required_if']);
    }

    #[Test]
    public function it_clears_frequency_when_recurring_is_disabled(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('form.isRecurring', true)
            ->set('form.frequency', MeetingFrequency::Weekly->value)
            ->set('form.isRecurring', false)
            ->assertSet('form.frequency', null);
    }

    #[Test]
    public function it_can_create_a_recurring_meeting(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('form.slug', 'recurring-meeting')
            ->set('form.type', MeetingType::Adults->value)
            ->set('form.day', 'Monday')
            ->set('form.who', 'Adults')
            ->set('form.isRecurring', true)
            ->set('form.frequency', MeetingFrequency::Weekly->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('meetings', [
            'slug' => 'recurring-meeting',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::Weekly->value,
        ]);
    }
}
