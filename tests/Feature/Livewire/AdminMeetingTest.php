<?php

namespace Tests\Feature\Livewire;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Livewire\Admin\Meetings\EditMeeting;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminMeetingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_resets_invalid_sort_input_to_safe_defaults(): void
    {
        $this->actingAs($this->admin);

        Meeting::factory()->create(['slug' => 'prayer-meeting']);

        Livewire::test(ListMeetings::class)
            ->set('sortBy', 'invalid_column')
            ->set('sortDirection', 'sideways')
            ->assertSet('sortBy', 'updated_at')
            ->assertSet('sortDirection', 'desc')
            ->assertSee('prayer-meeting');
    }

    #[Test]
    public function sort_action_rejects_non_allowlisted_columns(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListMeetings::class)
            ->set('sortBy', 'day')
            ->set('sortDirection', 'asc')
            ->call('sort', 'invalid_column')
            ->assertSet('sortBy', 'updated_at')
            ->assertSet('sortDirection', 'desc');
    }

    #[Test]
    public function admin_can_create_meeting_with_schedule_and_page_link(): void
    {
        $this->actingAs($this->admin);
        $page = Page::factory()->create(['heading' => 'Midweek Bible Study']);

        Livewire::test(CreateMeeting::class)
            ->set('slug', 'midweek-bible-study')
            ->set('type', MeetingType::ADULTS->value)
            ->set('startTime', '19:30')
            ->set('endTime', '21:00')
            ->set('day', 'Wednesday')
            ->set('location', 'Church Hall')
            ->set('who', 'Adults')
            ->set('pictures', true)
            ->set('leadersPhone', '0123456789')
            ->set('leadersEmail', 'leader@example.com')
            ->set('meetingDate', '2026-03-11')
            ->set('isRecurring', true)
            ->set('frequency', MeetingFrequency::WEEKLY->value)
            ->set('pageId', $page->id)
            ->call('save')
            ->assertRedirect(route('admin.meetings.index'));

        $meeting = Meeting::query()->where('slug', 'midweek-bible-study')->firstOrFail();

        $this->assertSame($page->id, $meeting->page_id);
        $this->assertSame(MeetingType::ADULTS, $meeting->type);
        $this->assertSame(MeetingFrequency::WEEKLY, $meeting->frequency);
        $this->assertSame('19:30', $meeting->start_time?->format('H:i'));
        $this->assertSame('21:00', $meeting->end_time?->format('H:i'));
        $this->assertSame('2026-03-11', $meeting->meeting_date?->format('Y-m-d'));
        $this->assertTrue($meeting->pictures);
    }

    #[Test]
    public function create_meeting_requires_frequency_when_recurring_is_enabled(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('slug', 'weekly-prayer')
            ->set('type', MeetingType::ADULTS->value)
            ->set('day', 'Thursday')
            ->set('who', 'Adults')
            ->set('isRecurring', true)
            ->set('frequency', null)
            ->call('save')
            ->assertHasErrors(['frequency' => ['required_if']]);
    }

    #[Test]
    public function updated_is_recurring_clears_frequency_when_toggled_off(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateMeeting::class)
            ->set('isRecurring', true)
            ->set('frequency', MeetingFrequency::WEEKLY->value)
            ->set('isRecurring', false)
            ->assertSet('frequency', null);
    }

    #[Test]
    public function edit_meeting_mounts_existing_values(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();
        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'evening-prayer',
            'type' => MeetingType::OCCASIONAL->value,
            'start_time' => '18:15:00',
            'end_time' => '19:45:00',
            'day' => 'Friday',
            'location' => 'Lower Hall',
            'who' => 'All Ages',
            'pictures' => true,
            'leaders_phone' => '0123400000',
            'leaders_email' => 'old@example.com',
            'meeting_date' => '2026-05-20',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::MONTHLY->value,
        ]);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->assertSet('slug', 'evening-prayer')
            ->assertSet('type', MeetingType::OCCASIONAL->value)
            ->assertSet('startTime', '18:15')
            ->assertSet('endTime', '19:45')
            ->assertSet('day', 'Friday')
            ->assertSet('location', 'Lower Hall')
            ->assertSet('who', 'All Ages')
            ->assertSet('pictures', true)
            ->assertSet('leadersPhone', '0123400000')
            ->assertSet('leadersEmail', 'old@example.com')
            ->assertSet('meetingDate', '2026-05-20')
            ->assertSet('isRecurring', true)
            ->assertSet('frequency', MeetingFrequency::MONTHLY->value)
            ->assertSet('pageId', $page->id);
    }

    #[Test]
    public function admin_can_update_meeting_from_edit_component(): void
    {
        $this->actingAs($this->admin);

        $oldPage = Page::factory()->create();
        $newPage = Page::factory()->create();
        $meeting = Meeting::factory()->create([
            'page_id' => $oldPage->id,
            'slug' => 'old-slug',
            'type' => MeetingType::ADULTS->value,
            'meeting_date' => '2026-03-01',
            'is_recurring' => true,
            'frequency' => MeetingFrequency::WEEKLY->value,
        ]);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->set('slug', 'new-slug')
            ->set('type', MeetingType::CHILDREN_AND_YOUNG_PEOPLE->value)
            ->set('startTime', '17:00')
            ->set('endTime', '18:30')
            ->set('day', 'Saturday')
            ->set('location', 'Community Hall')
            ->set('who', 'Youth')
            ->set('pictures', false)
            ->set('leadersPhone', '0987654321')
            ->set('leadersEmail', 'new@example.com')
            ->set('meetingDate', '2026-04-04')
            ->set('isRecurring', false)
            ->set('pageId', $newPage->id)
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Meeting updated');

        $meeting->refresh();

        $this->assertSame('new-slug', $meeting->slug);
        $this->assertSame(MeetingType::CHILDREN_AND_YOUNG_PEOPLE, $meeting->type);
        $this->assertSame('17:00', $meeting->start_time?->format('H:i'));
        $this->assertSame('18:30', $meeting->end_time?->format('H:i'));
        $this->assertSame('Saturday', $meeting->day);
        $this->assertSame('Community Hall', $meeting->location);
        $this->assertSame('Youth', $meeting->who);
        $this->assertFalse($meeting->pictures);
        $this->assertSame('0987654321', $meeting->leaders_phone);
        $this->assertSame('new@example.com', $meeting->leaders_email);
        $this->assertSame('2026-04-04', $meeting->meeting_date?->format('Y-m-d'));
        $this->assertFalse($meeting->is_recurring);
        $this->assertNull($meeting->frequency);
        $this->assertSame($newPage->id, $meeting->page_id);
    }

    #[Test]
    public function edit_meeting_validates_slug_uniqueness(): void
    {
        $this->actingAs($this->admin);

        $existing = Meeting::factory()->create(['slug' => 'existing-slug']);
        $editable = Meeting::factory()->create(['slug' => 'editable-slug']);

        Livewire::test(EditMeeting::class, ['meeting' => $editable])
            ->set('slug', $existing->slug)
            ->call('save')
            ->assertHasErrors(['slug' => ['unique']]);
    }
}
