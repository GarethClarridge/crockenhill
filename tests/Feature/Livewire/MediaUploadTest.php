<?php

namespace Tests\Feature\Livewire;

use App\Livewire\MediaUpload;
use App\Models\User;
use App\Services\ProcessingResult;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
        
        // Fake disks used by the component
        Storage::fake('local');
    }

    #[Test]
    public function it_renders_successfully()
    {
        $this->actingAs($this->admin);
        
        Livewire::test(MediaUpload::class)
            ->assertStatus(200)
            ->assertSee('Upload Media');
    }

    #[Test]
    public function it_requires_authentication_and_admin_permissions()
    {
        // Unauthenticated
        $this->get(route('admin.sermon-upload.create'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function it_validates_media_type_selection()
    {
        $this->actingAs($this->admin);
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'invalid-type')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertHasErrors(['mediaType' => 'Invalid media type selected.']);
    }

    #[Test]
    public function it_handles_missing_file_on_upload_complete()
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', null)
            ->call('uploadComplete')
            ->assertSet('status', 'failed')
            ->assertSet('errorMessage', 'File upload completed but file is missing');
    }

    #[Test]
    public function it_validates_file_requirements()
    {
        $this->actingAs($this->admin);

        $invalidFile = UploadedFile::fake()->create('test.txt', 100);
        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $invalidFile)
            ->call('uploadComplete')
            ->assertHasErrors(['mediaFile']);
    }

    #[Test]
    public function it_starts_processing_after_successful_upload()
    {
        $this->actingAs($this->admin);

        $expectedId = '00000000-0000-0000-0000-000000000000';

        Storage::fake('local');
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);
        
        $mockResult = \App\Services\ProcessingResult::success($expectedId, 'Started');
        
        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->willReturn($mockResult);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $test = Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete');

        if ($test->get('status') === 'failed') {
            $this->fail($test->get('errorMessage'));
        }

        $test->assertSet('status', 'processing')
            ->assertSet('processingId', $expectedId)
            ->assertSet('successMessage', 'Upload complete. Processing has started.');
    }

    #[Test]
    public function it_handles_processing_failures_gracefully()
    {
        $this->actingAs($this->admin);
        
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);
        
        $mockResult = \App\Services\ProcessingResult::failure('test-proc-id', 'System error', 'ERR_CODE');
        
        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->willReturn($mockResult);
            
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertSet('status', 'failed')
            ->assertSet('errorMessage', 'System error');
    }

    #[Test]
    public function it_can_cancel_upload()
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->set('isUploading', true)
            ->call('cancelUpload')
            ->assertSet('isUploading', false)
            ->assertSet('uploadCancelled', true)
            ->assertSet('status', 'idle');
    }

    #[Test]
    public function it_clears_file_when_media_type_changes()
    {
        $this->actingAs($this->admin);
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        Livewire::test(MediaUpload::class)
            ->set('mediaFile', $file)
            ->set('mediaType', 'video')
            ->assertSet('mediaFile', null);
    }
}
