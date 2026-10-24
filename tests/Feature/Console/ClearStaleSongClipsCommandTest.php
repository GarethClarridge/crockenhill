<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Run 1002, 2026-10-14: a recomposition re-typed rows that carried song clips, and the prayer and
 * the sermon kept them. The cause is fixed in section sync (R9); this clears what it left.
 */
class ClearStaleSongClipsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_dry_run_reports_and_changes_nothing(): void
    {
        [$prayer, $video] = $this->staleClipOnAPrayer();

        $this->artisan('historic-import:clear-stale-song-clips')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNotNull($prayer->fresh()->extracted_video_path);
        $this->assertSame($prayer->id, $video->fresh()->service_section_id);
    }

    #[Test]
    public function a_clip_moves_to_the_runs_only_section_of_its_song(): void
    {
        [$prayer, $video, $run, $song] = $this->staleClipOnAPrayer();
        $songSection = $this->songSection($run, $song, order: 3);

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true])->assertExitCode(0);

        $this->assertNull($prayer->fresh()->extracted_video_path);
        $this->assertSame($songSection->id, $video->fresh()->service_section_id);
    }

    /**
     * The song's own section already has its clip, so this one is a stale duplicate. The file
     * stays: quarantine is single-copy and deleting is the operator's decision.
     */
    #[Test]
    public function a_clip_whose_song_section_has_its_own_is_retired_and_kept(): void
    {
        [$prayer, $video, $run, $song] = $this->staleClipOnAPrayer();
        $songSection = $this->songSection($run, $song, order: 3);
        SongVideo::factory()->create(['song_id' => $song->id, 'service_section_id' => $songSection->id]);

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true])->assertExitCode(0);

        $video->refresh();
        $this->assertNull($video->service_section_id);
        $this->assertSame(SermonPublicationState::Quarantined, $video->publication_state);
        $this->assertTrue(Storage::disk('historic_quarantine')->exists($video->video_file_path));
        $this->assertNull($prayer->fresh()->extracted_video_path);
    }

    #[Test]
    public function a_published_section_is_left_alone(): void
    {
        [$prayer, $video] = $this->staleClipOnAPrayer();
        $prayer->forceFill(['publication_status' => ServiceSectionPublicationStatus::Published, 'published_sermon_id' => Sermon::factory()->create()->id, 'published_at' => now(), 'extracted_at' => now(), 'extracted_audio_path' => 'sermons/audio/1229.mp3'])->save();

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true])
            ->expectsOutputToContain('published')
            ->assertExitCode(0);

        $this->assertNotNull($prayer->fresh()->extracted_video_path);
        $this->assertSame($prayer->id, $video->fresh()->service_section_id);
    }

    /**
     * Run 1096: the 10-11 re-detection gave row 1814 to "Christ is risen" before section sync
     * released clips (10-16), so it kept the September clip of "I Know That My Redeemer". The
     * section's own cut is its media and stays.
     */
    #[Test]
    public function a_song_sections_clip_of_another_song_is_retired_and_its_own_cut_kept(): void
    {
        [$video, $run, $oldSong] = $this->clipOfAnotherSong();
        $section = $video->serviceSection;

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true])->assertExitCode(0);

        $video->refresh();
        $this->assertNull($video->service_section_id);
        $this->assertSame(SermonPublicationState::Quarantined, $video->publication_state);
        $this->assertSame($oldSong->id, $video->song_id);
        $this->assertSame('section-publications/1814-f283aec7bec2db3f/video.mp4', $section->fresh()->extracted_video_path);
    }

    #[Test]
    public function a_song_sections_clip_of_another_song_moves_to_that_songs_section(): void
    {
        [$video, $run, $oldSong] = $this->clipOfAnotherSong();
        $oldSongsSection = $this->songSection($run, $oldSong, order: 5);

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true])->assertExitCode(0);

        $this->assertSame($oldSongsSection->id, $video->fresh()->service_section_id);
    }

    #[Test]
    public function a_song_section_with_no_song_releases_its_clip(): void
    {
        [$video] = $this->clipOfAnotherSong();
        $video->serviceSection->forceFill(['church_service_item_id' => null])->save();

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true])->assertExitCode(0);

        $this->assertNull($video->fresh()->service_section_id);
    }

    #[Test]
    public function a_song_sections_clip_of_its_own_song_is_left_alone(): void
    {
        [$video, $run] = $this->clipOfAnotherSong();
        $video->forceFill(['song_id' => $video->serviceSection->resolvedSongId()])->save();

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true])
            ->expectsOutputToContain('0 section(s)')
            ->assertExitCode(0);

        $this->assertNotNull($video->fresh()->service_section_id);
    }

    #[Test]
    public function the_report_lists_every_change_by_run(): void
    {
        [$prayer, $video, $run] = $this->staleClipOnAPrayer();
        Storage::fake('local');

        $this->artisan('historic-import:clear-stale-song-clips', ['--execute' => true, '--report' => 'scratch/stale-clips.json'])
            ->assertExitCode(0);

        $report = json_decode(Storage::disk('local')->get('scratch/stale-clips.json'), true);
        $this->assertSame([[
            'section_id' => $prayer->id,
            'section_type' => 'prayer',
            'cleared_path' => 'sermons/songs/859/1229.mp4',
            'song_videos' => [['song_video_id' => $video->id, 'song_id' => $video->song_id, 'outcome' => 'retired', 'section_id' => null]],
        ]], $report['runs'][(string) $run->id]);
    }

    /**
     * @return array{ServiceSection, SongVideo, MediaProcessingLog, Song}
     */
    private function staleClipOnAPrayer(): array
    {
        Storage::fake('historic_quarantine');
        Storage::disk('historic_quarantine')->put('sermons/songs/859/1229.mp4', 'clip');

        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $song = Song::factory()->create();
        $prayer = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 2,
            'church_service_item_id' => null,
            'extracted_video_path' => 'sermons/songs/859/1229.mp4',
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
        ]);
        $video = SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $prayer->id,
            'video_file_path' => 'sermons/songs/859/1229.mp4',
            'asset_disk' => 'historic_quarantine',
            'publication_state' => SermonPublicationState::Quarantined,
        ]);

        return [$prayer, $video, $run, $song];
    }

    /**
     * @return array{SongVideo, MediaProcessingLog, Song}
     */
    private function clipOfAnotherSong(): array
    {
        Storage::fake('historic_quarantine');
        Storage::disk('historic_quarantine')->put('sermons/songs/420/1814.mp4', 'clip');

        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $oldSong = Song::factory()->create();
        $section = $this->songSection($run, Song::factory()->create(), order: 4);
        $section->forceFill(['extracted_video_path' => 'section-publications/1814-f283aec7bec2db3f/video.mp4'])->save();
        $video = SongVideo::factory()->create([
            'song_id' => $oldSong->id,
            'service_section_id' => $section->id,
            'video_file_path' => 'sermons/songs/420/1814.mp4',
            'asset_disk' => 'historic_quarantine',
            'publication_state' => SermonPublicationState::Quarantined,
        ]);

        return [$video, $run, $oldSong];
    }

    private function songSection(MediaProcessingLog $run, Song $song, int $order): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => $order,
            'church_service_item_id' => ChurchServiceItem::factory()->song()->create(['song_id' => $song->id])->id,
            'extracted_video_path' => null,
        ]);
    }
}
