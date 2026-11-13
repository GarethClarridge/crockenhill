<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeAnswers;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PublicationPlanValidator;
use App\Services\ChurchService\SongEndOutro;
use App\Services\ChurchService\SongEndSpeechOnset;
use App\Services\ChurchService\SongEndStems;
use App\Services\ChurchService\SongTailSpeechOnset;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AudioTimelineFixture;
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

    private const MUSIC = [0.7, 0.02];

    private const SPEECH = [0.01, 0.8];

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
        // The classifier windows are run 1250's: music to 4495, music and speech, then speech.
        $section = $this->song(4300.0, 4475.48, [$last, $prayer], [[4300, 4495.9, self::LOUD], [4495.9, 4510, -25.0]],
            timeline: [[4300, 4495, 0.8, 0.01], [4495, 4500, 0.55, 0.82], [4500, 4510, 0.01, 0.88]]);
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
        // Operator, 2026-10-07: every song end at speech fades its sound over the gap from the
        // last sung sound, 1.5–4 s. The outro here is longer: only its last 4 s fade.
        $this->assertEqualsWithDelta(4.0, $plan['audio_fade_out'], 0.001);
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
        $this->assertNull($plan['audio_fade_out'], 'a song that ends in silence is cut cleanly');
    }

    /**
     * 1335's sermon opened on 12 s of "Rock of Ages" outro: a spoken part starting where a song's
     * section ends starts no earlier than the song's sound stops (operator listening, 2026-10-11).
     */
    #[Test]
    public function a_spoken_part_after_a_song_starts_once_the_songs_sound_stops(): void
    {
        $last = ['start' => 1928.14, 'end' => 1932.52, 'text' => 'all His love to die for me.'];
        $hallucinated = ['start' => 1935.80, 'end' => 1965.78, 'text' => 'Thank you.'];
        $song = $this->song(1753.4, 1932.52, [$last, $hallucinated], [[1750, 1937.5, self::LOUD], [1937.5, 1970, self::SILENT]]);
        $this->bank($song, 1932.52, [
            ['start' => 1930.46, 'end' => 1931.06, 'word' => ' to'],
            ['start' => 1931.06, 'end' => 1931.97, 'word' => ' die'],
            ['start' => 1931.97, 'end' => 1932.88, 'word' => ' for'],
            ['start' => 1932.88, 'end' => 1933.51, 'word' => ' me?'],
        ]);
        $talk = ServiceSection::factory()->create(['media_processing_log_id' => $song->media_processing_log_id,
            'section_type' => ServiceSectionType::ShortTalk, 'start_time' => 1932.52, 'end_time' => 2100.0]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($talk->fresh());

        $this->assertGreaterThanOrEqual(1937.5, $plan['segments'][0]['start_time']);
        $this->assertLessThanOrEqual(1938.5, $plan['segments'][0]['start_time']);
        $start = collect($plan['cue_edge_widening'])->first(static fn (array $entry): bool => $entry['edge'] === 'start');
        $this->assertSame($song->id, $start['after_song_sound']['section_id']);
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
        $this->bank($section, 3897.74, $words);

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
     * Canary 12, 1028 §1400: the benediction's cue is stretched back 25 s over the last verse, so
     * the speech "after" the song started at "fill you", half a line in. The classifier hears the
     * singing stop at 4295, the decode hears "May the peace" at 4299.41, and the level is silent
     * between: the song ends at that silence, inside its section.
     */
    #[Test]
    public function a_benediction_starting_inside_the_song_section_is_left_out_of_it(): void
    {
        $sung = ['start' => 4271.24, 'end' => 4273.92, 'text' => 'My creation'];
        $benediction = ['start' => 4273.92, 'end' => 4303.18, 'text' => 'May the peace of God, sorry, may the, may the God of hope'];
        $next = ['start' => 4303.18, 'end' => 4306.14, 'text' => 'fill you with all joy and peace in believing,'];
        $section = $this->song(4115.0, 4303.18, [$sung, $benediction, $next],
            [[4100, 4297.4, self::LOUD], [4297.4, 4299.7, self::SILENT], [4299.7, 4320, -32.0]],
            timeline: [[4100, 4295, ...self::MUSIC], [4295, 4300, 0.54, 0.62], [4300, 4320, ...self::SPEECH]]);
        $this->bank($section, 4303.18, [
            ['start' => 4287.99, 'end' => 4288.76, 'word' => ' and send'],
            ['start' => 4289.13, 'end' => 4289.87, 'word' => ' free'],
            ['start' => 4291.73, 'end' => 4292.29, 'word' => ' him'],
            ['start' => 4292.29, 'end' => 4294.20, 'word' => ' rejoice.'],
            ['start' => 4299.41, 'end' => 4299.52, 'word' => ' May'],
            ['start' => 4299.52, 'end' => 4299.78, 'word' => ' the'],
            ['start' => 4299.78, 'end' => 4300.21, 'word' => ' peace'],
            ['start' => 4300.21, 'end' => 4300.38, 'word' => ' of'],
            ['start' => 4300.38, 'end' => 4300.66, 'word' => ' God,'],
            ['start' => 4302.49, 'end' => 4302.71, 'word' => ' of'],
            ['start' => 4302.71, 'end' => 4303.20, 'word' => ' hope'],
            ['start' => 4303.20, 'end' => 4303.62, 'word' => ' fill'],
            ['start' => 4303.62, 'end' => 4303.94, 'word' => ' you'],
            ['start' => 4303.94, 'end' => 4304.28, 'word' => ' with'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(4294.20, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(4299.41, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * Canary 12, 1108 §4948: "Let me close the benediction" is inside the song's section, its
     * words smeared over the outro ("close" spans 7 s). Smeared words are not sound the song
     * makes, so the silence before the speech (4288.7–4290.1) ends it.
     */
    #[Test]
    public function smeared_words_do_not_carry_a_song_past_the_silence_before_the_speech(): void
    {
        $sung = ['start' => 4271.58, 'end' => 4276.58, 'text' => 'His wounds have made my answer.'];
        $intro = ['start' => 4276.58, 'end' => 4292.58, 'text' => 'May I close the benediction.'];
        $benediction = ['start' => 4292.58, 'end' => 4295.58, 'text' => 'May the grace of the Lord Jesus Christ'];
        $section = $this->song(4122.57, 4292.58, [$sung, $intro, $benediction],
            [[4100, 4288.7, self::LOUD], [4288.7, 4290.1, self::SILENT], [4290.1, 4290.6, -44.0], [4290.6, 4291.3, self::SILENT],
                [4291.3, 4291.6, -44.0], [4291.6, 4293.4, self::SILENT], [4293.4, 4300, -35.0]],
            timeline: [[4100, 4290, ...self::MUSIC], [4290, 4300, ...self::SPEECH]]);
        $this->bank($section, 4292.58, [
            ['start' => 4275.72, 'end' => 4280.09, 'word' => ' Let'],
            ['start' => 4280.09, 'end' => 4282.51, 'word' => ' me'],
            ['start' => 4283.09, 'end' => 4290.52, 'word' => ' close'],
            ['start' => 4290.52, 'end' => 4290.65, 'word' => ' the'],
            ['start' => 4291.14, 'end' => 4291.94, 'word' => ' benediction.'],
            ['start' => 4292.22, 'end' => 4292.46, 'word' => ' In'],
            ['start' => 4292.46, 'end' => 4292.82, 'word' => ' the'],
            ['start' => 4293.36, 'end' => 4293.42, 'word' => ' grace'],
            ['start' => 4293.42, 'end' => 4293.66, 'word' => ' of'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(4288.7, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(4290.1, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * Canary 12, 1108 §1896: "Thank you, Aled." is one 15 s cue ending at the section's end, its
     * words smeared over the silence after the singing. The song ends at that silence.
     */
    #[Test]
    public function a_thank_you_inside_the_song_section_is_left_out_of_it(): void
    {
        $sung = ['start' => 886.06, 'end' => 891.46, 'text' => 'and sins are ready, his mercy is all.'];
        $thanks = ['start' => 891.46, 'end' => 906.74, 'text' => 'Thank you, Aled.'];
        $talk = ['start' => 908.60, 'end' => 912.91, 'text' => 'Well, the Heidelberg Catechism is focusing our attention'];
        $section = $this->song(729.35, 906.74, [$sung, $thanks, $talk],
            [[700, 893.0, self::LOUD], [893.0, 895.1, self::SILENT], [895.1, 895.4, -45.0], [895.4, 905.6, self::SILENT],
                [905.6, 907.3, -35.0], [907.3, 907.7, self::SILENT], [907.7, 920, -35.0]],
            timeline: [[700, 895, ...self::MUSIC], [895, 905, 0.05, 0.05], [905, 920, ...self::SPEECH]]);
        $this->bank($section, 906.74, [
            ['start' => 890.58, 'end' => 900.44, 'word' => ' Thank'],
            ['start' => 900.44, 'end' => 906.44, 'word' => ' you,'],
            ['start' => 906.88, 'end' => 907.73, 'word' => ' Aled.'],
        ]);
        $this->bank($section, 908.60, [
            ['start' => 907.76, 'end' => 908.14, 'word' => ' Well,'],
            ['start' => 908.63, 'end' => 908.86, 'word' => ' the'],
            ['start' => 909.10, 'end' => 909.84, 'word' => ' Heidelberg'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(893.0, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(905.6, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * Canary 12, 964 §4965: the prayer's first line ("…that truly is our prayer") starts inside
     * the song's section with no silence before it. The cut goes in the gap before its first
     * word, after the last sung "Lord".
     */
    #[Test]
    public function a_prayer_line_starting_inside_the_song_section_is_cut_before_its_first_word(): void
    {
        $sung = ['start' => 3928.12, 'end' => 3929.68, 'text' => 'where they are.'];
        $prayer = ['start' => 3929.68, 'end' => 3940.68, 'text' => 'And that truly is our prayer to you this morning.'];
        $next = ['start' => 3942.68, 'end' => 3947.71, 'text' => 'That the light of the Lord Jesus might shine throughout the'];
        $section = $this->song(3727.22, 3937.49, [$sung, $prayer, $next],
            [[3700, 3960, self::LOUD]],
            timeline: [[3700, 3935, ...self::MUSIC], [3935, 3940, 0.61, 0.69], [3940, 3960, ...self::SPEECH]]);
        $this->bank($section, 3937.49, [
            ['start' => 3931.82, 'end' => 3932.68, 'word' => ' Lord,'],
            ['start' => 3932.87, 'end' => 3933.17, 'word' => ' and'],
            ['start' => 3933.27, 'end' => 3933.68, 'word' => ' Lord,'],
            ['start' => 3934.25, 'end' => 3934.94, 'word' => ' Lord.'],
            ['start' => 3936.26, 'end' => 3936.26, 'word' => ' That'],
            ['start' => 3936.26, 'end' => 3936.40, 'word' => ' truly'],
            ['start' => 3936.40, 'end' => 3936.53, 'word' => ' is'],
            ['start' => 3936.53, 'end' => 3936.96, 'word' => ' our'],
            ['start' => 3936.96, 'end' => 3937.83, 'word' => ' prayer'],
            ['start' => 3940.13, 'end' => 3940.62, 'word' => ' Amen.'],
        ]);
        $this->bank($section, 3942.68, [
            ['start' => 3941.82, 'end' => 3942.76, 'word' => ' that'],
            ['start' => 3943.32, 'end' => 3943.58, 'word' => ' the'],
            ['start' => 3943.58, 'end' => 3943.90, 'word' => ' light'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(3934.94, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(3936.26, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_speech', $this->endEdge($plan)['reason']);
    }

    /**
     * Canary 12, 1311 §4972: the benediction after "In Christ Alone" has no cue at all; the next
     * cue is 27 s later. The classifier hears speech from 4145, but with no words the onset is not
     * established: the cut goes in the first quiet after the singing, and the edge is unresolved.
     */
    #[Test]
    public function speech_with_no_words_after_the_singing_fades_the_song_out_at_the_first_quiet(): void
    {
        $sung = ['start' => 4140.0, 'end' => 4145.64, 'text' => 'Here in the power of Christ I stand.'];
        $next = ['start' => 4172.84, 'end' => 4177.34, 'text' => 'To the only God, our Saviour, be glory, majesty, power and'];
        $section = $this->song(3944.07, 4145.64, [$sung, $next],
            [[3900, 4145.8, self::LOUD], [4145.8, 4146.1, self::SILENT], [4146.1, 4146.15, -40.0], [4146.15, 4146.6, self::SILENT],
                [4146.6, 4160.9, -30.0], [4160.9, 4162.2, self::SILENT], [4162.2, 4180, -30.0]],
            timeline: [[3900, 4145, ...self::MUSIC], [4145, 4180, 0.21, 0.68]]);
        $this->bank($section, 4145.64, [
            ['start' => 4141.0, 'end' => 4141.9, 'word' => ' power'],
            ['start' => 4143.6, 'end' => 4144.4, 'word' => ' I'],
            ['start' => 4144.4, 'end' => 4145.5, 'word' => ' stand.'],
        ]);
        $this->bank($section, 4172.84, [
            ['start' => 4171.98, 'end' => 4172.54, 'word' => ' joy.'],
            ['start' => 4172.89, 'end' => 4173.31, 'word' => ' To'],
            ['start' => 4173.59, 'end' => 4173.78, 'word' => ' the'],
            ['start' => 4173.78, 'end' => 4173.98, 'word' => ' only'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(4145.64, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(4146.6, $plan['segments'][0]['end_time']);
        $this->assertSame(CueSafeExtractionPlan::SONG_END_FADE, $this->endEdge($plan)['reason']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']), 'a fade makes the edge deliberate: nothing to ask');
        $this->assertEqualsWithDelta(1.5, $plan['audio_fade_out'], 0.001, 'the gap is shorter than the minimum fade');
    }

    /**
     * 1291 §3643: the reading after "The Lord's My Shepherd" opened on the song's last note, held
     * through a gap the decode heard as a pause between words ("This is the end of the song",
     * 10-19; "Should be about 18:45", 10-29). A song that fades out before known speech has stopped
     * sounding at its cut, as one ending in silence or at speech has.
     */
    #[Test]
    public function a_spoken_part_after_a_song_that_fades_starts_at_the_songs_cut(): void
    {
        $sung = ['start' => 4140.0, 'end' => 4145.64, 'text' => 'Here in the power of Christ I stand.'];
        $next = ['start' => 4172.84, 'end' => 4177.34, 'text' => 'To the only God, our Saviour, be glory, majesty, power and'];
        $song = $this->song(3944.07, 4145.64, [$sung, $next],
            [[3900, 4145.8, self::LOUD], [4145.8, 4146.1, self::SILENT], [4146.1, 4146.15, -40.0], [4146.15, 4146.6, self::SILENT],
                [4146.6, 4160.9, -30.0], [4160.9, 4162.2, self::SILENT], [4162.2, 4180, -30.0]],
            timeline: [[3900, 4145, ...self::MUSIC], [4145, 4180, 0.21, 0.68]]);
        $this->bank($song, 4145.64, [
            ['start' => 4141.0, 'end' => 4141.9, 'word' => ' power'],
            ['start' => 4143.6, 'end' => 4144.4, 'word' => ' I'],
            ['start' => 4144.4, 'end' => 4145.5, 'word' => ' stand.'],
        ]);
        $this->bank($song, 4172.84, [
            ['start' => 4171.98, 'end' => 4172.54, 'word' => ' joy.'],
            ['start' => 4172.89, 'end' => 4173.31, 'word' => ' To'],
            ['start' => 4173.59, 'end' => 4173.78, 'word' => ' the'],
            ['start' => 4173.78, 'end' => 4173.98, 'word' => ' only'],
        ]);
        $songCut = app(CueSafeExtractionPlan::class)->forSection($song->fresh())['segments'][0]['end_time'];
        $talk = ServiceSection::factory()->create(['media_processing_log_id' => $song->media_processing_log_id,
            'section_type' => ServiceSectionType::ShortTalk, 'start_time' => 4145.64, 'end_time' => 4300.0]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($talk->fresh());

        $this->assertGreaterThan(4145.64 + 0.05, $songCut);
        $this->assertEqualsWithDelta($songCut, $plan['segments'][0]['start_time'], 0.001);
        $start = collect($plan['cue_edge_widening'])->first(static fn (array $entry): bool => $entry['edge'] === 'start');
        $this->assertSame(['section_id' => $song->id, 'reason' => CueSafeExtractionPlan::SONG_END_FADE],
            array_intersect_key($start['after_song_sound'], ['section_id' => true, 'reason' => true]));
    }

    /**
     * 1250 §3131: "Marlene" is decoded over 4.4 s of the song's fading music, so the reading
     * started at the song's cut on 3.5 s of music ("Both have music. Should be more like 28:59",
     * 10-29). The song's own cut is right (canary 13); the reading starts where its first word was
     * said, a spoken word's length before the next word heard at speaking pace.
     */
    #[Test]
    public function a_spoken_part_whose_first_word_is_smeared_over_the_songs_music_starts_where_it_was_said(): void
    {
        $sung = ['start' => 1730.70, 'end' => 1734.74, 'text' => 'in the name of my Lord.'];
        $speech = ['start' => 1735.74, 'end' => 1742.33, 'text' => 'Marlene is going to come and do the next reading for us,'];
        $song = $this->song(1500.0, 1734.74, [$sung, $speech], [[1500, 1760, self::LOUD]],
            timeline: [[1500, 1735, 0.8, 0.0], [1735, 1740, 0.6, 0.7], [1740, 1760, 0.0, 0.7]]);
        $words = [
            ['start' => 1729.86, 'end' => 1730.90, 'word' => " It's"],
            ['start' => 1730.90, 'end' => 1731.20, 'word' => ' a'],
            ['start' => 1731.20, 'end' => 1733.00, 'word' => ' gift,'],
            ['start' => 1733.00, 'end' => 1733.60, 'word' => ' my'],
            ['start' => 1733.60, 'end' => 1734.86, 'word' => ' Lord.'],
            ['start' => 1734.86, 'end' => 1739.30, 'word' => ' Marlene'],
            ['start' => 1739.30, 'end' => 1739.42, 'word' => ' is'],
            ['start' => 1739.42, 'end' => 1739.72, 'word' => ' going'],
            ['start' => 1739.72, 'end' => 1739.83, 'word' => ' to'],
            ['start' => 1739.94, 'end' => 1740.10, 'word' => ' come'],
        ];
        $this->bank($song, 1734.74, $words);
        $this->bank($song, 1735.74, $words);
        $songCut = app(CueSafeExtractionPlan::class)->forSection($song->fresh())['segments'][0]['end_time'];
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $song->media_processing_log_id,
            'section_type' => ServiceSectionType::BibleReading, 'start_time' => 1734.74, 'end_time' => 1900.0]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($reading->fresh());

        $this->assertLessThan(1736.0, $songCut, 'the song keeps its own cut');
        $this->assertEqualsWithDelta(1738.97, $plan['segments'][0]['start_time'], 0.01);
        $start = collect($plan['cue_edge_widening'])->first(static fn (array $entry): bool => $entry['edge'] === 'start');
        $this->assertSame(['heard' => 'is', 'smeared' => 1], array_intersect_key($start['after_song_sound']['smeared_opening'], ['heard' => true, 'smeared' => true]));
    }

    /**
     * Silence after a song already says where the speech can start: a smeared first word moves
     * only a start after a fade, the case measured and heard (10-29). The same smear after
     * silence moved nine unheard talk starts by up to 7 s, so it is not followed there.
     */
    #[Test]
    public function a_smeared_first_word_after_a_song_that_ends_in_silence_does_not_move_the_start(): void
    {
        $sung = ['start' => 1730.70, 'end' => 1734.74, 'text' => 'in the name of my Lord.'];
        $speech = ['start' => 1735.74, 'end' => 1742.33, 'text' => 'Marlene is going to come and do the next reading for us,'];
        $song = $this->song(1500.0, 1734.74, [$sung, $speech], [[1500, 1735.0, self::LOUD], [1735.0, 1736.6, self::SILENT], [1736.6, 1760, self::LOUD]],
            timeline: [[1500, 1735, 0.8, 0.0], [1735, 1740, 0.6, 0.7], [1740, 1760, 0.0, 0.7]]);
        $words = [
            ['start' => 1733.00, 'end' => 1733.60, 'word' => ' my'],
            ['start' => 1733.60, 'end' => 1734.86, 'word' => ' Lord.'],
            ['start' => 1734.86, 'end' => 1739.30, 'word' => ' Marlene'],
            ['start' => 1739.30, 'end' => 1739.42, 'word' => ' is'],
            ['start' => 1739.42, 'end' => 1739.72, 'word' => ' going'],
        ];
        $this->bank($song, 1734.74, $words);
        $this->bank($song, 1735.74, $words);
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $song->media_processing_log_id,
            'section_type' => ServiceSectionType::BibleReading, 'start_time' => 1734.74, 'end_time' => 1900.0]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($reading->fresh());

        $start = collect($plan['cue_edge_widening'])->first(static fn (array $entry): bool => $entry['edge'] === 'start');
        $this->assertSame('song_end_silence', $start['after_song_sound']['reason']);
        $this->assertArrayNotHasKey('smeared_opening', $start['after_song_sound']);
        $this->assertLessThan(1737.0, $plan['segments'][0]['start_time']);
    }

    /**
     * Canary 12, 1028 §1392 (B1): "Aled," is not decoded, so the cue's opening is not heard. The
     * largest pause in reach (562.67–563.72) sat inside "…up on the | screen"; the silence before
     * the first decoded word ends the song instead.
     */
    #[Test]
    public function a_song_ends_at_the_silence_before_the_first_heard_word_not_at_a_later_longer_pause(): void
    {
        $sung = ['start' => 549.75, 'end' => 551.08, 'text' => 'the Lord.'];
        $talk = ['start' => 559.08, 'end' => 564.18, 'text' => 'Aled, can we have my first slide, please, up on the screen?'];
        $section = $this->song(387.20, 555.01, [$sung, $talk],
            [[380, 553.1, self::LOUD], [553.1, 559.4, self::SILENT], [559.4, 562.7, -30.0], [562.7, 563.8, self::SILENT], [563.8, 570, -30.0]]);
        $this->bank($section, 559.08, [
            ['start' => 558.08, 'end' => 558.69, 'word' => ' Can'],
            ['start' => 558.82, 'end' => 559.08, 'word' => ' we'],
            ['start' => 559.46, 'end' => 559.90, 'word' => ' have'],
            ['start' => 559.90, 'end' => 560.32, 'word' => ' my'],
            ['start' => 560.32, 'end' => 560.68, 'word' => ' first'],
            ['start' => 560.68, 'end' => 561.12, 'word' => ' slide'],
            ['start' => 561.12, 'end' => 562.08, 'word' => ' please'],
            ['start' => 562.08, 'end' => 562.23, 'word' => ' up'],
            ['start' => 562.33, 'end' => 562.52, 'word' => ' on'],
            ['start' => 562.52, 'end' => 562.67, 'word' => ' the'],
            ['start' => 563.72, 'end' => 565.14, 'word' => ' screen?'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertLessThanOrEqual(558.08, $plan['segments'][0]['end_time']);
        $this->assertGreaterThanOrEqual(555.01, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * Canary 12, 1311 §4875 (B1): the decode lost "And Naomi I'm going to put the same questions
     * to you" (one 10 s smeared "Do"). The largest pause then sat after "…to you", ten seconds in.
     * Without the opening words the onset is not established: the cut stays before the cue, and
     * the edge is unresolved rather than repaired.
     */
    #[Test]
    public function a_song_end_whose_speech_opening_is_not_heard_stays_before_the_cue_and_fades(): void
    {
        $sung = ['start' => 1574.10, 'end' => 1576.10, 'text' => 'Hallelujah.'];
        $talk = ['start' => 1584.13, 'end' => 1593.26, 'text' => "And Naomi I'm going to put the same questions to you Do you believe in God the Father I do"];
        $section = $this->song(1514.10, 1576.10, [$sung, $talk], [[1500, 1610, self::LOUD]]);
        $this->bank($section, 1576.10, [
            ['start' => 1573.24, 'end' => 1577.09, 'word' => ' Hallelujah.'],
        ]);
        $this->bank($section, 1584.13, [
            ['start' => 1583.27, 'end' => 1593.85, 'word' => ' Do'],
            ['start' => 1594.12, 'end' => 1594.25, 'word' => ' you'],
            ['start' => 1594.25, 'end' => 1594.25, 'word' => ' believe'],
            ['start' => 1594.25, 'end' => 1594.25, 'word' => ' in'],
            ['start' => 1594.25, 'end' => 1594.25, 'word' => ' God'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertLessThanOrEqual(1584.13, $plan['segments'][0]['end_time']);
        $this->assertGreaterThanOrEqual(1577.09, $plan['segments'][0]['end_time']);
        $this->assertSame(CueSafeExtractionPlan::SONG_END_FADE, $this->endEdge($plan)['reason']);
        $fade = $plan['audio_fade_out'];
        $this->assertGreaterThanOrEqual(1.5, $fade);
        $this->assertLessThanOrEqual(4.0, $fade);
        $this->assertGreaterThanOrEqual(1577.09, $plan['segments'][0]['end_time'] - $fade - 0.001, 'the fade starts after the last sung sound');
    }

    /**
     * Canary 12, 1025 §1373 and 1304 §3870 (B2): a 30 s filler cue ("Thank you.", "The End") over
     * the end of the song hides speech the decode hears ("Well, he rescues us…"). The filler is not
     * where speech is; the song ends at the silence before the heard words.
     */
    #[Test]
    public function a_long_filler_cue_does_not_hide_the_speech_that_ends_a_song(): void
    {
        $sung = ['start' => 570.80, 'end' => 600.78, 'text' => 'Thank you.'];
        $filler = ['start' => 600.80, 'end' => 630.78, 'text' => 'Thank you.'];
        $prayer = ['start' => 630.80, 'end' => 637.02, 'text' => 'Father, we do come to you this morning, very conscious,'];
        $section = $this->song(434.98, 630.78, [$sung, $filler, $prayer],
            [[400, 605.8, self::LOUD], [605.8, 614.4, self::SILENT], [614.4, 625.4, -30.0], [625.4, 632.6, self::SILENT], [632.6, 640, -30.0]],
            timeline: [[400, 610, ...self::MUSIC], [610, 640, 0.07, 0.6]]);
        $this->bank($section, 630.78, [
            ['start' => 601.50, 'end' => 602.52, 'word' => ' Master'],
            ['start' => 603.88, 'end' => 604.94, 'word' => ' praise.'],
            ['start' => 613.84, 'end' => 613.84, 'word' => ' Well,'],
            ['start' => 613.98, 'end' => 614.03, 'word' => ' from'],
            ['start' => 614.03, 'end' => 615.33, 'word' => ' all'],
            ['start' => 621.76, 'end' => 621.91, 'word' => " Let's"],
            ['start' => 621.91, 'end' => 622.17, 'word' => ' join'],
            ['start' => 625.13, 'end' => 625.42, 'word' => " Let's pray."],
            ['start' => 629.94, 'end' => 635.80, 'word' => ' Thank'],
        ]);
        $this->bank($section, 630.80, [
            ['start' => 632.15, 'end' => 632.92, 'word' => ' Father,'],
            ['start' => 632.92, 'end' => 633.16, 'word' => ' we'],
            ['start' => 633.16, 'end' => 633.40, 'word' => ' do'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(604.94, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(613.84, $plan['segments'][0]['end_time']);
    }

    /** Canary 12 (B2): a cue of punctuation alone is not the speech that ends a song. */
    #[Test]
    public function a_punctuation_only_cue_is_not_the_speech_that_ends_a_song(): void
    {
        $last = ['start' => 990.0, 'end' => 999.0, 'text' => 'and I will sing of the Lamb.'];
        $dots = ['start' => 1000.5, 'end' => 1003.0, 'text' => '. . . .'];
        $talk = ['start' => 1010.0, 'end' => 1013.0, 'text' => 'Let us pray together.'];
        $section = $this->song(800.0, 1000.0, [$last, $dots, $talk], [[790, 1030, self::LOUD]]);

        $this->assertSame([1010.0], app(CueSafeExtractionPlan::class)->songEndSpeechEdges($section->processingLog, [['start_time' => 800.0, 'end_time' => 1000.0]]));
    }

    /**
     * Counterexample (B3): a final sung line the classifier half-hears as speech, followed by the
     * instrumental outro, is the song's. Speech inside the section counts only once the music has
     * stopped for good; the song keeps its last line and outro and ends at the silence after.
     */
    #[Test]
    public function a_last_line_heard_as_speech_before_the_outro_stays_in_the_song(): void
    {
        $line = ['start' => 1980.0, 'end' => 1990.0, 'text' => "Let's pray to the Lord our God."];
        $talk = ['start' => 2002.0, 'end' => 2006.0, 'text' => 'Please be seated.'];
        $section = $this->song(1800.0, 1990.0, [$line, $talk],
            [[1790, 1998.0, self::LOUD], [1998.0, 2001.5, self::SILENT], [2001.5, 2010, -30.0]],
            timeline: [[1790, 1980, ...self::MUSIC], [1980, 1985, 0.55, 0.6], [1985, 1995, ...self::MUSIC], [1995, 2000, 0.05, 0.05], [2000, 2010, ...self::SPEECH]]);
        $this->bank($section, 1990.0, [
            ['start' => 1981.0, 'end' => 1981.4, 'word' => " Let's"],
            ['start' => 1981.5, 'end' => 1982.0, 'word' => ' pray'],
            ['start' => 1984.0, 'end' => 1985.5, 'word' => ' God.'],
        ]);
        $this->bank($section, 2002.0, [
            ['start' => 2002.1, 'end' => 2002.5, 'word' => ' Please'],
            ['start' => 2002.5, 'end' => 2002.7, 'word' => ' be'],
            ['start' => 2002.7, 'end' => 2003.2, 'word' => ' seated.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(1998.3, $plan['segments'][0]['end_time'], 0.05);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * Codex review: pace alone cannot tell speech from quick singing. A last line sung fast in a
     * window holding music as well as speech, with silence after it rather than speech, is sung:
     * a window holding both is speech only when speech alone follows it.
     */
    #[Test]
    public function quickly_sung_last_words_are_not_taken_for_speech(): void
    {
        $line = ['start' => 1978.0, 'end' => 1990.0, 'text' => 'Hallelujah, praise the Lord, amen.'];
        $talk = ['start' => 2002.0, 'end' => 2006.0, 'text' => 'Please be seated.'];
        $section = $this->song(1800.0, 1990.0, [$line, $talk],
            [[1790, 1986.0, self::LOUD], [1986.0, 2001.5, self::SILENT], [2001.5, 2010, -30.0]],
            timeline: [[1790, 1980, ...self::MUSIC], [1980, 1985, 0.6, 0.55], [1985, 2000, 0.05, 0.05], [2000, 2010, ...self::SPEECH]]);
        $this->bank($section, 1990.0, [
            ['start' => 1978.2, 'end' => 1979.6, 'word' => ' Hallelujah,'],
            ['start' => 1981.0, 'end' => 1981.4, 'word' => ' praise'],
            ['start' => 1981.4, 'end' => 1981.7, 'word' => ' the'],
            ['start' => 1981.7, 'end' => 1982.3, 'word' => ' Lord,'],
            ['start' => 1982.5, 'end' => 1983.2, 'word' => ' amen.'],
        ]);
        $this->bank($section, 2002.0, [
            ['start' => 2002.1, 'end' => 2002.5, 'word' => ' Please'],
            ['start' => 2002.5, 'end' => 2002.7, 'word' => ' be'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(1983.2, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(2002.1, $plan['segments'][0]['end_time']);
    }

    /**
     * Canary 13, 1282 §3527: two songs back to back. The next song's first line ("In happy
     * living") was taken for the speech after the first, so the end ran 17.5 s on and faded into
     * the next song. The next song's section starts at 940: this one ends by then.
     */
    #[Test]
    public function a_song_ends_by_the_start_of_the_next_song(): void
    {
        $last = ['start' => 928.44, 'end' => 930.12, 'text' => 'In you.'];
        $next = ['start' => 958.44, 'end' => 963.28, 'text' => 'In happy living'];
        $section = $this->song(844.68, 939.998, [$last, $next],
            [[900, 936.5, self::LOUD], [936.5, 942.0, -44.0], [942.0, 975, self::LOUD]],
            timeline: [[900, 935, ...self::MUSIC], [935, 940, 0.377, 0.004], [940, 975, ...self::MUSIC]]);
        ServiceSection::factory()->create(['media_processing_log_id' => $section->media_processing_log_id,
            'section_type' => ServiceSectionType::Song, 'start_time' => 940.0, 'end_time' => 1160.01]);
        $this->bank($section, 958.44, [
            ['start' => 957.57, 'end' => 958.78, 'word' => ' Jesus'],
            ['start' => 958.78, 'end' => 960.12, 'word' => ' comes'],
            ['start' => 960.12, 'end' => 960.65, 'word' => ' in'],
            ['start' => 960.65, 'end' => 963.07, 'word' => ' happiness'],
            ['start' => 963.08, 'end' => 964.27, 'word' => ' today.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertLessThanOrEqual(940.0, $plan['segments'][0]['end_time']);
    }

    /**
     * Canary 13, 1221 §2730: the benediction's 20 s cue is not where it starts, and its decode is
     * one smeared "And". The classifier hears music to 4200 and speech alone from 4200: speech is
     * already under way at the start of that window, so the song ends by then, not at its end
     * (4205), which kept "And now may the God of peace".
     */
    #[Test]
    public function a_window_of_speech_alone_bounds_the_song_at_its_start(): void
    {
        $sung = ['start' => 4177.04, 'end' => 4183.14, 'text' => "Let's talk to all the length of his glory,"];
        $benediction = ['start' => 4183.14, 'end' => 4203.74, 'text' => 'And now may the God of peace, who through the blood of the'];
        $section = $this->song(4032.86, 4183.14, [$sung, $benediction],
            [[4100, 4196.0, self::LOUD], [4196.0, 4200.5, -40.0], [4200.5, 4215, -25.0]],
            timeline: [[4100, 4200, ...self::MUSIC], [4200, 4215, 0.009, 0.792]]);
        $this->bank($section, 4183.14, [
            ['start' => 4176.17, 'end' => 4204.44, 'word' => ' And'],
            ['start' => 4204.73, 'end' => 4204.73, 'word' => ' now'],
            ['start' => 4204.73, 'end' => 4204.73, 'word' => ' may'],
            ['start' => 4204.73, 'end' => 4204.73, 'word' => ' the'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertLessThanOrEqual(4200.0, $plan['segments'][0]['end_time']);
        $this->assertGreaterThanOrEqual(4195.0, $plan['segments'][0]['end_time']);
    }

    /** 1008 §1265 (heard 11-10): two short bursts after digital silence are noise, not an outro. */
    #[Test]
    public function short_noise_bursts_in_a_music_window_do_not_extend_a_song(): void
    {
        $section = $this->song(1210.0, 1385.8, [],
            [[1370, 1386.4, self::LOUD], [1391.5, 1392.1, self::LOUD], [1394.0, 1394.05, self::LOUD], [1396.5, 1410, -25.0]],
            timeline: [[1370, 1395, ...self::MUSIC], [1395, 1410, ...self::SPEECH]]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(1386.7, $plan['segments'][0]['end_time'], 0.01);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * 1117 §1959 (heard 11-10): the announcement cue opens at 713.62, before the last sung sound.
     * The earlier silence ends the song when the sound after it is a burst, but a sustained
     * return of music carries the song through to the next silence.
     */
    #[Test]
    #[TestWith([716.07, 714.4], 'short burst after a 1.6-second silence')]
    #[TestWith([716.8, 717.1], 'music stays above threshold for a full second')]
    public function silence_ends_a_song_unless_music_returns_for_a_second(float $returnEnds, float $expectedEnd): void
    {
        $sung = ['start' => 708.66, 'end' => 713.62, 'text' => 'Shirev ayo kamotan'];
        $talk = ['start' => 713.62, 'end' => 728.59, 'text' => "Well, in a moment we're going to have just a short five-"];
        $section = $this->song(569.32, 713.62, [$sung, $talk],
            [[690, 714.1, -37.0], [715.7, $returnEnds, -42.0], [724.8, 735, -22.0]],
            timeline: [[690, 715, 0.677, 0.012], [715, 720, 0.639, 0.288], [720, 725, 0.012, 0.018], [725, 735, 0.009, 0.779]]);
        $this->bank($section, 713.62, [
            ['start' => 707.80, 'end' => 725.22, 'word' => ' Well'],
            ['start' => 725.22, 'end' => 725.31, 'word' => ' in'],
            ['start' => 725.31, 'end' => 725.35, 'word' => ' a'],
            ['start' => 725.35, 'end' => 725.65, 'word' => ' moment'],
            ['start' => 725.65, 'end' => 725.90, 'word' => " we're"],
            ['start' => 725.90, 'end' => 726.02, 'word' => ' going'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($expectedEnd, $plan['segments'][0]['end_time'], 0.01);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * 954 §901 (heard 10-20, 10-28): "Our gracious and loving God," opens at 3830.56 while the band
     * plays; the classifier hears speech only in the next window. The second of silence before that
     * window's speech is still the song's end, not the cue's start.
     */
    #[Test]
    public function a_song_ends_at_the_silence_before_speech_only_the_next_window_hears(): void
    {
        $sung = ['start' => 3820.0, 'end' => 3826.0, 'text' => 'and evermore.'];
        $prayer = ['start' => 3830.56, 'end' => 3838.8, 'text' => 'Our gracious and loving God,'];
        $section = $this->song(3700.0, 3826.0, [$sung, $prayer],
            [[3800, 3833.1, self::LOUD], [3833.1, 3835.6, self::SILENT], [3835.6, 3850, -30.0]],
            timeline: [[3800, 3835, ...self::MUSIC], [3835, 3850, ...self::SPEECH]]);
        $this->bank($section, 3826.0, [
            ['start' => 3824.1, 'end' => 3825.9, 'word' => ' evermore.'],
        ]);
        $this->bank($section, 3830.56, [
            ['start' => 3835.7, 'end' => 3836.0, 'word' => ' Our'],
            ['start' => 3836.0, 'end' => 3836.6, 'word' => ' gracious'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertGreaterThanOrEqual(3833.1, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(3835.6, $plan['segments'][0]['end_time']);
        $this->assertSame('song_end_silence', $this->endEdge($plan)['reason']);
    }

    /**
     * 1366 §4567 (heard 10-28): "Prayer and commit our service to the Lord. Let's pray." is spliced
     * straight onto the recorded song; the classifier hears speech only in the next window, and the
     * only silence begins as "pray" ends. A silence the cue's speech has not finished before is not
     * before its speech: the song ends by the cue's start.
     */
    #[Test]
    public function a_silence_after_the_speech_cue_has_finished_does_not_end_the_song(): void
    {
        $sung = ['start' => 418.9, 'end' => 425.02, 'text' => 'For He dwells in the presence of all His people'];
        $prayer = ['start' => 435.64, 'end' => 443.44, 'text' => "Prayer and commit our service to the Lord. Let's pray."];
        $next = ['start' => 444.74, 'end' => 446.70, 'text' => 'Our loving God and Heavenly Father,'];
        $section = $this->song(200.0, 425.02, [$sung, $prayer, $next],
            [[400, 443.4, self::LOUD], [443.4, 444.7, self::SILENT], [444.7, 460, -30.0]],
            timeline: [[400, 440, ...self::MUSIC], [440, 460, ...self::SPEECH]]);
        $this->bank($section, 425.02, [
            ['start' => 423.5, 'end' => 425.0, 'word' => ' people'],
        ]);
        $this->bank($section, 435.64, [
            ['start' => 434.78, 'end' => 440.80, 'word' => ' Prayer'],
            ['start' => 440.80, 'end' => 441.42, 'word' => ' and'],
            ['start' => 441.42, 'end' => 441.74, 'word' => ' commit'],
            ['start' => 442.95, 'end' => 443.16, 'word' => " Let's"],
            ['start' => 443.24, 'end' => 443.44, 'word' => ' pray.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertLessThanOrEqual(435.64, $plan['segments'][0]['end_time']);
    }

    /**
     * 1243 §3024 (heard 10-30, register E24): "Let's join together in prayer. Let's pray." is said over
     * the last of the music, in a window the classifier hears as music and speech at once (0.42, 0.38),
     * and the quiet comes only after "pray". Speech after a song belongs to the next item: the song
     * ends as the speech starts and its music fades out under the cut. Heard faded, right; the cut at
     * the quiet ("neither" as a hard cut) took the whole line.
     */
    #[Test]
    public function a_song_ends_where_speech_starts_over_its_last_music_and_fades(): void
    {
        $sung = ['start' => 321.64, 'end' => 328.92, 'text' => 'Your love is gracious, O my God.'];
        $invitation = ['start' => 331.06, 'end' => 334.72, 'text' => "Let's join together in prayer. Let's pray."];
        $prayer = ['start' => 340.64, 'end' => 345.74, 'text' => 'Lord, as we sit here on another Sunday morning,'];
        $section = $this->song(191.23, 328.92, [$sung, $invitation, $prayer],
            [[300, 334.8, self::LOUD], [334.8, 340.5, self::SILENT], [340.5, 360, -30.0]],
            timeline: [[300, 330, 0.75, 0.01], [330, 335, 0.42, 0.38], [335, 340, 0.01, 0.02], [340, 360, 0.01, 0.84]]);
        $this->bank($section, 328.92, [
            ['start' => 326.22, 'end' => 328.38, 'word' => ' gracious,'],
            ['start' => 328.38, 'end' => 328.81, 'word' => ' oh'],
            ['start' => 328.81, 'end' => 329.24, 'word' => ' my'],
            ['start' => 329.24, 'end' => 329.91, 'word' => ' God.'],
            ['start' => 330.22, 'end' => 332.06, 'word' => " Let's"],
            ['start' => 332.06, 'end' => 333.24, 'word' => ' join'],
            ['start' => 333.24, 'end' => 333.60, 'word' => ' together'],
            ['start' => 333.60, 'end' => 333.82, 'word' => ' in'],
            ['start' => 333.82, 'end' => 334.14, 'word' => ' prayer.'],
            ['start' => 334.26, 'end' => 334.38, 'word' => " Let's"],
            ['start' => 334.42, 'end' => 334.74, 'word' => ' pray.'],
        ]);
        $this->bank($section, 340.64, [
            ['start' => 340.64, 'end' => 340.67, 'word' => ' Lord,'],
            ['start' => 340.78, 'end' => 341.01, 'word' => ' as'],
            ['start' => 341.01, 'end' => 341.35, 'word' => ' we'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(331.06, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame(CueSafeExtractionPlan::SONG_END_SPEECH_OVER_MUSIC, $this->endEdge($plan)['reason']);
        // The fade spans the gap from the last sung sound ("God." to 329.91), at least 1.5 s.
        $this->assertEqualsWithDelta(1.5, $plan['audio_fade_out'], 0.001);
    }

    /**
     * 1274 §3430 (RA3, heard 11-07): "…to God in prayer again. But let me mention a few things"
     * starts 4.2 s before the section's end, under a line that opens 15 s early over the singing.
     * The tail's overlapping decodes agree on the speech, which the classifier hears without music.
     */
    #[Test]
    public function a_song_ends_before_speech_its_tail_decodes_agree_on(): void
    {
        $sung = ['start' => 975.0, 'end' => 983.0, 'text' => 'Lord Lord Lord Lord Lord Lord'];
        $speech = ['start' => 985.0, 'end' => 1003.0, 'text' => 'to God in prayer again. But let me mention a few things'];
        $section = $this->song(800.0, 1000.0, [$sung, $speech], [[800, 1010, self::LOUD]],
            timeline: [[800, 990, 0.75, 0.01], [990, 1010, 0.01, 0.8]]);
        $heard = [...$this->sungWords(975.0, 8), ...$this->spokenWords(995.8, ['to', 'God', 'in', 'prayer', 'again.', 'But', 'let', 'me', 'mention'])];
        $this->bank($section, 1000.0, $heard);
        $this->bank($section, 985.0, $heard);
        $this->bankTail($section, 1000.0, $heard);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(995.8 - 1.5, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame(SongTailSpeechOnset::REASON, $this->endEdge($plan)['reason']);
        $this->assertEqualsWithDelta(1.5, $plan['audio_fade_out'], 0.001);
    }

    /**
     * 1268 §4731 (WP15 D4, 11-13): the tail's decodes agree on "Please sit down" from 498.91, so the
     * tail rule cut at 497.41 and faded from 495.91, inside the held last word of "Our sacrifice of
     * praise" (decoded "grace", heard to 498.17 and 498.27 by two windows). The lyrics place that word,
     * so the song ends in the pause after it and fades only from its end. The words are 1268's own
     * tail decodes, from 487 s on.
     */
    #[Test]
    public function tail_cut_and_fade_keep_the_complete_final_lyric(): void
    {
        $section = $this->sacrificeOfPraise();

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $end = $plan['segments'][0]['end_time'];
        $this->assertSame(SongTailSpeechOnset::REASON, $this->endEdge($plan)['reason']);
        $this->assertGreaterThanOrEqual(498.27, $end, 'The cut must keep the last sung word.');
        $this->assertLessThan(498.81, $end, 'The song must not take in the "Please" that follows the lyric.');
        $this->assertGreaterThanOrEqual(498.27, $end - $plan['audio_fade_out'], 'The fade must start after the last sung word.');
        $this->assertEqualsWithDelta(498.54, $end, 0.001);
        $this->assertEqualsWithDelta(497.41, $this->endEdge($plan)['tail_cut'], 0.001);
        $this->assertSame('grace', $this->endEdge($plan)['last_lyric']['word']);
    }

    /**
     * 1180 §2371 (WP15 D4, 11-13): "Praise with us the God of grace!" is held to 304.30 (decoded by two
     * windows), past the tail cut at 303.36 before "Please remain standing". A third window hears
     * "Lord of grace", which the lyrics do not hold, and the wide decode smears the instruction early.
     */
    #[Test]
    public function tail_cut_keeps_a_final_lyric_held_over_the_cut(): void
    {
        $song = Song::factory()->create(['lyrics_plain' => "All who dwell in time and space.\nPraise Him! Praise Him!\nPraise Him! Praise Him!\nPraise with us the God of grace!"]);
        $section = $this->song(144.668, 305.002, [
            ['start' => 298.78, 'end' => 304.02, 'text' => 'praise Him, praise Him, praise Him, praise Him.'],
            ['start' => 305.02, 'end' => 306.8, 'text' => 'Please remain standing if you can.'],
        ], [[140, 320, self::LOUD]], timeline: [[100, 305, 0.64, 0.03], [305, 310, 0.26, 0.7], [310, 330, 0.01, 0.8]]);
        $this->bindSong($section, $song);
        $remain = [['Please', 304.862, 304.862], ['remain', 305.012, 305.242], ['standing', 305.242, 305.882], ['if', 305.882, 306.042], ['you', 306.042, 306.282], ['can', 306.282, 306.522], ['.', 306.522, 306.782]];
        $this->bankTailWindows($section, 305.002, [
            'slide-16' => [['praise', 295.042, 295.732], ['him', 295.732, 296.072], ['praise', 296.342, 297.732], ['him', 297.732, 298.422], ['praise', 298.882, 300.272], ['him', 300.272, 300.992]],
            'slide-13' => [['praise', 292.142, 303.972]],
            'slide-10' => [['praise', 298.832, 299.542], ['Him', 299.542, 299.922], ['us', 299.922, 300.002], [',', 300.002, 300.502], ['the', 300.502, 301.252],
                ['Lord', 301.252, 302.242], ['of', 302.242, 302.742], ['grace', 302.742, 304.002], ['.', 304.002, 304.272], ...$remain],
            'slide-7' => [['Praise', 298.142, 299.492], ['be', 299.492, 300.002], [',', 300.002, 300.152], ['master', 300.152, 300.642], [',', 300.642, 301.242],
                ['God', 301.242, 302.152], ['of', 302.152, 302.752], ['grace', 302.752, 304.272], ['.', 304.272, 304.302], ['Please', 304.942, 304.942],
                ['remain', 305.082, 305.262], ['standing', 305.262, 305.902], ['if', 305.902, 306.062], ['you', 306.062, 306.302], ['can', 306.302, 306.542], ['.', 306.542, 306.802], ['When', 307.542, 307.542]],
            'slide-4' => [['God', 301.142, 301.902], ['of', 301.902, 302.502], ['grace', 302.502, 304.002], ['.', 304.002, 304.002], ['Please', 304.862, 304.862],
                ['remain', 305.002, 305.402], ['standing', 305.402, 306.362], ['if', 306.362, 306.442], ['you', 306.442, 306.562], ['can', 306.562, 306.682], ['.', 306.682, 306.802], ['When', 307.542, 307.542]],
            'before' => [['praise', 298.122, 299.402], ['the', 299.532, 299.762], ['Lord', 299.762, 300.242], ['of', 300.242, 300.482], ['grace', 300.482, 301.082], ['.', 301.082, 301.442],
                ['Please', 301.442, 302.162], ['remain', 302.162, 302.882], ['standing', 302.882, 303.842], ['if', 303.842, 304.082], ['you', 304.082, 304.442], ['can', 304.442, 304.802], ['.', 304.802, 305.162]],
        ]);
        $heard = array_map(static fn (array $word): array => ['start' => $word[1], 'end' => $word[2], 'word' => ' '.$word[0]],
            [['God', 301.142, 301.902], ['of', 301.902, 302.502], ['grace', 302.502, 304.002], ...$remain]);
        $this->bank($section, 305.002, $heard);
        $this->bank($section, 305.02, $heard);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $end = $plan['segments'][0]['end_time'];
        $this->assertSame(SongTailSpeechOnset::REASON, $this->endEdge($plan)['reason']);
        $this->assertGreaterThanOrEqual(304.302, $end, 'The cut must keep the last sung word.');
        $this->assertLessThan(304.862, $end);
        $this->assertGreaterThanOrEqual(304.302, $end - $plan['audio_fade_out'], 'The fade must start after the last sung word.');
        $this->assertEqualsWithDelta(304.582, $end, 0.001);
        $this->assertEqualsWithDelta(303.362, $this->endEdge($plan)['tail_cut'], 0.001);
    }

    /**
     * 1200 §2509 (WP19 case 5, 11-13): the tail's wide decode places the speech from "I was saying"
     * (460.42), so the tail rule cut at 459.92, inside "Please remain standing." (458.54–460.42). Two
     * of the tail's decodes hear that line's "Please" at 458.44, before the cut; the line is no lyric
     * of the song, so the song ends just before it, after the last sung "earth." (to 456.86). The
     * words and classifier windows are 1200's own.
     */
    #[Test]
    public function tail_cut_does_not_split_the_next_line(): void
    {
        $section = $this->pleaseRemainStanding();

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $end = $plan['segments'][0]['end_time'];
        $edge = $this->endEdge($plan);
        $this->assertSame(SongTailSpeechOnset::REASON, $edge['reason']);
        $this->assertGreaterThan(456.86, $end, 'The song keeps its last sung line.');
        $this->assertLessThan(458.44, $end, 'The song must not take in the start of "Please remain standing."');
        $this->assertEqualsWithDelta(458.437 - SongEndSpeechOnset::LEAD, $end, 0.001);
        $this->assertSame('Please remain standing.', $edge['split_line']['line']);
        $this->assertEqualsWithDelta(459.917, $edge['split_line']['from'], 0.001);
    }

    /** A line the bound song sings is not the next item's, and with no lyrics nothing tells, so the tail cut stands. */
    #[Test]
    #[TestWith([true])]
    #[TestWith([false])]
    public function tail_cut_inside_a_sung_line_or_without_lyrics_stands(bool $lyricLine): void
    {
        $section = $this->pleaseRemainStanding(lyrics: $lyricLine ? "Be exalted, O God, above the heavens,\nPlease remain standing.\nLet Your glory be over all the earth." : null);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(459.917, $plan['segments'][0]['end_time'], 0.001);
        $this->assertArrayNotHasKey('split_line', $this->endEdge($plan));
    }

    /**
     * 1239 §165498 (WP19, 11-13): the tail cut at 4122.955 falls inside "O come," and two decodes hear
     * its "O" at 4122.82 and 4122.94, so the line rule moves the cut before it. The song's own last
     * sung "let us" then runs to 4125.20 past that cut, and the last-lyric guard, run after the line
     * rule, keeps it: the end stays in the pause after "us", where the guard alone put it.
     */
    #[Test]
    public function the_last_lyric_guard_wins_over_the_line_rule(): void
    {
        $section = $this->song(3960.33, 4125.22, [
            ['start' => 4115.78, 'end' => 4119.5, 'text' => 'adore him.'],
            ['start' => 4120.2, 'end' => 4124.14, 'text' => 'O come,'],
            ['start' => 4124.5, 'end' => 4125.22, 'text' => 'let us'],
            ['start' => 4125.22, 'end' => 4126.92, 'text' => 'adore him.'],
            ['start' => 4128.5, 'end' => 4128.98, 'text' => 'Lord,'],
            ['start' => 4129.06, 'end' => 4129.54, 'text' => 'may we give'],
        ], [[3950, 4140, self::LOUD]], timeline: [[3950, 4120, 0.82, 0.02], [4120, 4125, 0.5503, 0.4061], [4125, 4145, 0.0114, 0.7813]]);
        $this->bindSong($section, Song::factory()->create(['lyrics_plain' => "Who has held the oceans in His hands?\nWho has numbered every grain of sand?\n\nBehold our God, seated on His throne,\nCome let us adore Him!\nBehold our King, nothing can compare,\nCome let us adore Him!"]));
        $this->bankTailWindows($section, 4125.22, [
            'slide-22' => [['is', 4103.36, 4115.21]],
            'slide-19' => [['Amen', 4106.36, 4113.08], ['.', 4113.08, 4118.21]],
            'slide-16' => [['oh', 4109.37, 4119.73]],
            'slide-13' => [['O', 4112.34, 4114.61], ['come', 4114.62, 4123.75], [',', 4124.21, 4124.21]],
            'slide-10' => [['Let', 4115.36, 4116.18], ['us', 4116.18, 4116.82], ['adore', 4116.82, 4118.42], ['him', 4118.42, 4119.42], ['.', 4119.42, 4119.44], ['O', 4122.82, 4122.82],
                ['come', 4122.82, 4122.82], [',', 4122.82, 4122.82], ['let', 4122.82, 4122.82], ['us', 4122.96, 4123.48], ['adore', 4123.48, 4125.17], ['him', 4125.17, 4126.18], ['.', 4126.18, 4127.21]],
            'slide-7' => [['O', 4118.35, 4119.41], ['come', 4119.41, 4124.14], [',', 4124.15, 4124.45], ['let', 4124.45, 4124.89], ['us', 4124.89, 4125.2], ['adore', 4125.2, 4126.28],
                ['him', 4126.28, 4126.93], ['.', 4126.93, 4126.94], ['Lord', 4128.36, 4128.36], [',', 4128.36, 4128.36], ['may', 4128.36, 4128.36], ['we', 4128.36, 4128.36],
                ['give', 4128.49, 4128.67], ['you', 4128.67, 4129.02], ['the', 4129.02, 4129.37], ['honour', 4129.37, 4130.09], ['...', 4130.09, 4130.22]],
            'slide-4' => [['O', 4121.37, 4121.81], ['come', 4121.81, 4124.16], [',', 4124.16, 4124.46], ['let', 4124.46, 4124.91], ['us', 4124.91, 4125.2], ['adore', 4125.21, 4126.29],
                ['him', 4126.29, 4126.93], ['.', 4126.93, 4126.94], ['Lord', 4128.36, 4128.36], [',', 4128.36, 4128.36], ['may', 4128.36, 4128.36], ['we', 4128.51, 4128.59],
                ['give', 4128.59, 4129.19], ['you', 4129.19, 4129.68], ['the', 4129.68, 4129.81], ['honour', 4129.82, 4130.1], ['that', 4130.1, 4130.99], ['you', 4130.99, 4131.65],
                ['deserve', 4131.65, 4133.22], ['.', 4133.22, 4133.22]],
            'before' => [['Come', 4103.36, 4103.74], ['and', 4103.74, 4104.13], ['be', 4104.13, 4104.39], ['strong', 4104.39, 4105.17], [',', 4105.17, 4105.43], ['come', 4105.43, 4105.95],
                ['and', 4105.95, 4106.34], ['let', 4106.34, 4106.73], ['us', 4106.73, 4106.99], ['adore', 4106.99, 4107.64], ['Him', 4107.64, 4108.06], ['.', 4108.06, 4108.06],
                ['We', 4108.5, 4108.56], ['hold', 4108.56, 4109.56], ['our', 4109.56, 4110.31], ['King', 4110.31, 4111.31], [',', 4111.31, 4111.82], ['nothing', 4111.82, 4112.76],
                ['can', 4112.76, 4113.15], ['compare', 4113.16, 4114.1], [',', 4114.1, 4114.38], ['come', 4114.38, 4115.25], ['and', 4115.25, 4115.9], ['let', 4115.9, 4116.55],
                ['us', 4116.55, 4116.98], ['adore', 4116.98, 4118.07], ['Him', 4118.07, 4118.72], ['.', 4118.72, 4119.42], ['O', 4122.94, 4122.94], ['come', 4122.94, 4122.94],
                [',', 4122.94, 4122.94], ['let', 4122.94, 4122.94], ['us', 4123.08, 4123.31], ['adore', 4123.31, 4124.93], ['Him', 4124.93, 4125.9], ['.', 4125.9, 4126.92],
                ['Lord', 4128.7, 4128.7], [',', 4128.7, 4128.7], ['may', 4128.7, 4128.7], ['we', 4128.7, 4128.7], ['give', 4128.84, 4129.27], ['you', 4129.27, 4129.74],
                ['the', 4129.74, 4130.21], ['honour', 4130.21, 4131.22], ['...', 4131.22, 4131.22]],
        ]);
        $heard = [['start' => 4124.45, 'end' => 4124.89, 'word' => ' let'], ['start' => 4124.89, 'end' => 4125.2, 'word' => ' us'], ['start' => 4125.2, 'end' => 4126.28, 'word' => ' adore'],
            ['start' => 4126.28, 'end' => 4126.93, 'word' => ' him.'], ['start' => 4128.36, 'end' => 4128.36, 'word' => ' Lord,']];
        $this->bank($section, 4125.22, $heard);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $edge = $this->endEdge($plan);
        $this->assertSame(SongTailSpeechOnset::REASON, $edge['reason']);
        $this->assertEqualsWithDelta(4122.82 - SongEndSpeechOnset::LEAD, $edge['split_line']['time'], 0.001, 'The line rule moves the cut before "O come,".');
        $this->assertEqualsWithDelta(4125.205, $plan['segments'][0]['end_time'], 0.001, 'The last sung "us" is kept.');
        $this->assertSame('us', $edge['last_lyric']['word']);
    }

    /**
     * 1200 §2509's last line, cues and classifier windows, and its tail decodes from 438 s; bound to
     * the song's own lyrics unless null is given.
     */
    private function pleaseRemainStanding(?string $lyrics = "Be exalted, O God, above the heavens,\nLet Your glory be over all the earth.\nLet Your glory,\nLet your glory,\nLet your glory be over all the earth."): ServiceSection
    {
        $section = $this->song(309.58, 460.437, [
            ['start' => 441.06, 'end' => 454.92, 'text' => 'Let your glory, let your glory, let your glory be over the'],
            ['start' => 454.92, 'end' => 456.86, 'text' => 'earth.'],
            ['start' => 458.54, 'end' => 460.42, 'text' => 'Please remain standing.'],
            ['start' => 461.42, 'end' => 466.91, 'text' => 'I was saying in the back room shortly before we began our'],
            ['start' => 466.91, 'end' => 469.04, 'text' => 'time together this morning'],
        ], [[300, 480, self::LOUD]], timeline: [[300, 455, 0.65, 0.03], [455, 460, 0.6377, 0.1458], [460, 465, 0.1977, 0.5939], [465, 480, 0.0132, 0.7112]]);
        if ($lyrics !== null) {
            $this->bindSong($section, Song::factory()->create(['lyrics_plain' => $lyrics]));
        }
        $slideThirteen = [['Oh', 447.577, 447.787], [',', 447.787, 448.137], ['bring', 448.137, 449.007], ['such', 449.007, 449.707], ['a', 449.707, 449.877], ['glory', 449.877, 450.747],
            [',', 450.747, 451.097], ['bring', 451.097, 451.967], ['the', 451.967, 452.487], ['old', 452.487, 453.007], ['world', 453.007, 453.877], ['glorious', 453.877, 455.337],
            ['.', 455.337, 456.437], ['Please', 458.437, 458.437], ['pray', 458.577, 458.737], ['.', 458.737, 459.437]];
        $this->bankTailWindows($section, 460.437, [
            'slide-22' => [['Let', 438.557, 443.577], ['your', 443.577, 450.427], ['glory', 450.427, 450.427], [',', 450.427, 450.427], ['let', 450.427, 450.427], ['your', 450.427, 450.427], ['glory', 450.427, 450.427]],
            'slide-19' => [['Let', 441.567, 444.437], ['your', 444.437, 448.437], ['glory', 448.437, 453.427], [',', 453.427, 453.427], ['let', 453.427, 453.427], ['your', 453.427, 453.427],
                ['glory', 453.427, 453.427], [',', 453.427, 453.427], ['let', 453.427, 453.427], ['your', 453.427, 453.427], ['glory', 453.427, 453.427], ['be', 453.427, 453.427],
                ['all', 453.427, 453.427], ['.', 453.427, 453.427]],
            'slide-16' => [['oh', 444.567, 456.427]],
            'slide-13' => $slideThirteen,
            'slide-10' => [['I', 450.577, 462.297], ['was', 462.427, 462.427]],
            'slide-7' => [['I', 453.597, 465.367], ['was', 465.427, 465.427], ['saying', 465.427, 465.427], ['in', 465.427, 465.427], ['the', 465.427, 465.427], ['back', 465.427, 465.427],
                ['room', 465.427, 465.427], ['shortly', 465.427, 465.427], ['before', 465.427, 465.427]],
            'slide-4' => [['-', 456.557, 458.417], ['Good', 458.417, 458.737], ['evening', 458.737, 459.267], [',', 459.267, 459.437], ['Sandy', 459.437, 460.677], ['.', 460.687, 461.437],
                ['I', 461.557, 461.567], ['was', 461.567, 461.957], ['saying', 461.957, 462.737], ['in', 462.737, 462.987], ['the', 462.987, 463.387], ['back', 463.387, 463.907],
                ['room', 463.907, 464.437], [',', 464.437, 464.437], ['shortly', 464.557, 465.227], ['before', 465.227, 465.907], ['we', 465.907, 466.127], ['began', 466.127, 466.697],
                ['our', 466.697, 467.037], ['time', 467.037, 467.487], ['together', 467.487, 468.437]],
            'before' => [['The', 438.557, 438.937], ['love', 438.937, 439.607], ['that', 439.607, 440.277], ['ever', 440.277, 440.957], ['has', 440.957, 442.437], [',', 442.437, 442.857],
                ['let', 442.857, 443.497], ['your', 443.497, 444.347], ['glory', 444.347, 445.437], [',', 445.437, 445.927], ['let', 445.927, 446.677], ['your', 446.677, 447.677],
                ['glory', 447.677, 448.917], [',', 448.917, 449.437], ['let', 449.437, 450.127], ['your', 450.127, 451.047], ['glory', 451.047, 452.197], ['be', 452.197, 452.657],
                ['over', 452.657, 453.577], ['glorious', 453.577, 455.437], ['.', 455.437, 455.437], ['Please', 458.437, 458.437], ['be', 458.437, 458.437], ['standing', 458.557, 459.617],
                ['.', 459.617, 460.417], ['I', 460.417, 460.577], ['was', 460.577, 461.077], ['saying', 461.077, 462.077], ['in', 462.077, 462.407], ['the', 462.407, 462.907],
                ['back', 462.907, 463.567], ['room', 463.567, 464.227], ['shortly', 464.227, 465.387], ['before', 465.387, 466.437], ['the', 466.437, 466.437]],
        ]);
        $heard = array_map(static fn (array $word): array => ['start' => $word[1], 'end' => $word[2], 'word' => ' '.$word[0]], $slideThirteen);
        $this->bank($section, 460.437, $heard);
        $this->bank($section, 461.42, $heard);

        return $section;
    }

    /**
     * A spoken word after the lyric is never protected, even where two decodes hear it in the music
     * straight after the last sung word: "grace. Please" is no phrase of the song.
     */
    #[Test]
    public function a_spoken_word_after_the_last_lyric_is_not_protected(): void
    {
        $section = $this->sacrificeOfPraise(clearPlease: true);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta(498.54, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame('grace', $this->endEdge($plan)['last_lyric']['word']);
    }

    /** With no lyrics, or with only one decode hearing the word, the tail cut and its fade stand. */
    #[Test]
    #[TestWith([false, false])]
    #[TestWith([true, true])]
    public function without_lyrics_or_a_second_decode_the_tail_cut_stands(bool $withLyrics, bool $oneDecode): void
    {
        $section = $this->sacrificeOfPraise(withLyrics: $withLyrics, oneDecode: $oneDecode);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertSame(SongTailSpeechOnset::REASON, $this->endEdge($plan)['reason']);
        $this->assertEqualsWithDelta(497.41, $plan['segments'][0]['end_time'], 0.001);
        $this->assertEqualsWithDelta(1.5, $plan['audio_fade_out'], 0.001);
        $this->assertArrayNotHasKey('last_lyric', $this->endEdge($plan));
    }

    /** Without its decodes banked, the tail rule leaves the song's end to the rules before it. */
    #[Test]
    public function a_song_whose_tail_decodes_are_not_banked_keeps_its_end(): void
    {
        $sung = ['start' => 975.0, 'end' => 983.0, 'text' => 'Lord Lord Lord Lord Lord Lord'];
        $speech = ['start' => 985.0, 'end' => 1003.0, 'text' => 'to God in prayer again. But let me mention a few things'];
        $section = $this->song(800.0, 1000.0, [$sung, $speech], [[800, 1010, self::LOUD]],
            timeline: [[800, 990, 0.75, 0.01], [990, 1010, 0.01, 0.8]]);
        $heard = [...$this->sungWords(975.0, 8), ...$this->spokenWords(995.8, ['to', 'God', 'in', 'prayer', 'again.', 'But', 'let', 'me', 'mention'])];
        $this->bank($section, 1000.0, $heard);
        $this->bank($section, 985.0, $heard);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertNotSame(SongTailSpeechOnset::REASON, $this->endEdge($plan)['reason'] ?? null);
    }

    /**
     * 1007 §1261 (heard right at 4744.64, 10-20) and 1314 §3989 (heard right at 1055.30, 11-10; WP16
     * D1, 11-13): the song ends at the silence before the next item, but the tail's decodes agree on
     * that item's speech only from a later word, so the tail rule moved the end on to 4747.14 and
     * 1061.82, into "Praise be to the Lord" and "Over the last few weeks". The classifier already
     * hears speech without music in between: the next item has begun, and the earlier end stands.
     * The words and classifier windows are those runs' own.
     *
     * @param  list<array{start: float, end: float, word: string}>  $heard  The decode at the section's end
     * @param  list<string>  $spoken  The words the tail's decodes agree on, from $agreedFrom
     */
    #[Test]
    #[TestWith([4580.0, 4754.08, 4744.14, 'Praise be to the Lord the God of Israel from everlasting to everlasting Let all the', 'people say Amen',
        4744.28, 4746.3, 4745.0, 4748.64, 4744.64, 4747.14,
        [['start' => 4743.28, 'end' => 4746.21, 'word' => ' Praise'], ['start' => 4746.93, 'end' => 4747.62, 'word' => ' be'], ['start' => 4747.62, 'end' => 4747.83, 'word' => ' to'],
            ['start' => 4747.98, 'end' => 4748.3, 'word' => ' the'], ['start' => 4748.3, 'end' => 4748.74, 'word' => ' Lord,'], ['start' => 4748.85, 'end' => 4749.01, 'word' => ' the'],
            ['start' => 4749.01, 'end' => 4749.23, 'word' => ' God']],
        ['the', 'God', 'of', 'Israel', 'from', 'everlasting', 'to', 'everlasting']], '1007 §1261 blessing')]
    #[TestWith([875.006, 1065.38, 1048.16, "Over the last few weeks, there's somebody that I've wanted", 'to come up to the front and share a little bit of testimony',
        1054.94, 1060.0, 1060.0, 1063.32, 1055.3, 1061.82,
        [['start' => 1052.3, 'end' => 1054.91, 'word' => ' the'], ['start' => 1057.23, 'end' => 1061.31, 'word' => ' last'], ['start' => 1061.33, 'end' => 1062.06, 'word' => ' few'],
            ['start' => 1062.16, 'end' => 1063.47, 'word' => ' weeks,'], ['start' => 1063.52, 'end' => 1063.97, 'word' => " there's"]],
        ["there's", 'somebody', 'that', "I've", 'wanted', 'to', 'come', 'up']], '1314 §3989 testimony')]
    public function tail_does_not_extend_a_silence_into_an_existing_blessing(float $start, float $end, float $cueFrom, string $nextText, string $afterText,
        float $silenceFrom, float $speechFrom, float $speechWindowFrom, float $agreedFrom, float $expected, float $tailCut, array $heard, array $spoken): void
    {
        $sung = ['start' => $cueFrom - 8.0, 'end' => $cueFrom, 'text' => 'Lord Lord Lord Lord Lord Lord'];
        $section = $this->song($start, $end, [$sung, ['start' => $cueFrom, 'end' => $end, 'text' => $nextText], ['start' => $end, 'end' => $end + 3.0, 'text' => $afterText]],
            [[$start, $silenceFrom, self::LOUD], [$silenceFrom, $speechFrom, self::SILENT], [$speechFrom, $end + 20, -30.0]],
            timeline: [[$start, $speechWindowFrom - 5.0, 0.7, 0.02], [$speechWindowFrom - 5.0, $speechWindowFrom, 0.7, 0.01],
                [$speechWindowFrom, $end + 20, 0.01, 0.79]]);
        $this->bank($section, $end, $heard);
        $this->bankTail($section, $end, $this->spokenWords($agreedFrom, $spoken));

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $edge = $this->endEdge($plan);
        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['end_time'], 0.05,
            "The tail cut at {$tailCut} takes in the next item's speech.");
        $this->assertSame('song_end_silence', $edge['reason']);
        $this->assertGreaterThanOrEqual($sung['end'], $plan['segments'][0]['end_time'], 'The song keeps its last sung line.');
    }

    /**
     * The earlier end gives way only to the song itself (E28): where its lyrics place a held last
     * sung word past that end, heard by two of the tail's decodes in the music, the earlier end does
     * not stand and the tail rule decides as before, keeping the word.
     */
    #[Test]
    public function an_earlier_end_that_would_cut_the_last_sung_word_does_not_stand(): void
    {
        $section = $this->song(4580.0, 4754.08, [
            ['start' => 4736.14, 'end' => 4744.14, 'text' => 'No wrongs we have done'],
            ['start' => 4744.14, 'end' => 4754.08, 'text' => 'Praise be to the Lord the God of Israel from everlasting to everlasting Let all the'],
            ['start' => 4754.08, 'end' => 4756.11, 'text' => 'people say Amen'],
        ], [[4580.0, 4744.28, self::LOUD], [4744.28, 4746.3, self::SILENT], [4746.3, 4775.0, -30.0]],
            timeline: [[4580.0, 4740.0, 0.7, 0.02], [4740.0, 4745.0, 0.7, 0.01], [4745.0, 4775.0, 0.01, 0.79]]);
        $this->bindSong($section, Song::factory()->create(['lyrics_plain' => "What love could remember\nNo wrongs we have done"]));
        $this->bank($section, 4754.08, [['start' => 4743.28, 'end' => 4746.21, 'word' => ' Praise'], ['start' => 4746.93, 'end' => 4747.62, 'word' => ' be']]);
        $sung = [['start' => 4742.2, 'end' => 4742.8, 'word' => ' we'], ['start' => 4742.8, 'end' => 4743.4, 'word' => ' have'], ['start' => 4743.5, 'end' => 4744.95, 'word' => ' done']];
        $this->bankTail($section, 4754.08, [...$sung, ...$this->spokenWords(4748.64, ['the', 'God', 'of', 'Israel', 'from', 'everlasting', 'to', 'everlasting'])]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertSame(SongTailSpeechOnset::REASON, $this->endEdge($plan)['reason']);
        $this->assertEqualsWithDelta(4744.64, $this->endEdge($plan)['replaced']['time'], 0.05);
        $this->assertEqualsWithDelta(4747.14, $plan['segments'][0]['end_time'], 0.001);
        $this->assertGreaterThanOrEqual(4744.95, $plan['segments'][0]['end_time'] - $plan['audio_fade_out'], 'The fade must start after the last sung word.');
    }

    /** Short sections must not mistake their preceding announcement for speech after the song. */
    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function a_short_songs_tail_search_excludes_speech_before_its_start(bool $wideOnly): void
    {
        $section = $this->song(990.0, 1000.0, [], [[970, 1010, self::LOUD]],
            timeline: [[970, 990, 0.01, 0.8], [990, 1010, 0.8, 0.01]]);
        $this->bankTail($section, 1000.0, $this->spokenWords(982.0, ['Let', 'us', 'sing', 'together']));
        if ($wideOnly) {
            $log = $section->processingLog->fresh();
            $evidence = app(OutputEdgeWordTimings::class);
            foreach (app(SongTailSpeechOnset::class)->windows(1000.0, $log->duration) as $name => $window) {
                if ($name !== 'before') {
                    app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                        'identity' => $evidence->identity($log, $window), 'words' => [], 'compute_seconds' => 1.0,
                    ]);
                }
            }
            $section->unsetRelation('processingLog');
        }

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertSame(1000.0, $plan['segments'][0]['end_time']);
        $this->assertSame([], app(PublicationPlanValidator::class)->validate($section->processingLog, [$section], $plan['segments'], $plan['cue_edge_widening']));
    }

    /** A section's rounded end can lie milliseconds beyond the measured recording. */
    #[Test]
    public function a_section_ending_just_past_the_source_is_planned_to_the_source_end(): void
    {
        $section = $this->song(3670.98, 3785.02, [], [[3670, 3786, self::LOUD]]);
        $section->processingLog->update(['duration' => 3785.015011]);
        $section->unsetRelation('processingLog');

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertSame(3785.015011, $plan['segments'][0]['end_time']);
        $this->assertSame([], app(PublicationPlanValidator::class)->validate($section->processingLog, [$section], $plan['segments'], $plan['cue_edge_widening']));
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([3780.0])]
    public function an_unknown_source_end_or_a_large_overrun_is_not_silently_repaired(?float $duration): void
    {
        $section = $this->song(3670.98, 3785.02, [], [[3670, 3786, self::LOUD]]);
        $section->processingLog->update(['duration' => $duration]);
        $section->unsetRelation('processingLog');

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertSame(3785.02, $plan['segments'][0]['end_time']);
        if ($duration !== null) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $this->assertSame([], app(PublicationPlanValidator::class)->validate($section->processingLog, [$section], $plan['segments'], $plan['cue_edge_widening']));
    }

    /**
     * Heard 11-07 (RA3, song-end listening and A/B pages): the edge decode stretches the prayer's
     * first word back over the outro's last note, so the cut before it faded the note out. The
     * aligned decodes on the cut end that word just before the cut and agree on the speech from
     * 1.3 s (its first word ends at 2.1 s), and the song keeps its outro up to it, still fading from
     * the last sung sound.
     */
    #[Test]
    public function a_song_faded_into_speech_keeps_its_outro_up_to_the_speech_its_decodes_agree_on(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankCut($section, $cut, [['start' => $cut + 0.06, 'end' => $cut + 1.3, 'word' => ' Lord', 'aligned_end' => $cut - 0.2],
            ...$this->spokenWords($cut + 1.85, ['God,', 'we', 'thank', 'you', 'for', 'fellowship'])]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut + 1.0, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame(SongEndSpeechOnset::REASON, $this->endEdge($plan)['reason']);
        $this->assertSame('song_end_speech', $this->endEdge($plan)['replaced']['reason']);
        $this->assertEqualsWithDelta(4.0, $plan['audio_fade_out'], 0.001);
    }

    /**
     * Agreement can miss the speech's first word (796's "Would you"), so the prayer still starts
     * at the cut before it; the two outputs overlap by the moved second (E27).
     */
    #[Test]
    public function the_next_item_still_starts_at_the_cut_before_the_agreed_speech(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankCut($section, $cut, [['start' => $cut + 0.06, 'end' => $cut + 1.3, 'word' => ' Lord'],
            ...$this->spokenWords($cut + 1.3, ['God,', 'we', 'thank', 'you', 'for', 'fellowship'])]);
        $prayer = ServiceSection::factory()->create(['media_processing_log_id' => $section->media_processing_log_id,
            'section_type' => ServiceSectionType::Prayer, 'start_time' => 4475.48, 'end_time' => 4600.0]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($prayer->fresh());

        $this->assertEqualsWithDelta($cut, $plan['segments'][0]['start_time'], 0.001);
        $start = collect($plan['cue_edge_widening'])->first(static fn (array $entry): bool => $entry['edge'] === 'start');
        $this->assertSame('song_end_speech', $start['after_song_sound']['reason']);
    }

    /**
     * E29 (heard 11-08, 3891): with the cut's voice and instruments separated, a song whose outro
     * runs under the talking plays on until the outro dies away, with the talking taken out from
     * the last sung word and the sound faded from it. Under the talking, the outro is gone where half
     * the next second is quiet (1966, 11-08). The talking here is quiet enough to stay hidden through
     * the fade. The next item still starts at the old cut.
     */
    #[Test]
    public function a_song_whose_outro_runs_under_the_talking_ends_when_the_outro_dies_with_the_talking_taken_out(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankCut($section, $cut, [['start' => $cut + 0.06, 'end' => $cut + 1.3, 'word' => ' Lord', 'aligned_end' => $cut - 0.2],
            ...$this->spokenWords($cut + 1.85, ['God,', 'we', 'thank', 'you', 'for', 'fellowship'])]);
        $this->bankStems($section, $cut, voice: [[-8, 0.2, -24.0], [0.2, 1.4, -70.0], [1.4, 16, -40.0]],
            instruments: [[-8, 2.0, -20.0], [2.0, 4.5, -33.0], [4.5, 16, -62.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut + 4.0, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame(SongEndOutro::UNDER_SPEECH, $this->endEdge($plan)['song_end_outro']);
        $this->assertEqualsWithDelta(3.8, $plan['audio_fade_out'], 0.001);
        $this->assertNotNull($plan['audio_voice_removal'] ?? null);
        $this->assertEqualsWithDelta($cut + 0.2, $plan['audio_voice_removal']['from'], 0.001);
        $this->assertSame($cut - 8.0, $plan['audio_voice_removal']['instruments_start']);
    }

    #[Test]
    #[TestWith([11.5])]
    #[TestWith([-8.25])]
    public function an_operator_song_end_outside_its_stored_separation_window_is_refused(float $offset): void
    {
        [$section, $cut] = $this->songWithVoiceRemoval();
        $planner = app(CueSafeExtractionPlan::class);
        $planned = $planner->forSection($section);
        $this->assertNotNull($planned['audio_voice_removal']);
        $this->setSongEnd($section, $cut + $offset);

        $answered = $planner->forSection($section->fresh());

        $refused = CueSafeExtractionPlan::refusedOperatorEdges($answered['cue_edge_widening']);
        $this->assertCount(1, $refused, 'An operator end outside the stored separated-audio window must be refused: '.json_encode($answered, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('outside the separated-audio window', $refused[0]['refusal']);
        $this->assertSame($planned['segments'], $answered['segments']);
        $this->assertSame($planned['audio_fade_out'], $answered['audio_fade_out']);
        $this->assertSame($planned['audio_voice_removal'], $answered['audio_voice_removal']);
        $this->assertContains(['kind' => 'operator_edge_refused', 'section_ids' => [$section->id]],
            app(PublicationPlanValidator::class)->validate($section->processingLog, [$section], $answered['segments'], $answered['cue_edge_widening']));
    }

    #[Test]
    #[TestWith([-1.0])]
    #[TestWith([5.0])]
    #[TestWith([11.25])]
    public function an_operator_song_end_inside_its_stored_separation_window_keeps_fade_and_voice_removal(float $offset): void
    {
        [$section, $cut] = $this->songWithVoiceRemoval();
        $planner = app(CueSafeExtractionPlan::class);
        $planned = $planner->forSection($section);
        $this->setSongEnd($section, $cut + $offset);

        $answered = $planner->forSection($section->fresh());

        $this->assertSame([], CueSafeExtractionPlan::refusedOperatorEdges($answered['cue_edge_widening']));
        $this->assertSame($cut + $offset, $answered['segments'][0]['end_time']);
        $this->assertSame($planned['audio_fade_out'], $answered['audio_fade_out']);
        $this->assertSame($planned['audio_voice_removal'], $answered['audio_voice_removal']);
        $this->assertSame(CueSafeExtractionPlan::OPERATOR_SET_EDGE, $this->endEdge($answered)['reason']);
    }

    #[Test]
    public function an_operator_song_end_with_only_a_fade_does_not_need_a_separated_track(): void
    {
        $section = $this->outroIntoPrayer();
        $planner = app(CueSafeExtractionPlan::class);
        $planned = $planner->forSection($section);
        $this->assertNotNull($planned['audio_fade_out']);
        $this->assertNull($planned['audio_voice_removal']);
        $end = $planned['segments'][0]['end_time'] + 20.0;
        $this->setSongEnd($section, $end);

        $answered = $planner->forSection($section->fresh());

        $this->assertSame([], CueSafeExtractionPlan::refusedOperatorEdges($answered['cue_edge_widening']));
        $this->assertSame($end, $answered['segments'][0]['end_time']);
        $this->assertSame($planned['audio_fade_out'], $answered['audio_fade_out']);
        $this->assertNull($answered['audio_voice_removal']);
    }

    #[Test]
    public function an_operator_song_end_with_voice_removal_is_refused_when_its_window_is_missing(): void
    {
        [$section, $cut] = $this->songWithVoiceRemoval();
        $log = $section->processingLog->fresh();
        $stems = app(SongEndStems::class);
        $kind = $stems->kind($stems->identity($log, $cut));
        $entry = collect(ServiceArtifactStorage::recordedFor($log))->firstWhere('kind', $kind);
        $payload = json_decode(Storage::disk($entry['disk'])->get($entry['path']), true, flags: JSON_THROW_ON_ERROR);
        unset($payload['end']);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $kind, $payload);
        $this->setSongEnd($section, $cut + 5.0);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section->fresh());

        $refused = CueSafeExtractionPlan::refusedOperatorEdges($plan['cue_edge_widening']);
        $this->assertCount(1, $refused);
        $this->assertStringContainsString('separated-audio window is missing', $refused[0]['refusal']);
    }

    #[Test]
    public function a_song_without_an_operator_edge_keeps_its_planned_treatment(): void
    {
        [$section, $cut] = $this->songWithVoiceRemoval();

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertSame([], CueSafeExtractionPlan::refusedOperatorEdges($plan['cue_edge_widening']));
        $this->assertEqualsWithDelta($cut + 4.0, $plan['segments'][0]['end_time'], 0.001);
        $this->assertEqualsWithDelta(3.8, $plan['audio_fade_out'], 0.001);
        $this->assertEqualsWithDelta($cut + 0.2, $plan['audio_voice_removal']['from'], 0.001);
        $this->assertSame($cut - 8.0, $plan['audio_voice_removal']['instruments_start']);
        $this->assertSame(SongEndSpeechOnset::REASON, $this->endEdge($plan)['reason']);
    }

    /** @return array{ServiceSection, float} */
    private function songWithVoiceRemoval(): array
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankCut($section, $cut, [['start' => $cut + 0.06, 'end' => $cut + 1.3, 'word' => ' Lord', 'aligned_end' => $cut - 0.2],
            ...$this->spokenWords($cut + 1.85, ['God,', 'we', 'thank', 'you', 'for', 'fellowship'])]);
        $this->bankStems($section, $cut, voice: [[-8, 0.2, -24.0], [0.2, 1.4, -70.0], [1.4, 16, -40.0]],
            instruments: [[-8, 2.0, -20.0], [2.0, 4.5, -33.0], [4.5, 16, -62.0]]);
        $log = $section->processingLog->fresh();
        $stems = app(SongEndStems::class);
        $kind = $stems->kind($stems->identity($log, $cut));
        $entry = collect(ServiceArtifactStorage::recordedFor($log))->firstWhere('kind', $kind);
        $payload = json_decode(Storage::disk($entry['disk'])->get($entry['path']), true, flags: JSON_THROW_ON_ERROR);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $kind, [...$payload, 'end' => $cut + 11.25]);
        $section->unsetRelation('processingLog');

        return [$section, $cut];
    }

    private function setSongEnd(ServiceSection $section, float $time): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata[OutputEdgeAnswers::METADATA_KEY]['end'] = ['time' => $time, 'set_by_user_id' => 1,
            'set_at' => now()->toIso8601String(), 'from_time' => (float) $section->end_time,
            'section_span' => ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time]];
        $section->forceFill(['metadata' => ServiceSectionMetadata::fromArray($metadata)])->save();
    }

    /** E29 (828): an outro that dies away before the talking ends the song at the silence, nothing taken out. */
    #[Test]
    public function a_song_whose_outro_dies_before_the_talking_ends_at_the_silence(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankCut($section, $cut, [['start' => $cut + 0.06, 'end' => $cut + 1.3, 'word' => ' Lord', 'aligned_end' => $cut - 0.2],
            ...$this->spokenWords($cut + 1.85, ['God,', 'we', 'thank', 'you', 'for', 'fellowship'])]);
        $this->bankStems($section, $cut, voice: [[-8, 1.3, -98.0], [1.3, 16, -35.0]],
            instruments: [[-8, 0.6, -33.0], [0.6, 16, -60.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut + 0.6, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame(SongEndOutro::SILENCE, $this->endEdge($plan)['song_end_outro']);
        $this->assertEqualsWithDelta(0.1, $plan['audio_fade_out'], 0.001);
        $this->assertNull($plan['audio_voice_removal'] ?? null);
    }

    /** 1314 (11-08): where the recording itself cuts out of the music, the song fades over 3 s into the cut. */
    #[Test]
    public function a_song_whose_recording_cuts_out_fades_into_the_cut(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankCut($section, $cut, [['start' => $cut + 0.06, 'end' => $cut + 1.3, 'word' => ' Lord', 'aligned_end' => $cut - 0.2],
            ...$this->spokenWords($cut + 1.85, ['God,', 'we', 'thank', 'you', 'for', 'fellowship'])]);
        $this->bankStems($section, $cut, voice: [[-8, 1.3, -98.0], [1.3, 16, -35.0]],
            instruments: [[-8, 0.6, -33.0], [0.6, 1.3, -90.0], [1.3, 16, -60.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut + 0.6, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame(SongEndOutro::RECORDING_CUT, $this->endEdge($plan)['song_end_outro']);
        $this->assertEqualsWithDelta(3.0, $plan['audio_fade_out'], 0.001);
        $this->assertNull($plan['audio_voice_removal'] ?? null);
    }

    /**
     * 1138 §2124 (WP15 D2, 11-13): "Phil is going to come and bring our Bible reading to us." starts at
     * the cut (1751.50), but the cut's decodes agree only from "Bible reading", 2.5 s in, and moving the
     * end to just before that agreement kept the reader's introduction in the song (1753.74). The
     * separated tracks find no outro ending: the voice is the reader's from the cut, and the
     * instruments fall about 20 dB but stay just above the line for an outro that has died away. Agreement
     * on later words is no evidence that the earlier ones were not said, so the song keeps the cut before
     * them. The levels approximate 1138's banked stems (outro −53 dB, −68 to −72 dB under the reading).
     */
    #[Test]
    public function later_agreed_reading_words_do_not_extend_a_song_without_an_outro(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankCut($section, $cut, $this->spokenWords($cut + 2.9, ['Bible', 'reading', 'to', 'us.', 'Thank', 'you,', 'Phil.']),
            onlyInLastWindow: $this->spokenWords($cut + 0.1, ['Phil', 'is', 'going', 'to', 'come', 'and', 'bring', 'our']));
        $this->bankStems($section, $cut, voice: [[-8, -2.5, -107.0], [-2.5, -0.2, -58.0], [-0.2, 4.0, -36.0], [4.0, 16, -55.0]],
            instruments: [[-8, -2.7, -89.0], [-2.7, -0.8, -50.0], [-0.8, 4.0, -69.0], [4.0, 16, -55.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame('song_end_speech', $this->endEdge($plan)['reason']);
        $this->assertNull($plan['audio_voice_removal'] ?? null);
    }

    /** Without its decodes banked, the song's end stays where the rules before it put it. */
    #[Test]
    public function a_song_whose_cut_decodes_are_not_banked_keeps_its_cut(): void
    {
        $plan = app(CueSafeExtractionPlan::class)->forSection($this->outroIntoPrayer());

        $this->assertSame('song_end_speech', $this->endEdge($plan)['reason']);
    }

    /**
     * 1088 §1780 (heard 11-09): no speech is agreed after the cut, but the song carries on past it. The
     * banked voice track finds the talking, and the outro that dies before it ends the song at that
     * silence. The next item still starts at the old cut.
     */
    #[Test]
    public function a_song_with_no_agreed_speech_ends_at_the_silence_its_voice_track_finds(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankStems($section, $cut, voice: [[-8, 1.5, -24.0], [1.5, 8.5, -98.0], [8.5, 16, -35.0]],
            instruments: [[-8, 4.3, -30.0], [4.3, 16, -60.0]]);
        $prayer = ServiceSection::factory()->create(['media_processing_log_id' => $section->media_processing_log_id,
            'section_type' => ServiceSectionType::Prayer, 'start_time' => 4475.48, 'end_time' => 4600.0]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut + 4.3, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame(CueSafeExtractionPlan::SONG_END_VOICE_TRACK_TALKING, $this->endEdge($plan)['reason']);
        $this->assertSame('song_end_speech', $this->endEdge($plan)['replaced']['reason']);
        $this->assertSame(SongEndOutro::SILENCE, $this->endEdge($plan)['song_end_outro']);
        $this->assertEqualsWithDelta(0.1, $plan['audio_fade_out'], 0.001);
        $this->assertEqualsWithDelta($cut, app(CueSafeExtractionPlan::class)->forSection($prayer->fresh())['segments'][0]['start_time'], 0.001);
    }

    /**
     * Heard 11-09: with no agreed speech, an outro the voice track finds running under the talking
     * was preferred to today's cut no more often than not, so the cut stands.
     */
    #[Test]
    public function a_song_with_no_agreed_speech_whose_outro_runs_under_the_talking_keeps_its_cut(): void
    {
        $section = $this->outroIntoPrayer();
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankStems($section, $cut, voice: [[-8, 0.2, -24.0], [0.2, 1.4, -70.0], [1.4, 16, -40.0]],
            instruments: [[-8, 2.0, -20.0], [2.0, 4.5, -33.0], [4.5, 16, -62.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame('song_end_speech', $this->endEdge($plan)['reason']);
    }

    /**
     * 175130, 3598 (heard 11-09): where the music has already stopped just after the cut, the voice
     * after it is the speech, not the song carrying on: the cut stands, as {@see SongEndSpeechOnset} has it.
     */
    #[Test]
    public function a_song_with_no_agreed_speech_whose_music_has_stopped_at_the_cut_keeps_its_cut(): void
    {
        $section = $this->outroIntoPrayer(timeline: [[4300, 4495, 0.8, 0.01], [4495, 4510, 0.01, 0.88]]);
        $cut = app(CueSafeExtractionPlan::class)->forSection($section)['segments'][0]['end_time'];
        $this->bankStems($section, $cut, voice: [[-8, 1.5, -24.0], [1.5, 8.5, -98.0], [8.5, 16, -35.0]],
            instruments: [[-8, 4.3, -30.0], [4.3, 16, -60.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSection($section);

        $this->assertEqualsWithDelta($cut, $plan['segments'][0]['end_time'], 0.001);
        $this->assertNotSame(CueSafeExtractionPlan::SONG_END_VOICE_TRACK_TALKING, $this->endEdge($plan)['reason']);
    }

    /**
     * 1250 §4911's outro into the prayer (as in a_song_runs_on_through_its_outro_until_someone_starts_talking).
     *
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $timeline
     */
    private function outroIntoPrayer(array $timeline = [[4300, 4495, 0.8, 0.01], [4495, 4500, 0.55, 0.82], [4500, 4510, 0.01, 0.88]]): ServiceSection
    {
        $last = ['start' => 4469.04, 'end' => 4475.48, 'text' => 'Glory to you, there is no greater thing'];
        $prayer = ['start' => 4475.48, 'end' => 4498.26, 'text' => 'Lord God, we thank you for fellowship with one another.'];
        $section = $this->song(4300.0, 4475.48, [$last, $prayer], [[4300, 4495.9, self::LOUD], [4495.9, 4510, -25.0]],
            timeline: $timeline);
        $this->bank($section, 4475.48, [
            ['start' => 4473.75, 'end' => 4475.18, 'word' => ' greater'],
            ['start' => 4475.18, 'end' => 4476.26, 'word' => ' thing.'],
            ['start' => 4476.74, 'end' => 4481.70, 'word' => " You're my joy, my righteousness,"],
            ['start' => 4482.46, 'end' => 4487.53, 'word' => ' and I love you all, love you'],
            ['start' => 4487.53, 'end' => 4489.10, 'word' => ' all.'],
            ['start' => 4495.96, 'end' => 4496.08, 'word' => ' Lord'],
            ['start' => 4496.10, 'end' => 4496.50, 'word' => ' God,'],
        ]);

        return $section;
    }

    /**
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  list<array{start: float, end: float, word: string}>  $onlyInLastWindow  Words only the latest window heard, so none agree on them
     */
    private function bankCut(ServiceSection $section, float $cut, array $words, array $onlyInLastWindow = []): void
    {
        $log = $section->processingLog->fresh();
        $evidence = app(OutputEdgeWordTimings::class);
        $windows = app(SongEndSpeechOnset::class)->windows($cut, $log->duration);
        foreach ($windows as $name => $window) {
            $heard = $name === array_key_last($windows) ? [...$onlyInLastWindow, ...$words] : $words;
            $inside = array_values(array_filter($heard, static fn (array $word): bool => $word['start'] >= $window['start'] && $word['end'] <= $window['end']));
            // An aligned decode: a clearly spoken word's aligned end is its own end.
            $inside = array_map(static fn (array $word): array => $word + ['aligned_end' => $word['end']], $inside);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                'identity' => $evidence->identity($log, $window), 'words' => $inside, 'compute_seconds' => 1.0,
            ]);
        }
        $section->unsetRelation('processingLog');
    }

    /**
     * The cut's separation as {@see SongEndStems} banks it: levels every 0.1 s from the cut − 8 s.
     *
     * @param  list<array{0: float|int, 1: float|int, 2: float}>  $voice
     * @param  list<array{0: float|int, 1: float|int, 2: float}>  $instruments
     */
    private function bankStems(ServiceSection $section, float $cut, array $voice, array $instruments): void
    {
        $log = $section->processingLog->fresh();
        $stems = app(SongEndStems::class);
        $identity = $stems->identity($log, $cut);
        $frames = static function (array $spans): array {
            $levels = [];
            for ($index = 0; $index < 240; $index++) {
                $time = -8.0 + $index * 0.1 + 0.0001;
                $levels[] = array_values(array_filter($spans, static fn (array $span): bool => $time >= $span[0] && $time < $span[1]))[0][2];
            }

            return $levels;
        };
        $track = tempnam(sys_get_temp_dir(), 'stems-');
        file_put_contents($track, 'flac');
        app(ServiceArtifactStorage::class)->putFile($log->processing_id, $stems->kind($identity).'-instruments', $track, 'flac');
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $stems->kind($identity), ['identity' => $identity,
            'start' => $identity['start'], 'frame_seconds' => 0.1, 'voice_db' => $frames($voice), 'instruments_db' => $frames($instruments)]);
        unlink($track);
        $section->unsetRelation('processingLog');
    }

    /**
     * 1268 §4731 "O God Beyond All Praising": its last line, the tail decodes from 487 s, and the
     * classifier's windows (music under the held last word, speech alone from 500 s).
     */
    private function sacrificeOfPraise(bool $withLyrics = true, bool $oneDecode = false, bool $clearPlease = false): ServiceSection
    {
        $section = $this->song(383.36, 503.17, [
            ['start' => 489.06, 'end' => 497.36, 'text' => 'And make a joyful duty of sacrifice or praise.'],
            ['start' => 498.82, 'end' => 503.17, 'text' => "We'll sit down and we'll continue in worship as we pray."],
            ['start' => 503.17, 'end' => 503.8, 'text' => "Let's pray."],
        ], [[380, 510, self::LOUD]], timeline: [[300, 495, 0.83, 0.02], [495, 500, 0.81, 0.78], [500, 520, 0.03, 0.72]]);
        if ($withLyrics) {
            $this->bindSong($section, Song::factory()->create(['lyrics_plain' => "To marvel at Your beauty\nAnd glory in Your ways,\nAnd make a joyful duty\nOur sacrifice of praise."]));
        }
        $sitDown = [['sit', 499.16, 499.39], ['down', 499.39, 499.91], ['and', 499.91, 500.04], ['we', 500.04, 500.12], ["'ll", 500.12, 500.25], ['continue', 500.25, 500.61],
            ['in', 500.61, 500.83], ['worship', 500.83, 501.61], ['as', 501.61, 501.88], ['we', 501.88, 502.15], ['pray', 502.15, 502.7], ['.', 502.7, 503.13],
            ['Let', 503.29, 503.29], ["'s", 503.39, 503.39], ['pray', 503.42, 503.61], ['.', 503.61, 503.79]];
        $slideTen = [['The', 493.32, 493.97], ['sacrifice', 493.97, 496.36], ['of', 496.36, 496.9],
            ...($oneDecode ? [] : [['grace', 496.9, 498.24], ['.', 498.25, 498.27]]), ['Please', 499.01, 499.02], ...$sitDown];
        $this->bankTailWindows($section, 503.17, [
            'slide-19' => array_map(static fn (string $word): array => [$word, 496.16, 496.16], ['glory', 'in', 'your', 'ways', ',', 'and', 'make', 'a', 'tri', 'but', 'ary', 'of', 'sacrifice', 'of', 'praise', '.']),
            'slide-16' => [['the', 487.3, 497.49]],
            'slide-13' => [['Make', 490.32, 490.67], ['a', 490.67, 490.78], ['joyful', 490.78, 491.53], ['today', 491.53, 492.17], [',', 492.17, 492.8], ['a', 492.8, 493.11],
                ['sacrifice', 493.11, 495.94], ['of', 495.94, 496.57], ['grace', 496.57, 498.17], ['.', 498.17, 498.17], ['Please', 498.81, $clearPlease ? 499.0 : 498.81],
                ['sit', 498.96, 498.97], ['down', 498.97, 499.33], ['and', 499.33, 499.6], ['we', 499.6, 499.78], ["'ll", 499.78, 500.04], ['continue', 500.04, 500.75],
                ['in', 500.75, 500.93], ['worship', 500.93, 501.59], ['as', 501.59, 502.17], ['we', 502.17, 502.17], ['...', 502.17, 502.17]],
            'slide-10' => $slideTen,
            'slide-7' => [['do', 496.29, 496.66], ['please', 496.66, 498.14], ['sit', 498.14, 498.89], ['down', 498.89, 499.91], ['and', 499.91, 500.0], ['we', 500.0, 500.06],
                ["'ll", 500.06, 500.16], ['continue', 500.17, 500.59], ['in', 500.59, 500.8], ['worship', 501.09, 501.59], ['as', 501.59, 502.23], ['we', 502.23, 502.5],
                ['we', 502.6, 502.75], ['pray', 502.75, 503.15], ['let', 503.15, 503.35], ["'s", 503.36, 503.47], ['pray', 503.51, 503.82]],
            'slide-4' => [['sit', 499.28, 499.35], ['down', 499.35, 499.6], ['and', 499.6, 499.78], ['we', 499.78, 499.9], ["'ll", 499.9, 500.08], ['continue', 500.08, 500.61],
                ['in', 500.61, 500.81], ['worship', 501.09, 501.59], ['as', 501.59, 502.07], ['we', 502.07, 502.5], ['we', 502.6, 502.75], ['pray', 502.75, 503.15],
                ['let', 503.15, 503.35], ["'s", 503.36, 503.47], ['pray', 503.51, 503.82]],
            'before' => [['and', 489.2, 489.43], ['make', 489.43, 490.34], ['a', 490.34, 490.56], ['joyful', 490.56, 491.92], ['duty', 491.92, 492.83], ['of', 492.83, 493.28],
                ['sacrifice', 493.28, 495.33], ['of', 495.33, 495.78], ['grace', 495.78, 496.92], ['.', 496.92, 497.65], ['Please', 498.83, 498.83], ['sit', 498.83, 498.83],
                ['down', 498.97, 498.99], ['and', 498.99, 499.3], ['we', 499.3, 499.5], ["'ll", 499.5, 499.81], ['continue', 499.81, 500.63], ['in', 500.63, 500.83],
                ['worship', 500.83, 501.59], ['as', 501.59, 501.87], ['we', 501.87, 502.15], ['pray', 502.15, 502.7], ['.', 502.7, 503.13], ['Let', 503.13, 503.3],
                ["'s", 503.3, 503.41], ['pray', 503.41, 503.63], ['.', 503.63, 503.81]],
        ]);
        $heard = array_map(static fn (array $word): array => ['start' => $word[1], 'end' => $word[2], 'word' => ' '.$word[0]], $slideTen);
        $this->bank($section, 503.17, $heard);
        $this->bank($section, 498.82, $heard);

        return $section;
    }

    private function bindSong(ServiceSection $section, Song $song): void
    {
        $section->forceFill(['metadata' => ServiceSectionMetadata::fromArray(['transcript_song_match' => ['song_id' => $song->id, 'title' => $song->title]])])->save();
    }

    /**
     * Every tail window ({@see SongTailSpeechOnset::windows()}) banked with its own decode; a window
     * not named heard nothing. Words are [text, start, end]; a token is a word's continuation, as
     * whisper.cpp writes it, when its text has no letter or starts with an apostrophe.
     *
     * @param  array<string, list<array{0: string, 1: float, 2: float}>>  $byWindow
     */
    private function bankTailWindows(ServiceSection $section, float $end, array $byWindow): void
    {
        $log = $section->processingLog->fresh();
        $evidence = app(OutputEdgeWordTimings::class);
        foreach (app(SongTailSpeechOnset::class)->windows($end, $log->duration) as $name => $window) {
            $words = array_map(static fn (array $word): array => ['start' => $word[1], 'end' => $word[2],
                'word' => preg_match('/^[\\p{L}\\p{N}]/u', $word[0]) === 1 ? ' '.$word[0] : $word[0]], $byWindow[$name] ?? []);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                'identity' => $evidence->identity($log, $window), 'words' => $words, 'compute_seconds' => 1.0,
            ]);
        }
        $section->unsetRelation('processingLog');
    }

    /** @param list<array{start: float, end: float, word: string}> $words */
    private function bankTail(ServiceSection $section, float $end, array $words): void
    {
        $log = $section->processingLog->fresh();
        $evidence = app(OutputEdgeWordTimings::class);
        foreach (app(SongTailSpeechOnset::class)->windows($end, $log->duration) as $window) {
            $inside = array_values(array_filter($words, static fn (array $word): bool => $word['start'] >= $window['start'] && $word['end'] <= $window['end']));
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                'identity' => $evidence->identity($log, $window), 'words' => $inside, 'compute_seconds' => 1.0,
            ]);
        }
        $section->unsetRelation('processingLog');
    }

    /**
     * @param  list<string>  $text
     * @return list<array{start: float, end: float, word: string}>
     */
    private function spokenWords(float $from, array $text): array
    {
        return array_map(static fn (int $index, string $word): array => ['start' => round($from + $index * 0.35, 3), 'end' => round($from + $index * 0.35 + 0.25, 3), 'word' => " {$word}"],
            array_keys($text), $text);
    }

    /** @return list<array{start: float, end: float, word: string}> */
    private function sungWords(float $from, int $count): array
    {
        return array_map(static fn (int $index): array => ['start' => $from + $index * 1.0, 'end' => $from + $index * 1.0 + 0.6, 'word' => ' Lord'], range(0, $count - 1));
    }

    /** @param array{cue_edge_widening: list<array<string, mixed>>} $plan
     * @return array<string, mixed>
     */
    private function endEdge(array $plan): array
    {
        return collect($plan['cue_edge_widening'])->last(static fn (array $entry): bool => $entry['edge'] === 'end');
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{0: float|int, 1: float|int, 2: float}>  $levels
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>|null  $timeline  Classifier spans: [from, to, music, speech]
     */
    private function song(float $start, float $end, array $cues, array $levels, ServiceSectionType $type = ServiceSectionType::Song, ?array $timeline = null): ServiceSection
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'rms_log_path' => 'service-transcripts/song-end.rms.log',
            'audio_timeline_path' => $timeline !== null ? 'service-transcripts/song-end.classes.json' : null]);
        if ($timeline !== null) {
            Storage::disk('local')->put('service-transcripts/song-end.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        }
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
        foreach ([$evidence->timingWindow($evidence->cues($log), $edge, $log->duration), $evidence->window($evidence->cues($log), $edge, $log->duration, $log)] as $window) {
            $this->assertNotNull($window);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                'identity' => $evidence->identity($log, $window), 'words' => $words, 'compute_seconds' => 1.0,
            ]);
        }
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
