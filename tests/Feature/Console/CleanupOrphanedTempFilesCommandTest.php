<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupOrphanedTempFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_delete_files_referenced_by_processing_logs(): void
    {
        Storage::fake('local');

        $filePath = 'livestream/temp/recording.mp4';
        Storage::disk('local')->put($filePath, 'video content');

        touch(Storage::disk('local')->path($filePath), now()->subHours(48)->timestamp);

        MediaProcessingLog::factory()->processing()->create([
            'source_file_path' => $filePath,
        ]);

        $this->artisan('media:cleanup-temp-files', ['--hours' => 1])
            ->assertSuccessful();

        Storage::disk('local')->assertExists($filePath);
    }

    /**
     * WP-A1's acceptance test. The full-service transcript used to live on the
     * temp disk under `temp/service_transcript_*.json`, which this sweep deletes
     * 24 hours after a run completes — taking with it the one artifact that makes
     * re-running structure detection, song matching and section classification
     * possible without the source recording. It now lives on the transcript disk,
     * and this asserts the sweep cannot reach it.
     */
    #[Test]
    public function it_does_not_delete_the_stored_service_transcript_of_a_completed_run(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'public',
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-03-22',
        ]);

        $transcriptPath = app(ServiceArtifactStorage::class)->putJson(
            $log->processing_id,
            'normalized',
            ['cues' => [['start' => 0.0, 'end' => 4.0, 'text' => 'Welcome']]],
        );

        $log->putServiceTranscriptPath($transcriptPath);

        $this->artisan('media:cleanup-temp-files', ['--hours' => 1])
            ->assertSuccessful();

        Storage::disk('public')->assertExists($transcriptPath);
        $this->assertTrue(
            $log->fresh()->hasStoredServiceTranscript(),
            'A completed run must still resolve its full-service transcript after the sweep.',
        );
    }

    #[Test]
    public function it_does_not_delete_files_referenced_by_manual_review_logs(): void
    {
        Storage::fake('local');

        $filePath = 'temp/video-processing/livestream-2024.mp4';
        Storage::disk('local')->put($filePath, 'video content');

        touch(Storage::disk('local')->path($filePath), now()->subHours(48)->timestamp);

        MediaProcessingLog::factory()->manualReviewRequired()->create([
            'source_file_path' => $filePath,
        ]);

        $this->artisan('media:cleanup-temp-files', ['--hours' => 1])
            ->assertSuccessful();

        Storage::disk('local')->assertExists($filePath);
    }

    #[Test]
    public function it_does_not_delete_files_referenced_by_started_logs(): void
    {
        Storage::fake('local');

        $filePath = 'livestream/temp/starting-up.mp4';
        Storage::disk('local')->put($filePath, 'video content');

        touch(Storage::disk('local')->path($filePath), now()->subHours(48)->timestamp);

        MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Started,
            'source_file_path' => $filePath,
        ]);

        $this->artisan('media:cleanup-temp-files', ['--hours' => 1])
            ->assertSuccessful();

        Storage::disk('local')->assertExists($filePath);
    }

    #[Test]
    public function it_deletes_orphaned_files_with_no_matching_log(): void
    {
        Storage::fake('local');

        $filePath = 'livestream/temp/orphaned-recording.mp4';
        Storage::disk('local')->put($filePath, 'video content');

        touch(Storage::disk('local')->path($filePath), now()->subHours(48)->timestamp);

        $this->artisan('media:cleanup-temp-files', ['--hours' => 1])
            ->assertSuccessful();

        Storage::disk('local')->assertMissing($filePath);
    }

    #[Test]
    public function it_does_not_delete_anything_in_dry_run_mode(): void
    {
        Storage::fake('local');

        $orphanedFile = 'livestream/temp/orphaned.mp4';
        $protectedFile = 'livestream/temp/protected.mp4';
        Storage::disk('local')->put($orphanedFile, 'content');
        Storage::disk('local')->put($protectedFile, 'content');

        touch(Storage::disk('local')->path($orphanedFile), now()->subHours(48)->timestamp);
        touch(Storage::disk('local')->path($protectedFile), now()->subHours(48)->timestamp);

        MediaProcessingLog::factory()->processing()->create([
            'source_file_path' => $protectedFile,
        ]);

        $this->artisan('media:cleanup-temp-files', ['--hours' => 1, '--dry-run' => true])
            ->assertSuccessful();

        Storage::disk('local')->assertExists($orphanedFile);
        Storage::disk('local')->assertExists($protectedFile);
    }

    #[Test]
    public function it_exits_successfully_when_files_are_skipped_due_to_active_logs(): void
    {
        Storage::fake('local');

        $filePath = 'livestream/temp/active-processing.mp4';
        Storage::disk('local')->put($filePath, 'video content');
        touch(Storage::disk('local')->path($filePath), now()->subHours(48)->timestamp);

        MediaProcessingLog::factory()->pending()->create([
            'source_file_path' => $filePath,
        ]);

        // Skipping a protected file is the correct, safe behavior — exit 0, not 1
        $this->artisan('media:cleanup-temp-files', ['--hours' => 1])
            ->assertSuccessful();

        Storage::disk('local')->assertExists($filePath);
    }
}
