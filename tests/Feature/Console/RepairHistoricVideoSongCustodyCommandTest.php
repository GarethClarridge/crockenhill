<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RepairHistoricVideoSongCustodyCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');

        config([
            'filesystems.disks.historic_staging.root' => Storage::disk('historic_staging')->path(''),
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
            'thumbnail-generation.storage.disk' => 'historic_staging',
        ]);
    }

    #[Test]
    public function it_previews_exact_song_rows_and_held_candidates_without_writing(): void
    {
        [$operation, $log, $songVideo, $heldSection] = $this->historicSongRun(includeHeld: true);

        $this->artisan('historic-import:repair-video-song-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain($log->processing_id)
            ->expectsOutputToContain('Held candidates')
            ->assertSuccessful();

        $songVideo->refresh();
        self::assertSame(SermonPublicationState::Published, $songVideo->publication_state);
        self::assertNull($songVideo->asset_disk);
        self::assertNull($songVideo->historic_import_operation_id);
        self::assertNotNull($heldSection);
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $songVideo->video_file_path));
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $heldSection->extracted_video_path));
        Storage::disk('historic_quarantine')->assertMissing($songVideo->video_file_path);
        Storage::disk('historic_quarantine')->assertMissing($heldSection->extracted_video_path);
    }

    #[Test]
    public function it_requires_explicit_confirmation_for_apply(): void
    {
        [$operation, $log, $songVideo] = $this->historicSongRun();

        $this->artisan('historic-import:repair-video-song-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('--apply requires --yes')
            ->assertFailed();

        $songVideo->refresh();
        self::assertSame(SermonPublicationState::Published, $songVideo->publication_state);
        self::assertNull($songVideo->asset_disk);
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $songVideo->video_file_path));
    }

    #[Test]
    public function it_binds_song_rows_and_gives_held_candidates_custody_without_releasing_them(): void
    {
        [$operation, $log, $songVideo, $heldSection] = $this->historicSongRun(includeHeld: true);

        $this->artisan('historic-import:repair-video-song-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Promoted 1 song video(s)')
            ->expectsOutputToContain('Held candidates retained for review: 1')
            ->assertSuccessful();

        $songVideo->refresh();
        self::assertSame(SermonPublicationState::Quarantined, $songVideo->publication_state);
        self::assertSame('historic_quarantine', $songVideo->asset_disk);
        self::assertSame($operation->id, $songVideo->historic_import_operation_id);
        self::assertNotNull($heldSection);

        // The held clip gains durable custody without crossing the review gate.
        $heldSection->refresh();
        self::assertSame('historic_quarantine', $heldSection->asset_disk);
        self::assertSame(ServiceSectionPublicationStatus::PendingApproval, $heldSection->publication_status);

        Storage::disk('historic_staging')->assertMissing($this->stagedPath($log, $songVideo->video_file_path));
        Storage::disk('historic_staging')->assertMissing($this->stagedPath($log, $heldSection->extracted_video_path));
        Storage::disk('historic_quarantine')->assertExists($songVideo->video_file_path);
        Storage::disk('historic_quarantine')->assertExists($heldSection->extracted_video_path);
    }

    #[Test]
    public function an_identical_repair_replay_is_a_no_op(): void
    {
        [$operation, $log, $songVideo] = $this->historicSongRun();
        $arguments = [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ];

        $this->artisan('historic-import:repair-video-song-custody', $arguments)->assertSuccessful();
        $before = $songVideo->fresh()?->updated_at;

        $this->artisan('historic-import:repair-video-song-custody', $arguments)
            ->expectsOutputToContain('already repaired')
            ->assertSuccessful();

        $songVideo->refresh();
        self::assertSame(SermonPublicationState::Quarantined, $songVideo->publication_state);
        self::assertSame('historic_quarantine', $songVideo->asset_disk);
        self::assertSame($before?->toISOString(), $songVideo->updated_at?->toISOString());
        Storage::disk('historic_quarantine')->assertExists($songVideo->video_file_path);
    }

    #[Test]
    public function a_conflicting_destination_fails_closed_after_binding_and_retains_staging(): void
    {
        [$operation, $log, $songVideo] = $this->historicSongRun();
        Storage::disk('historic_quarantine')->put(
            $songVideo->video_file_path,
            str_repeat('x', strlen('song video')),
        );

        $this->artisan('historic-import:repair-video-song-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('differs from existing destination')
            ->assertFailed();

        $songVideo->refresh();
        self::assertSame(SermonPublicationState::Quarantined, $songVideo->publication_state);
        self::assertSame('historic_staging', $songVideo->asset_disk);
        self::assertSame($operation->id, $songVideo->historic_import_operation_id);
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $songVideo->video_file_path));
        self::assertSame(
            str_repeat('x', strlen('song video')),
            Storage::disk('historic_quarantine')->get($songVideo->video_file_path),
        );
    }

    #[Test]
    public function it_rejects_a_missing_song_source_before_any_write(): void
    {
        [$operation, $log, $songVideo] = $this->historicSongRun();
        Storage::disk('historic_staging')->delete($this->stagedPath($log, $songVideo->video_file_path));

        $this->artisan('historic-import:repair-video-song-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('missing from historic staging and quarantine')
            ->assertFailed();

        $songVideo->refresh();
        self::assertSame(SermonPublicationState::Published, $songVideo->publication_state);
        self::assertNull($songVideo->asset_disk);
        self::assertNull($songVideo->historic_import_operation_id);
    }

    #[Test]
    public function it_gives_a_held_only_run_durable_custody_without_creating_song_rows(): void
    {
        [$operation, $log, $songVideo, $heldSection] = $this->historicSongRun(
            includeSongVideo: false,
            includeHeld: true,
        );

        $this->artisan('historic-import:repair-video-song-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('promoted into quarantine: 1')
            ->assertSuccessful();

        self::assertNull($songVideo);
        self::assertNotNull($heldSection);

        // Custody moves; the review gate does not.
        $heldSection->refresh();
        self::assertSame('historic_quarantine', $heldSection->asset_disk);
        self::assertSame(ServiceSectionPublicationStatus::PendingApproval, $heldSection->publication_status);
        self::assertSame(0, SongVideo::query()->count());

        Storage::disk('historic_quarantine')->assertExists($heldSection->extracted_video_path);
        Storage::disk('historic_staging')->assertMissing($this->stagedPath($log, $heldSection->extracted_video_path));
    }

    #[Test]
    public function promoting_a_held_candidate_is_an_idempotent_replay(): void
    {
        [$operation, $log, , $heldSection] = $this->historicSongRun(
            includeSongVideo: false,
            includeHeld: true,
        );

        $arguments = [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ];

        $this->artisan('historic-import:repair-video-song-custody', $arguments)->assertSuccessful();
        $this->artisan('historic-import:repair-video-song-custody', $arguments)
            ->expectsOutputToContain('promoted into quarantine: 1')
            ->assertSuccessful();

        self::assertNotNull($heldSection);
        $heldSection->refresh();
        self::assertSame('historic_quarantine', $heldSection->asset_disk);
        Storage::disk('historic_quarantine')->assertExists($heldSection->extracted_video_path);
    }

    /**
     * @return array{0: HistoricImportOperation, 1: MediaProcessingLog, 2: SongVideo|null, 3: ServiceSection|null}
     */
    private function historicSongRun(
        bool $includeSongVideo = true,
        bool $includeHeld = false,
    ): array {
        $operation = $this->createHistoricImportOperation();
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            $operation->manifest_hashes['video'],
            $operation->plan_hash,
        );
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => (string) Str::uuid(),
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'cleanup',
            'sermon_id' => null,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'historic-video-job-'.Str::random(8),
                    'manifest_item_key' => '2020-01-01-morning-'.Str::random(4),
                    'operation_id' => $operation->operation_id,
                    'staging_context' => $context->toArray(),
                ],
            ],
        ]);

        $songVideo = null;

        if ($includeSongVideo) {
            $song = Song::factory()->create();
            $section = ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => ServiceSectionType::Song->value,
                'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            ]);
            $songVideo = SongVideo::factory()->create([
                'song_id' => $song->id,
                'service_section_id' => $section->id,
                'video_file_path' => 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4',
                'publication_state' => SermonPublicationState::Published,
                'asset_disk' => null,
                'historic_import_operation_id' => null,
            ]);
            Storage::disk('historic_staging')->put(
                $context->batchRoot.'/'.$songVideo->video_file_path,
                'song video',
            );
        }

        $heldSection = null;

        if ($includeHeld) {
            $heldSection = ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => ServiceSectionType::Song->value,
                'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
                'extracted_video_path' => 'section-publications/'.$log->id.'/held-video.mp4',
                'extracted_audio_path' => null,
            ]);
            Storage::disk('historic_staging')->put(
                $context->batchRoot.'/'.$heldSection->extracted_video_path,
                'held song video',
            );
        }

        return [$operation, $log, $songVideo, $heldSection];
    }

    private function stagedPath(MediaProcessingLog $log, ?string $path): string
    {
        self::assertNotNull($path);
        $context = $log->historicStagingContext();
        self::assertNotNull($context);

        return $context->batchRoot.'/'.$path;
    }
}
