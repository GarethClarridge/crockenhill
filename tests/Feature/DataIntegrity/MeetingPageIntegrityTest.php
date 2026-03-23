<?php

namespace Tests\Feature\DataIntegrity;

use App\Livewire\Admin\Meetings\EditMeeting;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingPageIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_unique_page_id_at_database_level()
    {
        $page = Page::factory()->create();

        // Create first meeting linked to the page
        Meeting::factory()->create(['page_id' => $page->id]);

        // Attempt to create second meeting linked to the same page
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Duplicate entry');

        Meeting::factory()->create(['page_id' => $page->id]);
    }

    #[Test]
    public function it_validates_unique_page_id_in_livewire_component()
    {
        $page = Page::factory()->create();
        $meeting1 = Meeting::factory()->create(['page_id' => $page->id]);
        $meeting2 = Meeting::factory()->create(['page_id' => null]);

        $this->actingAs($this->adminUser);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting2])
            ->set('form.pageId', $page->id)
            ->call('save')
            ->assertHasErrors(['form.pageId' => 'unique']);
    }

    #[Test]
    public function it_allows_saving_current_page_id_for_existing_meeting()
    {
        $page = Page::factory()->create();
        $meeting = Meeting::factory()->create(['page_id' => $page->id]);

        $this->actingAs($this->adminUser);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->set('form.pageId', $page->id)
            ->call('save')
            ->assertHasNoErrors(['form.pageId']);

        $this->assertEquals($page->id, $meeting->fresh()->page_id);
    }
}
