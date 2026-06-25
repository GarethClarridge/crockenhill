<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Meeting;
use App\Services\MeetingPhotoMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MeetingPhotoMigrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private MeetingPhotoMigrationService $service;

    private string $testSlug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSlug = Str::slug('test-migration-'.Str::random(8));
        $this->service = new MeetingPhotoMigrationService;
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path("images/meetings/{$this->testSlug}"));

        parent::tearDown();
    }

    #[Test]
    public function it_migrates_photos_successfully(): void
    {
        // Clear any existing meetings to have a predictable count
        Meeting::query()->delete();

        $meeting = Meeting::factory()->create(['slug' => $this->testSlug]);
        $directory = public_path("images/meetings/{$this->testSlug}");

        File::ensureDirectoryExists($directory);
        File::put("{$directory}/photo1.jpg", base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));
        File::put("{$directory}/photo2.png", base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));

        $result = $this->service->migrate();

        $this->assertEquals(1, $result['summary']['meetings_examined']);
        $this->assertEquals(2, $result['summary']['migrated']);
        $this->assertEquals(0, $result['summary']['skipped']);
        $this->assertEquals(0, $result['summary']['failed']);

        /** @var Meeting $meeting */
        $meeting = $meeting->refresh();
        $this->assertCount(2, $meeting->getMedia('photos'));

        /** @var Media|null $media1 */
        $media1 = $meeting->getMedia('photos')->first(fn ($m) => $m->getCustomProperty('legacy_file_name') === 'photo1.jpg');
        $this->assertNotNull($media1);
        $this->assertEquals("images/meetings/{$this->testSlug}/photo1.jpg", $media1->getCustomProperty('legacy_photo_path'));

        /** @var Media|null $media2 */
        $media2 = $meeting->getMedia('photos')->first(fn ($m) => $m->getCustomProperty('legacy_file_name') === 'photo2.png');
        $this->assertNotNull($media2);
    }

    #[Test]
    public function it_respects_dry_run_option(): void
    {
        Meeting::query()->delete();
        $meeting = Meeting::factory()->create(['slug' => $this->testSlug]);
        $directory = public_path("images/meetings/{$this->testSlug}");

        File::ensureDirectoryExists($directory);
        File::put("{$directory}/photo1.jpg", 'content');

        $result = $this->service->migrate(dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertEquals(1, $result['summary']['migrated']);
        /** @var Meeting $freshMeeting */
        $freshMeeting = $meeting->fresh();
        $this->assertCount(0, $freshMeeting->getMedia('photos'));
        $this->assertEquals('dry-run', $result['items'][0]['status']);
    }

    #[Test]
    public function it_skips_when_directory_does_not_exist(): void
    {
        Meeting::query()->delete();
        Meeting::factory()->create(['slug' => 'non-existent-slug']);

        $result = $this->service->migrate();

        $this->assertEquals(1, $result['summary']['meetings_examined']);
        $this->assertEquals(1, $result['summary']['skipped']);
        $this->assertEquals(0, $result['summary']['migrated']);
        $this->assertEquals('skip', $result['items'][0]['status']);
        $this->assertStringContainsString('no legacy photos directory', $result['items'][0]['label']);
    }

    #[Test]
    public function it_skips_when_no_supported_files_are_found(): void
    {
        Meeting::query()->delete();
        Meeting::factory()->create(['slug' => $this->testSlug]);
        $directory = public_path("images/meetings/{$this->testSlug}");

        File::ensureDirectoryExists($directory);
        File::put("{$directory}/not-an-image.txt", 'content');

        $result = $this->service->migrate();

        $this->assertEquals(1, $result['summary']['skipped']);
        $this->assertStringContainsString('no supported image files found', $result['items'][0]['label']);
    }

    #[Test]
    public function it_skips_already_migrated_photos(): void
    {
        Meeting::query()->delete();
        $meeting = Meeting::factory()->create(['slug' => $this->testSlug]);
        $directory = public_path("images/meetings/{$this->testSlug}");

        File::ensureDirectoryExists($directory);
        $filePath = "{$directory}/photo1.jpg";
        File::put($filePath, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));

        // Manually migrate once
        /** @var Meeting $meeting */
        $meeting->addMedia($filePath)
            ->withCustomProperties([
                'legacy_photo_path' => "images/meetings/{$this->testSlug}/photo1.jpg",
                'legacy_file_name' => 'photo1.jpg',
            ])
            ->preservingOriginal()
            ->toMediaCollection('photos');

        /** @var Meeting $freshMeeting */
        $freshMeeting = $meeting->fresh();
        $this->assertCount(1, $freshMeeting->getMedia('photos'));

        $result = $this->service->migrate();

        $this->assertEquals(1, $result['summary']['skipped']);
        $this->assertEquals(0, $result['summary']['migrated']);
        $this->assertStringContainsString('already migrated', $result['items'][0]['label']);
    }

    #[Test]
    public function it_handles_failures_during_media_addition(): void
    {
        Meeting::query()->delete();

        // Create a meeting but we will mock the addMedia to fail
        // Actually it's easier to provide a broken file path or similar,
        // but the service uses $photo->getPathname() which is real.

        $meeting = Meeting::factory()->create(['slug' => $this->testSlug]);
        $directory = public_path("images/meetings/{$this->testSlug}");
        File::ensureDirectoryExists($directory);
        $filePath = "{$directory}/broken.jpg";
        File::put($filePath, 'not really an image but Medialibrary might fail if it tries to process it?');

        // To force a failure, we can try to use a file that isn't readable or something.
        // Or we can mock the meeting model. But the service calls Meeting::query()->get().

        // Let's try to make the file unreadable.
        chmod($filePath, 0000);

        try {
            $result = $this->service->migrate();

            $this->assertEquals(1, $result['summary']['failed']);
            $this->assertEquals('error', $result['items'][0]['status']);
        } finally {
            chmod($filePath, 0644);
        }
    }
}
