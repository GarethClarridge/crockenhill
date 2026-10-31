<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportReleaseAttempt;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\Import\HistoricSermonPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plan §4.1a step 3b, step 3: a weekly run retired into a round keeps its published sermon
 * until release, and release then replaces it with the re-import's cut, keeping its id,
 * slug and podcast GUID (R11, amended 10-31). The retired run's published song clips are
 * withdrawn as the re-import's clips of the same songs are released.
 */
class HistoricSermonRetiredRunReplacementTest extends TestCase
{
    use RefreshDatabase;

    private const NewAudio = 'sermons/historic/new-audio.mp3';

    private const OldVideo = 'sermons/858/video.mp4';

    private HistoricImportOperation $operation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'church.services.public_from' => '2000-01-01',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'public',
        ]);
        Storage::fake('historic_quarantine');
        Storage::fake('public');

        $this->operation = $this->operation('historic-'.str_repeat('a', 32));
    }

    #[Test]
    public function release_folds_the_new_cut_into_the_published_sermon_a_retired_weekly_run_awaits(): void
    {
        [$published, $retired] = $this->retiredWeeklyRun();
        [$cut, $reimport] = $this->reimportedCut();

        $released = app(HistoricSermonPublicationService::class)->releaseRecords($this->operation, [$cut->id], []);

        $this->assertSame($published->id, $released['sermons'][0]->id);
        $this->assertNull($cut->fresh());

        $published = $published->fresh();
        $this->assertSame(SermonPublicationState::Published, $published?->publication_state);
        $this->assertSame('sunday-1st-march-2026', $published?->slug);
        $this->assertSame('Remembering the faithful God', $published?->title);
        $this->assertSame(self::NewAudio, $published?->audio_file_path);
        $this->assertNull($published?->video_file_path);
        $this->assertSame('public', $published?->asset_disk);
        $this->assertSame($this->operation->id, $published?->historic_import_operation_id);
        $this->assertSame($reimport->processing_id, $published?->livestream_processing_id);
        $this->assertSame($published?->id, $reimport->fresh()?->sermon_id);
        Storage::disk('public')->assertExists(self::NewAudio);
        Storage::disk('public')->assertExists(self::OldVideo);

        $record = $retired->fresh()?->retirementRecord()['sermon'] ?? [];
        $this->assertFalse($record['awaiting_replacement'] ?? true);
        $this->assertSame($cut->id, $record['replaced_by'] ?? null);
        $this->assertSame('Sunday 1St March 2026', $record['replaced_content']['title'] ?? null);
        $this->assertSame(self::OldVideo, $record['replaced_content']['video_file_path'] ?? null);
    }

    /** A completed batch stays an exact no-op although the sermon it named was folded away. */
    #[Test]
    public function a_second_release_of_a_folded_batch_is_an_exact_no_op(): void
    {
        [$published] = $this->retiredWeeklyRun();
        [$cut] = $this->reimportedCut();
        $service = app(HistoricSermonPublicationService::class);

        $service->releaseRecords($this->operation, [$cut->id], []);
        $again = $service->releaseRecords($this->operation, [$cut->id], []);

        $this->assertSame($published->id, $again['sermons'][0]->id);
        $this->assertSame(SermonPublicationState::Published, $again['sermons'][0]->publication_state);
        $this->assertSame(1, HistoricImportReleaseAttempt::query()->count());
    }

    #[Test]
    public function a_sermon_no_retired_run_awaits_releases_as_its_own_record(): void
    {
        [$published] = $this->retiredWeeklyRun(date: '2026-03-08');
        [$cut] = $this->reimportedCut();

        $released = app(HistoricSermonPublicationService::class)->releaseRecords($this->operation, [$cut->id], []);

        $this->assertSame($cut->id, $released['sermons'][0]->id);
        $this->assertSame(SermonPublicationState::Published, $cut->fresh()?->publication_state);
        $this->assertSame('Sunday 1St March 2026', $published->fresh()?->title);
    }

    #[Test]
    public function a_run_retired_into_another_operation_is_not_replaced_by_this_one(): void
    {
        [$published] = $this->retiredWeeklyRun(operationId: 'historic-'.str_repeat('b', 32));
        [$cut] = $this->reimportedCut();

        app(HistoricSermonPublicationService::class)->releaseRecords($this->operation, [$cut->id], []);

        $this->assertSame(SermonPublicationState::Published, $cut->fresh()?->publication_state);
        $this->assertSame('Sunday 1St March 2026', $published->fresh()?->title);
    }

    #[Test]
    public function release_withdraws_the_retired_runs_published_clip_of_the_same_song_only(): void
    {
        $service = ChurchService::factory()->create();
        [, $retired] = $this->retiredWeeklyRun(churchService: $service);
        $replacedSong = Song::factory()->create();
        $keptSong = Song::factory()->create();
        $oldClip = $this->publishedClip($retired, $replacedSong, $service);
        $otherClip = $this->publishedClip($retired, $keptSong, $service);
        $retired->putRetirement([...$retired->retirementRecord() ?? [], 'published_sections' => [
            $oldClip->service_section_id, $otherClip->service_section_id,
        ]]);

        $newClip = SongVideo::factory()->quarantined()->create([
            'song_id' => $replacedSong->id,
            'church_service_id' => $service->id,
            'service_section_id' => null,
            'historic_import_operation_id' => $this->operation->id,
        ]);
        $newClip->forceFill(['video_file_path' => "song-videos/{$this->operation->id}/{$newClip->id}/song.mp4"])->save();
        Storage::disk('historic_quarantine')->put($newClip->video_file_path, 'song-video-bytes');

        app(HistoricSermonPublicationService::class)->releaseRecords($this->operation, [], [$newClip->id]);

        $this->assertSame(SermonPublicationState::Published, $newClip->fresh()?->publication_state);
        $this->assertSame(SermonPublicationState::Quarantined, $oldClip->fresh()?->publication_state);
        $this->assertSame(SermonPublicationState::Published, $otherClip->fresh()?->publication_state);
    }

    /**
     * The shape 910 has after retirement: superseded, its published sermon 858 kept and
     * recorded as awaiting replacement by this operation.
     *
     * @return array{0: Sermon, 1: MediaProcessingLog}
     */
    private function retiredWeeklyRun(
        string $date = '2026-03-01',
        ?string $operationId = null,
        ?ChurchService $churchService = null,
    ): array {
        $published = Sermon::factory()->create([
            'date' => $date,
            'service' => SermonService::Morning,
            'slug' => 'sunday-1st-march-2026',
            'title' => 'Sunday 1St March 2026',
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
            'historic_import_operation_id' => null,
            'video_file_path' => self::OldVideo,
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
        ]);
        Storage::disk('public')->put(self::OldVideo, 'old-video-bytes');

        $retired = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => null,
            'sermon_id' => $published->id,
            'church_service_id' => $churchService?->id,
            'extracted_date' => $date,
            'extracted_service' => 'morning',
            'superseded_at' => now(),
            'processing_metadata' => [],
        ]);
        $retired->putRetirement([
            'recorded_by' => 'operator',
            'note' => 'Re-imported in the round.',
            'lane' => 'weekly',
            'operation_id' => $operationId ?? $this->operation->operation_id,
            'manifest_item_key' => $date.'-morning',
            'sermon' => ['id' => $published->id, 'slug' => $published->slug, 'awaiting_replacement' => true],
            'published_sections' => [],
        ]);

        return [$published, $retired->fresh() ?? $retired];
    }

    /** @return array{0: Sermon, 1: MediaProcessingLog} */
    private function reimportedCut(): array
    {
        $cut = Sermon::factory()->create([
            'date' => '2026-03-01',
            'service' => SermonService::Morning,
            'slug' => 'remembering-the-faithful-god',
            'title' => 'Remembering the faithful God',
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'historic_import_operation_id' => $this->operation->id,
            'audio_file_path' => self::NewAudio,
            'video_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
        ]);
        Storage::disk('historic_quarantine')->put(self::NewAudio, 'new-audio-bytes');

        $reimport = MediaProcessingLog::factory()->withSermon($cut)->create([
            'historic_import_operation_id' => $this->operation->id,
        ]);
        $cut->forceFill(['livestream_processing_id' => $reimport->processing_id])->save();

        return [$cut->fresh() ?? $cut, $reimport];
    }

    private function publishedClip(MediaProcessingLog $run, Song $song, ChurchService $service): SongVideo
    {
        $section = ServiceSection::factory()->create(['media_processing_log_id' => $run->id]);

        return SongVideo::factory()->create([
            'song_id' => $song->id,
            'church_service_id' => $service->id,
            'service_section_id' => $section->id,
            'publication_state' => SermonPublicationState::Published,
        ]);
    }

    private function operation(string $operationId): HistoricImportOperation
    {
        return HistoricImportOperation::query()->create([
            'operation_id' => $operationId,
            'binding_hash' => hash('sha256', $operationId),
            'batch_key' => 'historic-retired-run-replacement',
            'manifest_hashes' => ['video' => str_repeat('c', 64)],
            'plan_hash' => str_repeat('d', 64),
            'target_fingerprint' => str_repeat('e', 64),
            'runtime_fingerprint' => str_repeat('f', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 100,
        ]);
    }
}
