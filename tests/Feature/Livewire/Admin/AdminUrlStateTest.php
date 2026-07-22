<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\InboundEmailStatus;
use App\Enums\MeetingType;
use App\Enums\PageArea;
use App\Enums\SermonService;
use App\Livewire\Admin\CalendarEvents\ListCalendarEvents;
use App\Livewire\Admin\ChurchServices\AddToService;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ListSongs;
use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Livewire\Admin\Users\ListUsers;
use App\Models\CalendarEvent;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\InboundEmail;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class AdminUrlStateTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function add_to_service_hydrates_the_intent_from_the_url(): void
    {
        $this->actingAs($this->admin);

        Livewire::withQueryParams(['intent' => 'recording'])
            ->test(AddToService::class)
            ->assertSet('intent', 'recording')
            ->assertSee('Upload a recording');
    }

    #[Test]
    public function sermon_list_hydrates_url_filters_and_preserves_filtered_results(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create(['name' => 'John Shepherd']);

        Sermon::factory()->withDate(now()->subMonths(18))->create([
            'title' => 'Grace for Today',
            'service' => SermonService::Morning->value,
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'series' => 'Grace Series',
            'needs_preacher_review' => true,
            'video_file_path' => 'sermons/grace-for-today.mp4',
        ]);

        Sermon::factory()->withDate(now()->subWeeks(2))->create([
            'title' => 'Hope for Tomorrow',
            'service' => SermonService::Evening->value,
            'series' => 'Hope Series',
            'needs_preacher_review' => false,
            'video_file_path' => null,
        ]);

        Livewire::withQueryParams([
            'search' => 'Grace',
            'serviceFilter' => SermonService::Morning->value,
            'preacherFilter' => (string) $preacher->id,
            'seriesFilter' => 'Grace Series',
            'hasVideoFilter' => '1',
            'needsReviewFilter' => '1',
            'last12Months' => '0',
        ])->test(ListSermons::class)
            ->assertSet('search', 'Grace')
            ->assertSet('serviceFilter', SermonService::Morning->value)
            ->assertSet('preacherFilter', $preacher->id)
            ->assertSet('seriesFilter', 'Grace Series')
            ->assertSet('hasVideoFilter', true)
            ->assertSet('needsReviewFilter', true)
            ->assertSet('last12Months', false)
            ->assertSee('Grace for Today')
            ->assertDontSee('Hope for Tomorrow');
    }

    #[Test]
    public function page_list_hydrates_url_filters_and_preserves_filtered_results(): void
    {
        $this->actingAs($this->admin);

        Page::factory()->create([
            'heading' => 'About Community Life',
            'description' => 'Community information',
            'area' => PageArea::Community->value,
            'navigation' => false,
        ]);

        Page::factory()->create([
            'heading' => 'About Church Life',
            'description' => 'Church information',
            'area' => PageArea::Church->value,
            'navigation' => false,
        ]);

        Page::factory()->create([
            'heading' => 'About Community Events',
            'description' => 'Community events',
            'area' => PageArea::Community->value,
            'navigation' => true,
        ]);

        Livewire::withQueryParams([
            'search' => 'About',
            'areaFilter' => PageArea::Community->value,
            'navigationFilter' => '0',
        ])->test(ListPages::class)
            ->assertSet('search', 'About')
            ->assertSet('areaFilter', PageArea::Community->value)
            ->assertSet('navigationFilter', false)
            ->assertSee('About Community Life')
            ->assertDontSee('About Church Life')
            ->assertDontSee('About Community Events');
    }

    #[Test]
    public function meeting_list_hydrates_url_filters_and_preserves_filtered_results(): void
    {
        $this->actingAs($this->admin);

        $matchingPage = Page::factory()->create(['heading' => 'Prayer Gathering']);
        $excludedPage = Page::factory()->create(['heading' => 'Prayer Gathering']);

        Meeting::factory()->create([
            'page_id' => $matchingPage->id,
            'type' => MeetingType::Adults->value,
            'who' => 'Prayer Team',
        ]);

        Meeting::factory()->create([
            'page_id' => $excludedPage->id,
            'type' => MeetingType::Occasional->value,
            'who' => 'Prayer Guests',
        ]);

        Livewire::withQueryParams([
            'search' => 'Prayer',
            'typeFilter' => MeetingType::Adults->value,
        ])->test(ListMeetings::class)
            ->assertSet('search', 'Prayer')
            ->assertSet('typeFilter', MeetingType::Adults->value)
            ->assertSee('Prayer Gathering')
            ->assertDontSee('Prayer Guests');
    }

    #[Test]
    public function user_list_hydrates_url_filters_and_sorting_from_the_url(): void
    {
        $this->actingAs($this->admin);

        User::factory()->create([
            'name' => 'Zoe Member',
            'email' => 'zoe@example.com',
            'email_verified_at' => null,
            'is_admin' => false,
        ]);

        User::factory()->create([
            'name' => 'Aaron Member',
            'email' => 'aaron@example.com',
            'email_verified_at' => null,
            'is_admin' => false,
        ]);

        User::factory()->create([
            'name' => 'Verified Admin',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);

        Livewire::withQueryParams([
            'search' => 'example.com',
            'verifiedFilter' => '0',
            'adminFilter' => '0',
            'sortBy' => 'name',
            'sortDirection' => 'asc',
        ])->test(ListUsers::class)
            ->assertSet('search', 'example.com')
            ->assertSet('verifiedFilter', false)
            ->assertSet('adminFilter', false)
            ->assertSet('sortBy', 'name')
            ->assertSet('sortDirection', 'asc')
            ->assertSeeInOrder(['Aaron Member', 'Zoe Member'])
            ->assertDontSee('Verified Admin');
    }

    #[Test]
    public function preacher_list_hydrates_url_filters_and_preserves_filtered_results(): void
    {
        $this->actingAs($this->admin);

        Preacher::factory()->create([
            'name' => 'John Inactive',
            'is_active' => false,
        ]);

        Preacher::factory()->create([
            'name' => 'John Active',
            'is_active' => true,
        ]);

        Livewire::withQueryParams([
            'search' => 'John',
            'activeFilter' => '0',
        ])->test(ListPreachers::class)
            ->assertSet('search', 'John')
            ->assertSet('activeFilter', false)
            ->assertSee('John Inactive')
            ->assertDontSee('John Active');
    }

    #[Test]
    public function calendar_event_list_hydrates_url_filters_and_preserves_filtered_results(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['heading' => 'Prayer Meeting']);
        $meeting = Meeting::factory()->create([
            'slug' => 'prayer-meeting',
            'page_id' => $page->id,
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Prayer Gathering',
            'description' => 'Evening prayer',
            'start_datetime' => now()->subDays(3)->startOfHour(),
            'end_datetime' => now()->subDays(3)->startOfHour()->addHour(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => null,
            'title' => 'Prayer Gathering',
            'description' => 'Uncategorized prayer',
            'start_datetime' => now()->subDays(2)->startOfHour(),
            'end_datetime' => now()->subDays(2)->startOfHour()->addHour(),
        ]);

        Livewire::withQueryParams([
            'search' => 'Prayer',
            'meetingFilter' => $meeting->slug,
            'upcomingOnly' => '0',
        ])->test(ListCalendarEvents::class)
            ->assertSet('search', 'Prayer')
            ->assertSet('meetingFilter', $meeting->slug)
            ->assertSet('upcomingOnly', false)
            ->assertSet('uncategorizedOnly', false)
            ->assertSee('Prayer Gathering')
            ->assertDontSee('Uncategorized prayer');
    }

    #[Test]
    public function church_service_list_hydrates_url_filters_and_preserves_filtered_results(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create([
            'date' => '2026-01-19',
            'service' => SermonService::Evening->value,
            'original_filename' => '2026-01-19 PM.osz',
            'needs_review' => true,
        ]);

        ChurchService::factory()->create([
            'date' => '2026-01-12',
            'service' => SermonService::Morning->value,
            'original_filename' => '2026-01-12 AM.osz',
            'needs_review' => false,
        ]);

        Livewire::withQueryParams([
            'search' => '2026-01-19',
            'serviceFilter' => SermonService::Evening->value,
            'needsReviewFilter' => '1',
        ])->test(ListChurchServices::class)
            ->assertSet('search', '2026-01-19')
            ->assertSet('serviceFilter', SermonService::Evening->value)
            ->assertSet('needsReviewFilter', true)
            ->assertSee('2026-01-19 PM.osz')
            ->assertDontSee('2026-01-12 AM.osz');
    }

    #[Test]
    public function song_list_hydrates_url_filters_and_preserves_usage_scoping(): void
    {
        $this->actingAs($this->admin);

        $eveningService = ChurchService::factory()->create([
            'date' => '2026-02-08',
            'service' => SermonService::Evening,
        ]);

        $morningService = ChurchService::factory()->create([
            'date' => '2026-03-08',
            'service' => SermonService::Morning,
        ]);

        $songA = Song::factory()->create([
            'title' => 'Song A',
            'canonical_key' => 'song a@',
        ]);
        $songB = Song::factory()->create([
            'title' => 'Song B',
            'canonical_key' => 'song b@',
        ]);

        $authorOne = SongAuthor::factory()->create(['display_name' => 'Writer One']);
        $authorTwo = SongAuthor::factory()->create(['display_name' => 'Writer Two']);
        $songA->authors()->attach($authorOne->id, ['author_type' => 'words']);
        $songB->authors()->attach($authorTwo->id, ['author_type' => 'words']);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $eveningService->id,
            'type' => 'songs',
            'title' => 'Song B',
            'song_id' => $songB->id,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $morningService->id,
            'type' => 'songs',
            'title' => 'Song A',
            'song_id' => $songA->id,
        ]);

        Livewire::withQueryParams([
            'search' => 'Writer Two',
            'serviceFilter' => SermonService::Evening->value,
            'dateFrom' => '2026-02-01',
            'dateTo' => '2026-02-28',
        ])->test(ListSongs::class)
            ->assertSet('search', 'Writer Two')
            ->assertSet('serviceFilter', SermonService::Evening->value)
            ->assertSet('dateFrom', '2026-02-01')
            ->assertSet('dateTo', '2026-02-28')
            ->assertSee('Song B')
            ->assertDontSee('Song A')
            ->assertViewHas('songs', function ($songs) use ($songB): bool {
                $collection = $songs->getCollection()->keyBy('id');

                return (int) $collection[$songB->id]->usage_count === 1
                    && (int) $collection[$songB->id]->services_count === 1;
            });
    }

    #[Test]
    public function manage_church_service_prefills_from_inbound_email_id_in_the_url(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-06',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['type' => 'songs', 'title' => 'Opening Hymn', 'metadata' => null],
                ],
            ),
        ]);

        Livewire::withQueryParams([
            'inboundEmailId' => (string) $email->id,
        ])->test(ManageChurchService::class)
            ->assertSet('inboundEmailId', $email->id)
            ->assertSet('form.date', '2026-07-06')
            ->assertSet('form.service', SermonService::Morning->value)
            ->assertSet('form.items.0.title', 'Welcome')
            ->assertSet('form.items.1.title', 'Opening Hymn');
    }

    #[Test]
    public function church_service_show_hydrates_edit_mode_from_the_url(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create();
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'title' => 'Welcome',
        ]);

        Livewire::withQueryParams(['edit' => '1'])
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSet('edit', true)
            ->assertSet('form.items.0.title', 'Welcome')
            ->assertSee('Save order of service');
    }
}
