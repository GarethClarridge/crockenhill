<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MeetingMigratePhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('images/meetings/test-command-meeting'));

        parent::tearDown();
    }

    public function test_it_migrates_meeting_photos_via_shared_service(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'test-command-meeting']);
        $directory = public_path('images/meetings/test-command-meeting');

        File::ensureDirectoryExists($directory);
        File::put("{$directory}/photo.gif", base64_decode(self::GIF_PIXEL));

        $this->artisan('meetings:migrate-photos')
            ->expectsOutputToContain('Migrated: 1')
            ->assertSuccessful();

        $this->assertCount(1, $meeting->fresh()->getMedia('photos'));
    }

    private const string GIF_PIXEL = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
}
