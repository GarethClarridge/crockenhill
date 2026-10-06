<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Canary 11 listening (operator, 2026-10-06): "We really want to end songs when it gets to
 * silence, or when someone starts talking." Four of five song ends lost the last line or the
 * outro: the largest pause in reach sat before the last sung line (1221 §2718, 1250 §3131), or
 * the cut stopped at the last word while the note was held (1028 §1398, 1250 §4911). 1304 §4884
 * kept half the benediction. Each shape below is taken from those runs' words and levels.
 */
class SongEndEdgesTest extends TestCase
{
    use DatabaseTransactions;

    private const LOUD = -20.0;

    private const SILENT = -90.0;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);
    }

    /** 1250 §4911: the prayer's cue is timed 20 s early, over the last lines and the outro. */
    #[Test]
    public function a_song_runs_on_through_its_outro_until_someone_starts_talking(): void
    {
        $last = ['start' => 4469.04, 'end' => 4475.48, 'text' => 'Glory to you, there is no greater thing'];
        $prayer = ['start' => 4475.48, 'end' => 4498.26, 'text' => 'Lord God, we thank you for fellowship with one another.'];
        $section = $this->song(4300.0, 4475.48, [$last, $prayer], [[4300, 4495.9, self::LOUD], [4495.9, 4510, -25.0]]);
        $this->bank($section, 4475.48, [
            ['start' => 4473.75, 'end' => 4475.18, 'word' => ' greater'],
            ['start' => 4475.18, 'end' => 4476.26, 'word' => ' thing.'],
            ['start' => 4476.74, 'end' => 4481.70, 'word' => " You're my joy, my righteousness,"],
            ['start' => 4482.46, 'end' => 4487.53, 'word' => ' and I love you all, love you'],
            ['start' => 4487.53, 'end' => 4489.10, 'word' => ' all.'],
            ['start' => 4495.96, 'end' => 4496.08, 'word' => ' Lord'],
            ['start' => 4496.10, 'end' => 4496.50, 'word' => ' God,'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(4495.9, $plan['segments'][0]['end_time'], 0.1);
        $this->assertLessThanOrEqual(4495.96, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_speech', $plan['cue_edge_widening'][array_key_last($plan['cue_edge_widening'])]['reason']);
    }

    /** 1028 §1398: the held "me" fades to digital silence; whisper hears "Thank you." over it. */
    #[Test]
    public function a_song_runs_on_through_a_held_note_until_silence(): void
    {
        $last = ['start' => 1928.14, 'end' => 1932.52, 'text' => 'all His love to die for me.'];
        $hallucinated = ['start' => 1935.80, 'end' => 1965.78, 'text' => 'Thank you.'];
        $section = $this->song(1753.4, 1932.52, [$last, $hallucinated], [[1750, 1937.5, self::LOUD], [1937.5, 1970, self::SILENT]]);
        $this->bank($section, 1932.52, [
            ['start' => 1930.46, 'end' => 1931.06, 'word' => ' to'],
            ['start' => 1931.06, 'end' => 1931.97, 'word' => ' die'],
            ['start' => 1931.97, 'end' => 1932.88, 'word' => ' for'],
            ['start' => 1932.88, 'end' => 1933.51, 'word' => ' me?'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(1937.5, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(1938.5, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_silence', $plan['cue_edge_widening'][array_key_last($plan['cue_edge_widening'])]['reason']);
    }

    /**
     * 1250 §3131: the largest pause near the old edge came before the last line ("in the name of
     * my Lord"), so the cut dropped it. A song end never cuts back into its own singing.
     */
    #[Test]
    public function a_song_end_never_falls_before_its_last_sung_line(): void
    {
        $line = ['start' => 1725.74, 'end' => 1730.70, 'text' => "God, I'll serve all our miracles"];
        $last = ['start' => 1730.70, 'end' => 1734.74, 'text' => 'in the name of my Lord.'];
        $reading = ['start' => 1736.74, 'end' => 1742.33, 'text' => 'Marlene is going to come and do the next reading for us,'];
        $section = $this->song(1565.52, 1734.74, [$line, $last, $reading], [[1560, 1760, self::LOUD]]);
        $this->bank($section, 1734.74, [
            ['start' => 1726.0, 'end' => 1726.86, 'word' => ' miracles'],
            ['start' => 1729.86, 'end' => 1730.60, 'word' => ' The'],
            ['start' => 1730.60, 'end' => 1731.80, 'word' => ' Lord'],
            ['start' => 1731.80, 'end' => 1733.60, 'word' => ' is in my'],
            ['start' => 1733.60, 'end' => 1735.73, 'word' => ' home.'],
        ]);
        $this->bank($section, 1736.74, [
            ['start' => 1735.73, 'end' => 1735.73, 'word' => ' home.'],
            ['start' => 1736.80, 'end' => 1737.30, 'word' => ' Marlene'],
            ['start' => 1737.30, 'end' => 1738.0, 'word' => ' is going to'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(1735.73, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(1736.80, $plan['segments'][0]['end_time']);
    }

    /** 1304 §4884: the benediction starts as the singing stops; it belongs to no song. */
    #[Test]
    public function a_song_ends_before_the_words_that_follow_it_even_when_they_start_inside_the_section(): void
    {
        $last = ['start' => 3888.20, 'end' => 3893.88, 'text' => 'For the nations, Jesus saves.'];
        $benediction = ['start' => 3897.74, 'end' => 3901.36, 'text' => 'And now may the grace of the Lord Jesus Christ'];
        $section = $this->song(3706.01, 3897.92, [$last, $benediction], [[3700, 3920, self::LOUD]]);
        $words = [
            ['start' => 3896.90, 'end' => 3898.48, 'word' => ' and'],
            ['start' => 3898.53, 'end' => 3898.82, 'word' => ' now'],
            ['start' => 3898.82, 'end' => 3899.06, 'word' => ' may'],
            ['start' => 3899.06, 'end' => 3899.60, 'word' => ' the grace'],
        ];
        $this->bank($section, 3897.92, $words);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertLessThanOrEqual(3896.90, $plan['segments'][0]['end_time']);
        $this->assertGreaterThanOrEqual(3893.88, $plan['segments'][0]['end_time']);
    }

    /** 1311 §4971 (ruled right): speech begins at the section end, so the cut stays before it. */
    #[Test]
    public function a_song_followed_at_once_by_speech_keeps_its_word_pause_cut(): void
    {
        $last = ['start' => 2367.84, 'end' => 2373.84, 'text' => 'With Christ my Savior and my God.'];
        $speech = ['start' => 2373.84, 'end' => 2375.84, 'text' => 'Well that was a great hymn.'];
        $section = $this->song(2200.0, 2373.84, [$last, $speech], [[2190, 2400, self::LOUD]]);
        $this->bank($section, 2373.84, [
            ['start' => 2366.99, 'end' => 2369.43, 'word' => ' Well,'],
            ['start' => 2369.43, 'end' => 2372.46, 'word' => ' that was'],
            ['start' => 2372.46, 'end' => 2372.89, 'word' => ' a'],
            ['start' => 2373.27, 'end' => 2375.06, 'word' => ' great'],
            ['start' => 2375.06, 'end' => 2376.83, 'word' => ' hymn.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(2373.27, $plan['segments'][0]['end_time'], 0.15);
    }

    /** The rule is the song's: a talk ending at the same shape keeps the word-pause cut. */
    #[Test]
    public function only_song_ends_run_on(): void
    {
        $last = ['start' => 1928.14, 'end' => 1932.52, 'text' => 'that is all I want to say.'];
        $next = ['start' => 1945.0, 'end' => 1950.0, 'text' => 'Let us pray.'];
        $section = $this->song(1753.4, 1932.52, [$last, $next], [[1750, 1970, self::LOUD]], ServiceSectionType::ShortTalk);
        $this->bank($section, 1932.52, [
            ['start' => 1930.0, 'end' => 1932.40, 'word' => ' all I want to say.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertLessThan(1934.0, $plan['segments'][0]['end_time']);
    }

    /** The speech that ends a song needs its own word timings; the edge step must decode them. */
    #[Test]
    public function the_cue_that_ends_a_song_is_an_edge_the_word_timings_cover(): void
    {
        $last = ['start' => 1311.86, 'end' => 1317.84, 'text' => 'But since our baby is mercy is born'];
        $sit = ['start' => 1317.84, 'end' => 1322.40, 'text' => 'Do you please sit down?'];
        $talk = ['start' => 1324.58, 'end' => 1326.48, 'text' => "Well, it wasn't that long ago"];
        $section = $this->song(1200.0, 1322.40, [$last, $sit, $talk], [[1190, 1340, self::LOUD]]);

        $this->assertSame([1324.58], app(CueSafeExtractionPlan::class)->songEndSpeechEdges($section->processingLog, [['start_time' => 1200.0, 'end_time' => 1322.40]]));
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{0: float|int, 1: float|int, 2: float}>  $levels
     */
    private function song(float $start, float $end, array $cues, array $levels, ServiceSectionType $type = ServiceSectionType::Song): ServiceSection
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'rms_log_path' => 'service-transcripts/song-end.rms.log']);
        $log->putServiceTranscriptPath('temp/song-end.json');
        Storage::disk('local')->put('temp/song-end.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        Storage::disk('local')->put('service-transcripts/song-end.rms.log', $this->rmsLog($levels));

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => $type,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /** @param list<array{start: float, end: float, word: string}> $words */
    private function bank(ServiceSection $section, float $edge, array $words): void
    {
        $log = $section->processingLog->fresh();
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->window($evidence->cues($log), $edge, $log->duration);
        $this->assertNotNull($window);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
            'identity' => $evidence->identity($log, $window), 'words' => $words, 'compute_seconds' => 1.0,
        ]);
        $section->unsetRelation('processingLog');
    }

    /** @param list<array{0: float|int, 1: float|int, 2: float}> $levels */
    private function rmsLog(array $levels): string
    {
        $lines = [];
        $from = (int) floor(min(array_column($levels, 0)) * 10);
        $to = (int) ceil(max(array_column($levels, 1)) * 10);

        for ($tenth = $from; $tenth < $to; $tenth++) {
            $time = $tenth / 10;
            $level = self::SILENT;

            foreach ($levels as [$start, $end, $value]) {
                if ($time >= $start && $time < $end) {
                    $level = $value;
                }
            }

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
        }

        return implode("\n", $lines)."\n";
    }
}
