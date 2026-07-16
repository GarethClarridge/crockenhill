<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UploadState;
use App\Livewire\Admin\MediaUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadAutoSubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user with a verified email to pass SermonPolicy::create()
        // Use unique email to avoid conflicts with database seeder during parallel tests
        $this->user = User::factory()->create([
            'email' => 'test-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Setup fake storage for testing
        Storage::fake('local');
        Storage::fake('public');
    }

    #[Test]
    public function it_initializes_with_correct_default_state(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->assertSet('status', UploadState::Idle)
            ->assertSet('uploadProgress', 0);
    }

    #[Test]
    public function it_tracks_upload_progress(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('status', UploadState::Uploading)
            ->call('updateUploadProgress', 50)
            ->assertSet('uploadProgress', 50);
    }

    #[Test]
    public function it_ignores_progress_updates_when_upload_is_not_active(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('status', UploadState::Processing)
            ->call('updateUploadProgress', 50)
            ->assertSet('uploadProgress', 0);
    }

    #[Test]
    public function it_cancels_upload_and_resets_state(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('status', UploadState::Uploading)
            ->set('uploadProgress', 50)
            ->call('cancelUpload')
            ->assertSet('status', UploadState::Idle)
            ->assertSet('uploadProgress', 0)
            ->assertSet('mediaFile', null)
            ->assertSet('statusMessageOverride', null);
    }

    #[Test]
    public function it_does_nothing_when_cancelling_non_uploading_state(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('status', UploadState::Idle)
            ->call('cancelUpload')
            ->assertSet('status', UploadState::Idle);
    }

    #[Test]
    public function it_prevents_upload_complete_when_terminal(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('status', UploadState::Failed)
            ->set('mediaFile', null) // No file, so uploadComplete will exit early
            ->call('uploadComplete')
            ->assertSet('status', UploadState::Failed); // Should not start processing
    }

    #[Test]
    public function it_handles_upload_complete_with_missing_file(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', null)
            ->call('uploadComplete')
            ->assertSet('status', UploadState::Failed)
            ->assertSee('File upload completed but file is missing');
    }

    #[Test]
    public function it_resets_upload_state_on_retry(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('status', UploadState::Uploading)
            ->set('uploadProgress', 75)
            ->set('tempFilePath', 'temp/livewire-upload/test.mp3')
            ->call('retryUpload')
            ->assertSet('status', UploadState::Idle)
            ->assertSet('uploadProgress', 0)
            ->assertSet('tempFilePath', null)
            ->assertSet('mediaFile', null)
            ->assertSet('statusMessageOverride', null);
    }

    #[Test]
    public function it_maintains_separate_upload_and_processing_progress(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('uploadProgress', 75)
            ->set('progressPercentage', 25)
            ->assertSet('uploadProgress', 75)
            ->assertSet('progressPercentage', 25);
    }

    #[Test]
    public function it_clears_media_file_when_media_type_changes(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaType', 'video')
            ->assertSet('mediaFile', null);
    }

    #[Test]
    public function it_updates_progress_with_realistic_values(): void
    {
        $this->actingAs($this->user);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'livestream')
            ->set('status', UploadState::Uploading)
            ->call('updateUploadProgress', 75)
            ->assertSet('uploadProgress', 75);
    }
}
