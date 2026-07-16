<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

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

        Meeting::factory()->create(['slug' => 'list-meeting-slug']);

        Livewire::test(ListMeetings::class)
            ->set('sortBy', 'invalid_column')
            ->set('sortDirection', 'sideways')
            ->assertSet('sortBy', 'updated_at')
            ->assertSet('sortDirection', 'desc')
            ->assertSee('list-meeting-slug');
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
            ->set('form.slug', 'midweek-bible-study')
            ->set('form.type', MeetingType::Adults->value)
            ->set('form.startTime', '19:30')
            ->set('form.endTime', '21:00')
            ->set('form.day', 'Wednesday')
            ->set('form.location', 'Church Hall')
            ->set('form.who', 'Adults')
            ->set('form.pictures', true)
            ->set('form.leadersPhone', '0123456789')
            ->set('form.leadersEmail', 'leader@example.com')
            ->set('form.pageId', $page->id)
            ->call('save')
            ->assertRedirect(route('admin.meetings.index'));

        $meeting = Meeting::query()->where('slug', 'midweek-bible-study')->firstOrFail();

        $this->assertSame($page->id, $meeting->page_id);
        $this->assertSame(MeetingType::Adults, $meeting->type);
        $this->assertSame('19:30', $meeting->start_time?->format('H:i'));
        $this->assertSame('21:00', $meeting->end_time?->format('H:i'));
        $this->assertTrue($meeting->pictures);
    }

    #[Test]
    public function edit_meeting_mounts_existing_values(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();
        $meeting = Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'evening-prayer',
            'type' => MeetingType::Occasional->value,
            'start_time' => '18:15:00',
            'end_time' => '19:45:00',
            'day' => 'Friday',
            'location' => 'Lower Hall',
            'who' => 'All Ages',
            'pictures' => true,
            'leaders_phone' => '0123400000',
            'leaders_email' => 'old@example.com',
        ]);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->assertSet('form.slug', 'evening-prayer')
            ->assertSet('form.type', MeetingType::Occasional->value)
            ->assertSet('form.startTime', '18:15')
            ->assertSet('form.endTime', '19:45')
            ->assertSet('form.day', 'Friday')
            ->assertSet('form.location', 'Lower Hall')
            ->assertSet('form.who', 'All Ages')
            ->assertSet('form.pictures', true)
            ->assertSet('form.leadersPhone', '0123400000')
            ->assertSet('form.leadersEmail', 'old@example.com')
            ->assertSet('form.pageId', $page->id);
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
            'type' => MeetingType::Adults->value,
        ]);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->set('form.slug', 'new-slug')
            ->set('form.type', MeetingType::ChildrenAndYoungPeople->value)
            ->set('form.startTime', '17:00')
            ->set('form.endTime', '18:30')
            ->set('form.day', 'Saturday')
            ->set('form.location', 'Community Hall')
            ->set('form.who', 'Youth')
            ->set('form.pictures', false)
            ->set('form.leadersPhone', '0987654321')
            ->set('form.leadersEmail', 'new@example.com')
            ->set('form.pageId', $newPage->id)
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Meeting updated');

        $meeting->refresh();

        $this->assertSame('new-slug', $meeting->slug);
        $this->assertSame(MeetingType::ChildrenAndYoungPeople, $meeting->type);
        $this->assertSame('17:00', $meeting->start_time?->format('H:i'));
        $this->assertSame('18:30', $meeting->end_time?->format('H:i'));
        $this->assertSame('Saturday', $meeting->day);
        $this->assertSame('Community Hall', $meeting->location);
        $this->assertSame('Youth', $meeting->who);
        $this->assertFalse($meeting->pictures);
        $this->assertSame('0987654321', $meeting->leaders_phone);
        $this->assertSame('new@example.com', $meeting->leaders_email);
        $this->assertSame($newPage->id, $meeting->page_id);
    }

    #[Test]
    public function edit_meeting_validates_slug_uniqueness(): void
    {
        $this->actingAs($this->admin);

        $existing = Meeting::factory()->create(['slug' => 'existing-slug']);
        $editable = Meeting::factory()->create(['slug' => 'editable-slug']);

        Livewire::test(EditMeeting::class, ['meeting' => $editable])
            ->set('form.slug', $existing->slug)
            ->call('save')
            ->assertHasErrors(['form.slug' => ['unique']]);
    }
}
