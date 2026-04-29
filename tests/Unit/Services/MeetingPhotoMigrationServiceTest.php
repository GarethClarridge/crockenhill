<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Meeting;
use App\Services\MeetingPhotoMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingPhotoMigrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $meetingSlug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meetingSlug = 'test-meeting-'.Str::lower((string) Str::ulid());

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path("images/meetings/{$this->meetingSlug}"));

        parent::tearDown();
    }

    public function test_it_is_rerunnable_at_the_file_level(): void
    {
        $meeting = Meeting::factory()->create(['slug' => $this->meetingSlug]);
        $directory = public_path("images/meetings/{$this->meetingSlug}");

        File::ensureDirectoryExists($directory);
        File::put("{$directory}/one.gif", base64_decode(self::GIF_PIXEL));
        File::put("{$directory}/two.gif", base64_decode(self::GIF_PIXEL));

        $meeting->addMedia("{$directory}/one.gif")
            ->withCustomProperties(['legacy_photo_path' => "images/meetings/{$this->meetingSlug}/one.gif"])
            ->preservingOriginal()
            ->toMediaCollection('photos');

        $service = app(MeetingPhotoMigrationService::class);

        $firstRun = $service->migrate();
        $secondRun = $service->migrate();

        $this->assertSame(1, $firstRun['summary']['migrated']);
        $this->assertSame(1, $firstRun['summary']['skipped']);
        $this->assertSame(0, $secondRun['summary']['migrated']);
        $this->assertSame(2, $secondRun['summary']['skipped']);
        $this->assertCount(2, $meeting->fresh()->getMedia('photos'));
    }

    private const string GIF_PIXEL = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
}
