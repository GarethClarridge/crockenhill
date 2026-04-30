<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Livewire\Admin\Meetings\EditMeeting;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_enforces_unique_page_id_at_database_level()
    {
        $page = Page::factory()->create();

        // First meeting with the page
        Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'meeting-1',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_page_id_unique');

        // Second meeting with the same page should fail
        Meeting::factory()->create([
            'page_id' => $page->id,
            'slug' => 'meeting-2',
        ]);
    }

    #[Test]
    public function it_allows_multiple_meetings_with_null_page_id()
    {
        Meeting::factory()->create([
            'page_id' => null,
            'slug' => 'meeting-null-1',
        ]);

        Meeting::factory()->create([
            'page_id' => null,
            'slug' => 'meeting-null-2',
        ]);

        $this->assertEquals(2, Meeting::whereNull('page_id')->count());
    }

    #[Test]
    public function it_validates_unique_page_id_in_livewire_create()
    {
        $page = Page::factory()->create();
        Meeting::factory()->create(['page_id' => $page->id]);

        Livewire::actingAs($this->admin)
            ->test(CreateMeeting::class)
            ->set('form.slug', 'new-meeting')
            ->set('form.type', 'Adults')
            ->set('form.day', 'Monday')
            ->set('form.who', 'Anyone')
            ->set('form.pageId', $page->id)
            ->call('save')
            ->assertHasErrors(['form.pageId' => 'unique']);
    }

    #[Test]
    public function it_validates_unique_page_id_in_livewire_edit()
    {
        $page1 = Page::factory()->create();
        $page2 = Page::factory()->create();
        $meeting1 = Meeting::factory()->create(['page_id' => $page1->id]);
        $meeting2 = Meeting::factory()->create(['page_id' => $page2->id]);

        Livewire::actingAs($this->admin)
            ->test(EditMeeting::class, ['meeting' => $meeting1])
            ->set('form.pageId', $page2->id)
            ->call('save')
            ->assertHasErrors(['form.pageId' => 'unique']);

        Livewire::actingAs($this->admin)
            ->test(EditMeeting::class, ['meeting' => $meeting1])
            ->set('form.pageId', $page1->id)
            ->call('save')
            ->assertHasNoErrors(['form.pageId']);
    }
}
