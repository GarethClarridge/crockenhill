<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Livewire\Admin\CalendarEvents\ListCalendarEvents;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Livewire\Admin\Users\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClearFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_can_reset_sermon_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListSermons::class)
            ->set('search', 'Test Search')
            ->set('serviceFilter', 'morning')
            ->set('last12Months', false)
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('serviceFilter', null)
            ->assertSet('last12Months', true)
            ->assertSet('hasFilters', false)
            ->assertDispatched('notify', type: 'success', message: 'Filters cleared');
    }

    public function test_can_reset_page_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListPages::class)
            ->set('search', 'Page Search')
            ->set('areaFilter', 'christ')
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('areaFilter', null)
            ->assertSet('hasFilters', false)
            ->assertDispatched('notify', type: 'success', message: 'Filters cleared');
    }

    public function test_can_reset_meeting_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListMeetings::class)
            ->set('search', 'Meeting Search')
            ->set('typeFilter', 'regular')
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('typeFilter', null)
            ->assertSet('hasFilters', false)
            ->assertDispatched('notify', type: 'success', message: 'Filters cleared');
    }

    public function test_can_reset_user_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListUsers::class)
            ->set('search', 'User Search')
            ->set('verifiedFilter', true)
            ->set('adminFilter', false)
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('verifiedFilter', null)
            ->assertSet('adminFilter', null)
            ->assertSet('hasFilters', false)
            ->assertDispatched('notify', type: 'success', message: 'Filters cleared');
    }

    public function test_can_reset_preacher_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListPreachers::class)
            ->set('search', 'Preacher Search')
            ->set('activeFilter', true)
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('activeFilter', null)
            ->assertSet('hasFilters', false)
            ->assertDispatched('notify', type: 'success', message: 'Filters cleared');
    }

    public function test_can_reset_calendar_event_filters(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListCalendarEvents::class)
            ->set('search', 'Event Search')
            ->set('meetingFilter', 'sunday-morning')
            ->set('uncategorizedOnly', true)
            ->set('upcomingOnly', false)
            ->assertSet('hasFilters', true)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('meetingFilter', null)
            ->assertSet('uncategorizedOnly', false)
            ->assertSet('upcomingOnly', true)
            ->assertSet('hasFilters', false)
            ->assertDispatched('notify', type: 'success', message: 'Filters cleared');
    }
}
