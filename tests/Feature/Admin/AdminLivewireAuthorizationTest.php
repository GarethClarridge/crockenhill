<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Livewire\Admin\Meetings\EditMeeting;
use App\Livewire\Admin\Meetings\ListMeetings;
use App\Livewire\Admin\Pages\CreatePage;
use App\Livewire\Admin\Pages\EditPage;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\CreatePreacher;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Users\CreateUser;
use App\Livewire\Admin\Users\EditUser;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['is_admin' => true]);
        $this->regularUser = User::factory()->create(['is_admin' => false]);
    }

    /** @test */
    public function non_admin_cannot_access_page_admin_components(): void
    {
        $page = Page::factory()->create();

        $this->actingAs($this->regularUser);

        Livewire::test(ListPages::class)->assertForbidden();
        Livewire::test(CreatePage::class)->assertForbidden();
        Livewire::test(EditPage::class, ['page' => $page])->assertForbidden();
    }

    /** @test */
    public function admin_can_access_page_admin_components(): void
    {
        $page = Page::factory()->create();

        $this->actingAs($this->adminUser);

        Livewire::test(ListPages::class)->assertStatus(200);
        Livewire::test(CreatePage::class)->assertStatus(200);
        Livewire::test(EditPage::class, ['page' => $page])->assertStatus(200);
    }

    /** @test */
    public function non_admin_cannot_access_meeting_admin_components(): void
    {
        $meeting = Meeting::factory()->create();

        $this->actingAs($this->regularUser);

        Livewire::test(ListMeetings::class)->assertForbidden();
        Livewire::test(CreateMeeting::class)->assertForbidden();
        Livewire::test(EditMeeting::class, ['meeting' => $meeting])->assertForbidden();
    }

    /** @test */
    public function non_admin_cannot_access_sermon_edit_component(): void
    {
        $sermon = Sermon::factory()->create();

        $this->actingAs($this->regularUser);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])->assertForbidden();
    }

    /** @test */
    public function non_admin_cannot_access_preacher_admin_components(): void
    {
        $preacher = Preacher::factory()->create();

        $this->actingAs($this->regularUser);

        Livewire::test(CreatePreacher::class)->assertForbidden();
        Livewire::test(EditPreacher::class, ['preacher' => $preacher])->assertForbidden();
    }

    /** @test */
    public function non_admin_cannot_access_user_admin_components(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->regularUser);

        Livewire::test(CreateUser::class)->assertForbidden();
        Livewire::test(EditUser::class, ['user' => $user])->assertForbidden();
    }

    /** @test */
    public function admin_can_access_meeting_admin_components(): void
    {
        $meeting = Meeting::factory()->create();

        $this->actingAs($this->adminUser);

        Livewire::test(ListMeetings::class)->assertStatus(200);
        Livewire::test(CreateMeeting::class)->assertStatus(200);
        Livewire::test(EditMeeting::class, ['meeting' => $meeting])->assertStatus(200);
    }

    /** @test */
    public function admin_can_access_sermon_admin_components(): void
    {
        $sermon = Sermon::factory()->create();

        $this->actingAs($this->adminUser);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])->assertStatus(200);
    }

    /** @test */
    public function admin_can_access_preacher_admin_components(): void
    {
        $preacher = Preacher::factory()->create();

        $this->actingAs($this->adminUser);

        Livewire::test(CreatePreacher::class)->assertStatus(200);
        Livewire::test(EditPreacher::class, ['preacher' => $preacher])->assertStatus(200);
    }

    /** @test */
    public function admin_can_access_user_admin_components(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->adminUser);

        Livewire::test(CreateUser::class)->assertStatus(200);
        Livewire::test(EditUser::class, ['user' => $user])->assertStatus(200);
    }
}
