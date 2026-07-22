<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminLivewireSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var User $admin */
        $admin = User::factory()->crockenhillAdmin()->create();
        $this->admin = $admin;

        // A regular non-admin user
        /** @var User $user */
        $user = User::factory()->create(['is_admin' => false]);
        $this->user = $user;
    }

    #[Test]
    public function non_admins_cannot_delete_sermons_via_livewire(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        $this->mountAsAdminThenActAsUser(ListSermons::class)
            ->call('delete', $sermon)
            ->assertForbidden();

        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
    }

    #[Test]
    public function non_admins_cannot_save_sermons_via_livewire(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create(['title' => 'Original Title']);

        $this->mountAsAdminThenActAsUser(EditSermon::class, ['sermon' => $sermon])
            ->set('form.title', 'Hacked Title')
            ->call('save')
            ->assertForbidden();

        /** @var Sermon $fresh */
        $fresh = $sermon->fresh();
        $this->assertEquals('Original Title', $fresh->title);
    }

    #[Test]
    public function non_admins_cannot_delete_meetings_via_livewire(): void
    {
        /** @var Meeting $meeting */
        $meeting = Meeting::factory()->create();

        $this->mountAsAdminThenActAsUser(ListMeetings::class)
            ->call('delete', $meeting)
            ->assertForbidden();

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    #[Test]
    public function non_admins_cannot_delete_preachers_via_livewire(): void
    {
        /** @var Preacher $preacher */
        $preacher = Preacher::factory()->create();

        $this->mountAsAdminThenActAsUser(ListPreachers::class)
            ->call('delete', $preacher)
            ->assertForbidden();

        $this->assertDatabaseHas('preachers', ['id' => $preacher->id]);
    }

    #[Test]
    public function non_admins_cannot_delete_pages_via_livewire(): void
    {
        /** @var Page $page */
        $page = Page::factory()->create();

        $this->mountAsAdminThenActAsUser(ListPages::class)
            ->call('delete', $page)
            ->assertForbidden();

        $this->assertDatabaseHas('pages', ['id' => $page->id]);
    }

    #[Test]
    public function non_admins_cannot_approve_inbound_emails_via_livewire(): void
    {
        /** @var InboundEmail $email */
        $email = InboundEmail::factory()->create();

        $this->mountAsAdminThenActAsUser(ListChurchServices::class)
            ->call('approveEmail', $email->id)
            ->assertForbidden();
    }

    #[Test]
    public function non_admins_cannot_resolve_structure_merges_via_livewire(): void
    {
        /** @var ChurchService $service */
        $service = ChurchService::factory()->create();

        $this->mountAsAdminThenActAsUser(ShowChurchService::class, ['churchService' => $service])
            ->call('acceptIncomingMerge')
            ->assertForbidden();
    }

    /**
     * Mount the component as admin (so it passes mount() auth), then switch
     * to a non-admin user so subsequent action calls test defense-in-depth.
     *
     * @param  array<string, mixed>  $params
     */
    private function mountAsAdminThenActAsUser(string $component, array $params = []): Testable
    {
        $testable = Livewire::actingAs($this->admin)->test($component, $params);

        $this->actingAs($this->user);

        return $testable;
    }
}
