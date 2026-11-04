<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\ChurchService\SectionPublication\UnrecordedSong;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Operator 11-04 (item 7): a song with no sung audio keeps its title in the order of service but
 * makes no clip. The 14 such sections are the hymn's announcement, after which the recording
 * ends (1068, 1381 …) or the feed carries digital zero while it is sung (1377 §4781, 18423).
 */
class UnrecordedSongTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
    }

    /** 1381 §167073: "Just As I Am" announced, and the recording stops 2 s later. */
    #[Test]
    public function an_announced_song_the_recording_ends_after_makes_no_clip(): void
    {
        $section = $this->song(start: 1711.0, end: 1739.7, recordingEnd: 1741.8, music: 0.02);

        $this->assertSame('recording_ends', app(UnrecordedSong::class)->observe($section)['reason'] ?? null);
        $this->assertFalse(app(SongPublicationHandler::class)->isEligible($section));
    }

    /** 1377 §4781: the announcement, then 123 s of digital zero while the hymn is sung, then the prayer. */
    #[Test]
    public function an_announced_song_sung_over_a_muted_feed_makes_no_clip(): void
    {
        $section = $this->song(start: 4228.1, end: 4244.5, recordingEnd: 4400.0, music: 0.01, zeros: [4244.5, 4368.0]);

        $this->assertSame('feed_muted', app(UnrecordedSong::class)->observe($section)['reason'] ?? null);
        $this->assertFalse(app(SongPublicationHandler::class)->isEligible($section));
    }

    /** 1252's "Happy Birthday": no music to the classifier, but sung, and the service goes on. */
    #[Test]
    public function a_song_without_music_the_service_continues_after_keeps_its_clip(): void
    {
        $section = $this->song(start: 285.7, end: 316.0, recordingEnd: 4000.0, music: 0.15);

        $this->assertNull(app(UnrecordedSong::class)->observe($section));
        $this->assertTrue(app(SongPublicationHandler::class)->isEligible($section));
    }

    /** A song heard as music whose recording ends with it is a recorded song. */
    #[Test]
    public function a_song_with_music_the_recording_ends_after_keeps_its_clip(): void
    {
        $section = $this->song(start: 1500.0, end: 1700.0, recordingEnd: 1701.0, music: 0.8);

        $this->assertNull(app(UnrecordedSong::class)->observe($section));
        $this->assertTrue(app(SongPublicationHandler::class)->isEligible($section));
    }

    /** A pause of digital zero shorter than a dropout is not a muted feed. */
    #[Test]
    public function a_short_run_of_digital_zero_after_the_song_is_not_a_muted_feed(): void
    {
        $section = $this->song(start: 4228.1, end: 4244.5, recordingEnd: 4400.0, music: 0.01, zeros: [4244.5, 4254.5]);

        $this->assertNull(app(UnrecordedSong::class)->observe($section));
    }

    /** A run with no classifier timeline has nothing to judge music by. */
    #[Test]
    public function a_run_without_a_timeline_is_not_judged(): void
    {
        $section = $this->song(start: 1711.0, end: 1739.7, recordingEnd: 1741.8, music: null);

        $this->assertNull(app(UnrecordedSong::class)->observe($section));
        $this->assertTrue(app(SongPublicationHandler::class)->isEligible($section));
    }

    /**
     * A confirmed song section with a timeline scoring `$music` over it (none when null) and live
     * audio at −40 dB to the recording's end, except digital zero over `$zeros`.
     *
     * @param  array{0: float, 1: float}|null  $zeros
     */
    private function song(float $start, float $end, float $recordingEnd, ?float $music, ?array $zeros = null): ServiceSection
    {
        $churchService = ChurchService::factory()->create(['date' => '2021-05-02']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => Song::factory()->create()->id,
        ]);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create(['church_service_id' => $churchService->id]);

        $lines = [];
        for ($time = 0.0; $time <= $recordingEnd; $time += 0.5) {
            $zero = $zeros !== null && $time >= $zeros[0] && $time < $zeros[1];
            $lines[] = sprintf('pts_time:%.3f', $time);
            $lines[] = $zero ? 'lavfi.astats.Overall.RMS_level=-inf' : 'lavfi.astats.Overall.RMS_level=-40.0';
        }
        $rmsPath = 'service-transcripts/test-'.$log->processing_id.'.rms.json';
        Storage::disk('local')->put($rmsPath, implode("\n", $lines));
        $log->forceFill(['rms_log_path' => $rmsPath])->save();

        if ($music !== null) {
            $timelinePath = 'service-transcripts/test-'.$log->processing_id.'.classes.json';
            Storage::disk('local')->put($timelinePath, AudioTimelineFixture::json([[0, $start, 0.02, 0.85], [$start, $end, $music, 0.9], [$end, $recordingEnd, 0.02, 0.85]], $recordingEnd));
            $log->forceFill(['audio_timeline_path' => $timelinePath])->save();
        }

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'start_time' => $start,
            'end_time' => $end,
        ])->fresh();
    }
}
