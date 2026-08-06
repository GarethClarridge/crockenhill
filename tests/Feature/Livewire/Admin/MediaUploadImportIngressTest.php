<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\UploadState;
use App\Livewire\Admin\MediaUpload;
use App\Models\User;
use App\Services\Import\ImportIngressGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §15.2's first ingress requirement, admin half: upload routes refuse new work
 * "with a clear retryable response and corresponding disabled admin state".
 *
 * The refusal is the load-bearing part. `RefuseBlockedImportIngress` guards the
 * API upload route, but this screen never travels it — it calls
 * `UnifiedMediaProcessor` directly — so a window that only guarded the API would
 * still admit work through the admin uploader. The disabled state stops an
 * operator reaching the refusal by surprise; the guard is what makes the window
 * true.
 */
class MediaUploadImportIngressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->crockenhillAdmin()->create();
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('livewire-tmp');
        $this->actingAs($this->admin);
    }

    #[Test]
    public function the_uploader_explains_itself_instead_of_offering_a_form_during_an_import_window(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        Livewire::test(MediaUpload::class)
            ->assertSee('Uploads are paused for a scheduled archive import')
            ->assertSee('historic-import-1')
            ->assertDontSee('Choose file');
    }

    /**
     * Losslessness is worth saying on the screen too: an operator who sees
     * uploads refused should not go hunting for the order-of-service email they
     * think has been dropped.
     */
    #[Test]
    public function the_blocked_state_says_inbound_email_is_still_being_kept(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        Livewire::test(MediaUpload::class)
            ->assertSee('still being stored and will process automatically');
    }

    #[Test]
    public function the_form_is_offered_normally_when_no_window_is_open(): void
    {
        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->assertSee('Choose file')
            ->assertDontSee('Uploads are paused for a scheduled archive import');
    }

    /**
     * A page opened before the window began still holds a live upload path. The
     * component, not the view, has to be the thing that refuses.
     */
    #[Test]
    public function a_submission_from_a_page_opened_before_the_window_is_refused(): void
    {
        $component = Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg'));

        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        $component->call('uploadComplete')
            ->assertSet('status', UploadState::Idle)
            ->assertSet('importIngressRefused', true)
            ->assertSee('Your recording was not accepted');

        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    /**
     * `startProcessing` is a public Livewire action of its own, so a stale page
     * can call it without going through `uploadComplete`.
     */
    #[Test]
    public function starting_processing_directly_is_refused_during_a_window(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('tempFilePath', 'temp/livewire-upload/sermon.mp3')
            ->call('startProcessing')
            ->assertSet('importIngressRefused', true);

        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    /**
     * Arriving during a window and being turned away mid-upload are different
     * events; only the second one cost the operator a submission.
     */
    #[Test]
    public function merely_arriving_during_a_window_is_not_reported_as_a_refused_upload(): void
    {
        app(ImportIngressGate::class)->block('historic-import-1', 'Historic archive import window');

        Livewire::test(MediaUpload::class)
            ->assertSet('importIngressRefused', false)
            ->assertDontSee('Your recording was not accepted');
    }

    #[Test]
    public function releasing_the_window_restores_the_form(): void
    {
        $gate = app(ImportIngressGate::class);
        $gate->block('historic-import-1', 'Historic archive import window');
        $gate->release('historic-import-1');

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->assertSee('Choose file')
            ->assertDontSee('Uploads are paused for a scheduled archive import');
    }
}
