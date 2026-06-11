<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
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
}
