<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Livewire\Admin\Components\MediaUploadField;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->regularUser = User::factory()->create(['is_admin' => false]);
    }

    #[Test]
    public function admin_can_render_media_upload_field(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUploadField::class)->assertStatus(200);
    }

    #[Test]
    public function non_admin_cannot_call_upload_action(): void
    {
        $this->actingAs($this->regularUser);

        Livewire::test(MediaUploadField::class)
            ->call('upload')
            ->assertForbidden();
    }

    #[Test]
    public function non_admin_cannot_call_remove_action(): void
    {
        $this->actingAs($this->regularUser);

        Livewire::test(MediaUploadField::class)
            ->call('remove', 1)
            ->assertForbidden();
    }

    #[Test]
    public function upload_reports_error_when_no_file_is_selected(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUploadField::class)
            ->call('upload')
            ->assertDispatched('notify', type: 'error', message: 'No file selected');
    }

    #[Test]
    public function remove_reports_error_when_media_not_found(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        Livewire::test(MediaUploadField::class, ['model' => $page])
            ->call('remove', 99999)
            ->assertDispatched('notify', type: 'error', message: 'Media not found');
    }
}
