<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Jobs\ProcessInboundOosEmail;
use App\Livewire\Admin\ChurchServices\SubmitEmailText;
use App\Livewire\Admin\ChurchServices\UploadChurchService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['service-tracking.enabled' => true]);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->regularUser = User::factory()->create(['is_admin' => false]);
    }

    // -------------------------------------------------------------------------
    // SubmitEmailText
    // -------------------------------------------------------------------------

    #[Test]
    public function non_admin_cannot_mount_submit_email_text(): void
    {
        $this->actingAs($this->regularUser)
            ->get(route('admin.services.submit-email'))
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_mount_submit_email_text(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SubmitEmailText::class)->assertStatus(200);
    }

    #[Test]
    public function admin_submit_action_dispatches_job_and_sets_submitted_flag(): void
    {
        Queue::fake();

        $this->actingAs($this->admin);

        Livewire::test(SubmitEmailText::class)
            ->set('bodyPlain', str_repeat('x', 20))
            ->call('submit')
            ->assertSet('submitted', true);

        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
    }

    // -------------------------------------------------------------------------
    // UploadChurchService
    // -------------------------------------------------------------------------

    #[Test]
    public function non_admin_cannot_mount_upload_church_service(): void
    {
        $this->actingAs($this->regularUser)
            ->get(route('admin.services.upload'))
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_mount_upload_church_service(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UploadChurchService::class)->assertStatus(200);
    }
}
