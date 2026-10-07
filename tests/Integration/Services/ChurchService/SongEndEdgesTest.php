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
    public function speech_with_no_words_after_the_singing_leaves_the_song_end_unresolved_at_the_first_quiet(): void
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
        $this->assertSame(CueSafeExtractionPlan::SONG_END_UNRESOLVED, $this->endEdge($plan)['reason']);
        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
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
    public function a_song_end_whose_speech_opening_is_not_heard_is_unresolved_and_stays_before_the_cue(): void
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
        $this->assertSame(CueSafeExtractionPlan::SONG_END_UNRESOLVED, $this->endEdge($plan)['reason']);
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
