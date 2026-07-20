<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\CalendarEventStatus;
use App\Enums\InboundEmailStatus;
use App\Livewire\Admin\CalendarEvents\EditCalendarEvent;
use App\Livewire\Admin\CalendarEvents\ListCalendarEvents;
use App\Livewire\Admin\ChurchServices\ReviewInbox;
use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Livewire\Admin\Meetings\EditMeeting;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Admin\Pages\CreatePage;
use App\Livewire\Admin\Pages\EditPage;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\CreatePreacher;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Livewire\Admin\Users\CreateUser;
use App\Models\CalendarEvent;
use App\Models\InboundEmail;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_logs_sermon_deletion_via_livewire(): void
    {
        Log::spy();
        $sermon = Sermon::factory()->create(['title' => 'Sermon to delete']);

        Livewire::actingAs($this->admin)
            ->test(ListSermons::class)
            ->call('delete', $sermon);

        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Sermon deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['sermon_id'] === $sermon->id &&
            $context['title'] === 'Sermon to delete'
        );
    }

    #[Test]
    public function legacy_sermon_delete_url_does_not_create_a_second_audit_event(): void
    {
        Log::spy();
        $sermon = Sermon::factory()->create(['title' => 'Sermon Title']);

        $this->actingAs($this->admin)
            ->post(route('sermons.destroy', $sermon->slug))
            ->assertRedirect();

        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
        Log::assertNothingLogged();
    }

    #[Test]
    public function it_logs_page_deletion_via_livewire(): void
    {
        Log::spy();
        $page = Page::factory()->create(['heading' => 'Page to delete']);

        Livewire::actingAs($this->admin)
            ->test(ListPages::class)
            ->call('delete', $page);

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Page deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['page_id'] === $page->id &&
            $context['heading'] === 'Page to delete'
        );
    }

    #[Test]
    public function it_logs_bulk_page_deletion_via_livewire(): void
    {
        Log::spy();
        $pages = Page::factory()->count(3)->create();
        $ids = $pages->pluck('id')->all();
        $descriptivePages = $pages->map(fn (Page $p) => [
            'id' => $p->id,
            'heading' => $p->heading,
            'slug' => $p->slug,
        ])->all();

        Livewire::actingAs($this->admin)
            ->test(ListPages::class)
            ->set('selected', $ids)
            ->call('deleteSelected');

        foreach ($ids as $id) {
            $this->assertDatabaseMissing('pages', ['id' => $id]);
        }

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Pages deleted by admin (batch)' &&
            $context['admin_id'] === $this->admin->id &&
            $context['pages'] === $descriptivePages
        );
    }

    #[Test]
    public function it_logs_meeting_deletion_via_livewire(): void
    {
        Log::spy();
        $meeting = Meeting::factory()->create(['slug' => 'meeting-slug']);

        Livewire::actingAs($this->admin)
            ->test(ListMeetings::class)
            ->call('delete', $meeting);

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Meeting deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['meeting_id'] === $meeting->id &&
            $context['slug'] === 'meeting-slug'
        );
    }

    #[Test]
    public function it_logs_preacher_deletion_via_livewire(): void
    {
        Log::spy();
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);

        Livewire::actingAs($this->admin)
            ->test(ListPreachers::class)
            ->call('delete', $preacher);

        $this->assertDatabaseMissing('preachers', ['id' => $preacher->id]);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Preacher deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['preacher_id'] === $preacher->id &&
            $context['name'] === 'Preacher Name'
        );
    }

    #[Test]
    public function it_logs_preacher_alias_removal(): void
    {
        Log::spy();
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);
        $alias = PreacherAlias::create(['preacher_id' => $preacher->id, 'alias' => 'old alias']);

        Livewire::actingAs($this->admin)
            ->test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeAlias', $alias->id);

        $this->assertDatabaseMissing('preacher_aliases', ['id' => $alias->id]);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Preacher alias removed by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['preacher_id'] === $preacher->id &&
            $context['alias_id'] === $alias->id &&
            $context['alias'] === 'old alias'
        );
    }

    #[Test]
    public function it_logs_speaker_profile_deactivation(): void
    {
        Log::spy();
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);
        $profile = SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeProfile', $profile->id);

        $this->assertDatabaseHas('speaker_profiles', [
            'id' => $profile->id,
            'is_active' => false,
        ]);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Speaker profile deactivated by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['preacher_id'] === $preacher->id &&
            $context['profile_id'] === $profile->id
        );
    }

    #[Test]
    public function it_logs_calendar_event_categorization_via_livewire_list(): void
    {
        Log::spy();
        Meeting::factory()->create(['slug' => 'new-meeting-slug']);
        $event = CalendarEvent::factory()->create(['title' => 'Event Title']);

        Livewire::actingAs($this->admin)
            ->test(ListCalendarEvents::class)
            ->call('categorize', $event->id, 'new-meeting-slug');

        $this->assertEquals('new-meeting-slug', $event->fresh()->meeting_slug);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Calendar event categorized via list view' &&
            $context['admin_id'] === $this->admin->id &&
            $context['event_id'] === $event->id &&
            $context['event_title'] === 'Event Title' &&
            $context['meeting_slug'] === 'new-meeting-slug'
        );
    }

    #[Test]
    public function it_logs_calendar_event_categorization_via_livewire_edit(): void
    {
        Log::spy();
        Meeting::factory()->create(['slug' => 'old-slug']);
        Meeting::factory()->create(['slug' => 'new-slug']);
        $event = CalendarEvent::factory()->create([
            'title' => 'Event Title',
            'meeting_slug' => 'old-slug',
            'status' => CalendarEventStatus::Confirmed,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditCalendarEvent::class, ['calendarEvent' => $event])
            ->set('meetingSlug', 'new-slug')
            ->call('save');

        $this->assertEquals('new-slug', $event->fresh()->meeting_slug);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Calendar event categorization changed via edit form' &&
            $context['admin_id'] === $this->admin->id &&
            $context['event_id'] === $event->id &&
            $context['event_title'] === 'Event Title' &&
            $context['old_meeting_slug'] === 'old-slug' &&
            $context['new_meeting_slug'] === 'new-slug'
        );
    }

    #[Test]
    public function it_logs_calendar_event_categorization_via_service(): void
    {
        Log::spy();

        Meeting::factory()->create(['slug' => 'service-slug']);
        $event = CalendarEvent::factory()->create(['title' => 'Service Event']);

        $this->actingAs($this->admin);
        app(CalendarService::class)->manuallyCategorizeEvent($event->id, 'service-slug');

        $this->assertEquals('service-slug', $event->fresh()->meeting_slug);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Calendar event manually categorized' &&
            $context['admin_id'] === $this->admin->id &&
            $context['event_id'] === $event->id &&
            $context['event_title'] === 'Service Event' &&
            $context['meeting_slug'] === 'service-slug'
        );
    }

    #[Test]
    public function it_logs_inbound_email_rejection_via_livewire(): void
    {
        Log::spy();
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'subject' => 'Suspicious Email',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReviewInbox::class)
            ->call('rejectEmail', $email->id);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Rejected, $email->status);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Inbound email rejected by admin' &&
            $context['inbound_email_id'] === $email->id &&
            $context['subject'] === 'Suspicious Email'
        );
    }

    #[Test]
    public function it_logs_preacher_creation(): void
    {
        Log::spy();

        Livewire::actingAs($this->admin)
            ->test(CreatePreacher::class)
            ->set('name', 'New Preacher')
            ->set('slug', 'new-preacher')
            ->call('save');

        $this->assertDatabaseHas('preachers', ['name' => 'New Preacher']);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'New preacher created by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['name'] === 'New Preacher' &&
            $context['slug'] === 'new-preacher'
        );
    }

    #[Test]
    public function it_logs_preacher_update(): void
    {
        Log::spy();
        $preacher = Preacher::factory()->create(['name' => 'Old Name', 'slug' => 'old-slug']);

        Livewire::actingAs($this->admin)
            ->test(EditPreacher::class, ['preacher' => $preacher])
            ->set('name', 'New Name')
            ->set('slug', 'new-name')
            ->call('save');

        $this->assertSame('New Name', $preacher->fresh()->name);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Preacher updated by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['preacher_id'] === $preacher->id &&
            $context['name'] === 'New Name' &&
            $context['slug'] === 'new-name'
        );
    }

    #[Test]
    public function it_logs_meeting_creation(): void
    {
        Log::spy();

        Livewire::actingAs($this->admin)
            ->test(CreateMeeting::class)
            ->set('form.slug', 'new-meeting')
            ->set('form.day', 'Monday')
            ->set('form.who', 'Everyone')
            ->set('form.type', 'SundayAndBibleStudies')
            ->call('save');

        $this->assertDatabaseHas('meetings', ['slug' => 'new-meeting']);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'New meeting created by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['slug'] === 'new-meeting'
        );
    }

    #[Test]
    public function it_logs_meeting_update(): void
    {
        Log::spy();
        $meeting = Meeting::factory()->create(['slug' => 'old-meeting']);

        Livewire::actingAs($this->admin)
            ->test(EditMeeting::class, ['meeting' => $meeting])
            ->set('form.slug', 'new-meeting')
            ->call('save');

        $this->assertSame('new-meeting', $meeting->fresh()->slug);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Meeting updated by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['meeting_id'] === $meeting->id &&
            $context['slug'] === 'new-meeting'
        );
    }

    #[Test]
    public function it_logs_sermon_update(): void
    {
        Log::spy();
        $sermon = Sermon::factory()->create(['title' => 'Old Title', 'slug' => 'old-title']);

        Livewire::actingAs($this->admin)
            ->test(EditSermon::class, ['sermon' => $sermon])
            ->set('form.title', 'New Title')
            ->set('form.slug', 'new-title')
            ->call('save');

        $this->assertSame('New Title', $sermon->fresh()->title);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Sermon updated by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['sermon_id'] === $sermon->id &&
            $context['title'] === 'New Title' &&
            $context['slug'] === 'new-title'
        );
    }

    #[Test]
    public function it_logs_page_creation(): void
    {
        Log::spy();

        Livewire::actingAs($this->admin)
            ->test(CreatePage::class)
            ->set('form.heading', 'New Page')
            ->set('form.slug', 'new-page')
            ->set('form.description', 'Description')
            ->call('save');

        $this->assertDatabaseHas('pages', ['slug' => 'new-page']);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'New page created by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['heading'] === 'New Page' &&
            $context['slug'] === 'new-page'
        );
    }

    #[Test]
    public function it_logs_page_update(): void
    {
        Log::spy();
        $page = Page::factory()->create(['heading' => 'Old Heading', 'slug' => 'old-slug']);

        Livewire::actingAs($this->admin)
            ->test(EditPage::class, ['page' => $page])
            ->set('form.heading', 'New Heading')
            ->set('form.slug', 'new-heading')
            ->call('save');

        $this->assertSame('New Heading', $page->fresh()->heading);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'Page updated by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['page_id'] === $page->id &&
            $context['heading'] === 'New Heading' &&
            $context['slug'] === 'new-heading'
        );
    }

    #[Test]
    public function it_logs_user_creation(): void
    {
        Log::spy();
        $securePassword = 'Abc.123.def.456.!!!';

        Livewire::actingAs($this->admin)
            ->test(CreateUser::class)
            ->set('name', 'New User')
            ->set('email', 'newuser@example.com')
            ->set('password', $securePassword)
            ->set('passwordConfirmation', $securePassword)
            ->set('sendVerification', false)
            ->call('save');

        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);

        Log::assertLogged('warning', fn (string $message, array $context): bool => $message === 'New user created by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['target_user_email'] === 'newuser@example.com'
        );
    }
}
