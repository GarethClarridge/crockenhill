<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\MediaProcessingLog;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class UploadRecordingTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_choosing_a_media_type_reveals_the_file_input(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/admin/services/upload-recording')
                ->assertSee('Upload recording')
                ->assertDontSeeIn('main', 'Drop your file here')
                ->select('#mediaType', 'audio')
                ->waitForText('Drop your file here')
                ->assertPresent('input#media-file')
                ->assertSee('Processing starts automatically after the upload finishes.');
        });
    }

    public function test_legacy_sermon_upload_url_redirects_to_the_new_page(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/admin/sermon-upload')
                ->waitForLocation('/admin/services/upload-recording')
                ->assertSee('Upload recording');
        });
    }

    public function test_cancelling_a_throttled_upload_stops_transfer_and_never_starts_processing(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $uploadPath = tempnam(sys_get_temp_dir(), 'dusk-upload-');
        $this->assertIsString($uploadPath);
        file_put_contents($uploadPath, str_repeat('audio-data', 1024 * 1024));

        try {
            $this->browse(function (Browser $browser) use ($admin, $uploadPath): void {
                $devTools = new ChromeDevToolsDriver($browser->driver);

                $browser->loginAs($admin)
                    ->visit('/admin/services/upload-recording')
                    ->select('#mediaType', 'audio')
                    ->waitFor('input#media-file')
                    ->script(<<<'JS'
                        window.uploadProgressEvents = 0;
                        window.uploadStartDetails = [];
                        window.addEventListener('livewire-upload-start', (event) => window.uploadStartDetails.push(event.detail));
                        window.addEventListener('livewire-upload-progress', () => window.uploadProgressEvents++);
                    JS);

                $devTools->execute('Network.enable');
                $devTools->execute('Network.emulateNetworkConditions', [
                    'offline' => false,
                    'latency' => 300,
                    'downloadThroughput' => 64 * 1024,
                    'uploadThroughput' => 32 * 1024,
                    'connectionType' => 'cellular3g',
                ]);

                try {
                    $browser->attach('#media-file', $uploadPath)
                        ->waitUsing(10, 100, fn (): bool => count($browser->script('return window.uploadStartDetails;')[0]) > 0);

                    $browser->waitFor('@cancel-upload', 10)
                        ->waitUsing(10, 100, fn (): bool => (int) $browser->script('return window.uploadProgressEvents;')[0] > 0)
                        ->click('@cancel-upload')
                        ->waitFor('input#media-file', 10);
                } finally {
                    $devTools->execute('Network.emulateNetworkConditions', [
                        'offline' => false,
                        'latency' => 0,
                        'downloadThroughput' => -1,
                        'uploadThroughput' => -1,
                        'connectionType' => 'none',
                    ]);
                }

                $settledProgressEvents = (int) $browser->script('return window.uploadProgressEvents;')[0];

                $browser->pause(2000)
                    ->assertPresent('input#media-file');

                $this->assertSame(
                    $settledProgressEvents,
                    (int) $browser->script('return window.uploadProgressEvents;')[0],
                );
            });

            $this->assertSame(0, MediaProcessingLog::query()->count());
        } finally {
            @unlink($uploadPath);
        }
    }

    public function test_terminal_runs_hide_the_picker_but_a_validation_failure_keeps_it_available(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $this->browse(function (Browser $browser) use ($admin): void {
            $browser->loginAs($admin)
                ->visit('/admin/services/upload-recording')
                ->select('#mediaType', 'audio')
                ->waitFor('input#media-file')
                ->script(<<<'JS'
                    const uploadRoot = document.querySelector('[x-data^="mediaUploadController"]');
                    const livewireRoot = uploadRoot.closest('[wire\\:id]');
                    window.mediaUploadWire = Livewire.find(livewireRoot.getAttribute('wire:id'));
                JS);

            foreach (['failed', 'cancelled', 'manual_review'] as $status) {
                $browser->script("window.mediaUploadWire.set('processingId', 'existing-run');");
                $browser->waitUsing(5, 100, fn (): bool => $browser->script('return window.mediaUploadWire.processingId;')[0] === 'existing-run');
                $browser->script("window.mediaUploadWire.set('status', '{$status}');");
                $browser->waitUntilMissing('input#media-file', 5);
            }

            $browser->script("window.mediaUploadWire.set('processingId', null);");
            $browser->waitUsing(5, 100, fn (): bool => $browser->script('return window.mediaUploadWire.processingId;')[0] === null);
            $browser->script("window.mediaUploadWire.set('status', 'failed');");
            $browser->waitFor('input#media-file', 5);
        });
    }
}
