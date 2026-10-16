<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Canary 12 (operator, 2026-10-06): sermon ends beside a song landed mid-word or mid-sentence.
 * 1028 clipped half of "nine" ("Number 749."); the cut sits in the pause after the last word, not
 * on its decoded end. Where the sermon ends beside a song is register K16 (2026-10-16): at the
 * line the banked introduction answer names ({@see SermonSongIntroductionCheck}), which replaced
 * running on to wherever the singing starts (K8).
 */
class SpokenEndBesideSongTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 1077 and 1023: a looped transcript repeats a line as zero-length cues at one instant. The
     * old spoken stretch stopped advancing on them and the edge job spun until it timed out; the
     * question numbers them as lines and the answer's end is read past them.
     */
    #[Test]
    public function zero_length_cues_before_the_song_do_not_stop_the_end_being_found(): void
    {
        $repeat = ['start' => 1935.66, 'end' => 1935.66, 'text' => 'because you died for me,'];
        $log = $this->service([
            ['start' => 1919.78, 'end' => 1920.08, 'text' => 'Amen.'],
            ['start' => 1934.42, 'end' => 1935.66, 'text' => 'Because you died for me,'],
            $repeat, $repeat, $repeat,
            ['start' => 1935.66, 'end' => 1938.26, 'text' => 'I will remember you, my Lord,'],
        ], [['sermon', 0.0, 1920.08], ['song', 1921.4, 1942.7]], timeline: [[1900, 1940, 0.01, 0.8]]);
        $this->introduction($log, [['start_time' => 0.0, 'end_time' => 1920.08]], [1, true, 6], [1, true, 6]);

        $through = app(CueSafeExtractionPlan::class)->spokenEndsBesideSongs($log, [['start_time' => 0.0, 'end_time' => 1920.08]]);

        $this->assertSame([1938.26], $through);
    }

    /** 1028: the edge falls inside "749"; the decode ends "49" early, so the cut keeps a tail. */
    #[Test]
    public function a_sermon_end_beside_a_song_keeps_a_tail_after_its_last_word(): void
    {
        $log = $this->service([
            ['start' => 4111.56, 'end' => 4113.60, 'text' => 'Oh, how the grace of God amazes me.'],
            ['start' => 4113.66, 'end' => 4115.72, 'text' => 'Number 749.'],
            ['start' => 4130.92, 'end' => 4137.10, 'text' => 'The grace of God'],
        ], [['sermon', 1965.95, 4115.0], ['song', 4115.0, 4303.18]], timeline: [[4100, 4120, 0.01, 0.75], [4120, 4300, 0.66, 0.02]]);
        $this->introduction($log, [['start_time' => 1965.95, 'end_time' => 4115.0]], [1, true, 2], [1, true, 2]);
        $this->bank($log, 4115.72, [
            ['start' => 4112.78, 'end' => 4113.60, 'word' => ' amazes me.'],
            ['start' => 4113.66, 'end' => 4113.80, 'word' => ' Number'],
            ['start' => 4113.80, 'end' => 4114.43, 'word' => ' 7'],
            ['start' => 4114.45, 'end' => 4115.78, 'word' => '49.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 1965.95, 'end_time' => 4115.0]], sermonEnd: true);

        $this->assertGreaterThanOrEqual(4115.98, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(4116.72, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']), 'the answer says where it ends');
    }

    /**
     * 1304 under K16: the section ends mid-way through the preacher quoting the hymn, which ties
     * it to the sermon. The quotation goes in whole; "Let's stand and sing." on its own does not.
     */
    #[Test]
    public function a_tied_introduction_ends_before_the_call_to_stand_and_sing(): void
    {
        $log = $this->service([
            ['start' => 3697.06, 'end' => 3699.66, 'text' => 'Climb the steeps and cross the waves.'],
            ['start' => 3699.76, 'end' => 3702.58, 'text' => "Onward, tis our Lord's command."],
            ['start' => 3702.84, 'end' => 3704.54, 'text' => 'Jesus saves.'],
            ['start' => 3704.54, 'end' => 3705.48, 'text' => "Let's stand and sing."],
            ['start' => 3706.74, 'end' => 3708.74, 'text' => 'Amen.'],
            ['start' => 3736.74, 'end' => 3740.08, 'text' => 'Every man by the saints'],
        ], [['sermon', 1871.36, 3702.58], ['song', 3706.01, 3897.92]], timeline: [[3690, 3705, 0.01, 0.77], [3705, 3710, 0.59, 0.49], [3710, 3900, 0.8, 0.01]]);
        $spans = [['start_time' => 1871.36, 'end_time' => 3702.58]];
        $this->introduction($log, $spans, [0, true, 3], [0, true, 3]);
        $this->bank($log, 3704.54, [
            ['start' => 3702.90, 'end' => 3703.10, 'word' => ' Jesus'],
            ['start' => 3703.20, 'end' => 3704.02, 'word' => ' saves.'],
            ['start' => 3704.64, 'end' => 3704.87, 'word' => " Let's"],
            ['start' => 3705.10, 'end' => 3705.66, 'word' => ' stand'],
        ]);

        $plans = app(CueSafeExtractionPlan::class);
        $plan = $plans->forSpans($log, $spans, sermonEnd: true);

        $this->assertGreaterThanOrEqual(3704.02, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(3704.64, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
        $this->assertSame([3704.54], $plans->spokenEndsBesideSongs($log, $spans), 'the edge step decodes the window the answer\'s end reads');
    }

    /** A song's recorded start is no boundary: a line past it that the answer keeps goes in whole. */
    #[Test]
    public function a_thought_continuing_past_the_songs_recorded_start_goes_in_whole(): void
    {
        $log = $this->service($this->unfinishedThought(), [['sermon', 1871.36, 3702.58], ['song', 3706.01, 3897.92]],
            timeline: [[3600, 3710, 0.05, 0.8], [3710, 3900, 0.8, 0.02]]);
        $spans = [['start_time' => 1871.36, 'end_time' => 3702.58]];
        $this->introduction($log, $spans, [1, true, 4], [1, true, 4]);
        $plans = app(CueSafeExtractionPlan::class);
        $this->assertSame([3707.2], $plans->spokenEndsBesideSongs($log, $spans));
        $this->bank($log, 3707.2, [
            ['start' => 3706.15, 'end' => 3706.40, 'word' => ' sing'],
            ['start' => 3706.40, 'end' => 3706.55, 'word' => ' our'],
            ['start' => 3706.55, 'end' => 3706.80, 'word' => ' final'],
            ['start' => 3706.80, 'end' => 3707.15, 'word' => ' hymn.'],
        ]);

        $plan = $plans->forSpans($log, $spans, sermonEnd: true);

        $this->assertGreaterThanOrEqual(3707.15, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /**
     * 1112 under K16 ("Let's stand to sing, I am trusting you, Lord Jesus." was ruled right under
     * K7): a bare call to sing and the song's title are no conclusion. The sermon ends at its own
     * last line, inside its section.
     */
    #[Test]
    public function a_bare_call_to_sing_is_left_out_of_the_sermon(): void
    {
        $log = $this->service([
            ['start' => 3741.20, 'end' => 3745.90, 'text' => 'trusting him to make us holy by his blood.'],
            ['start' => 3746.42, 'end' => 3747.32, 'text' => "Let's stand to sing,"],
            ['start' => 3747.80, 'end' => 3749.92, 'text' => 'I am trusting you, Lord Jesus.'],
            ['start' => 3758.86, 'end' => 3764.78, 'text' => 'I am trusting you, Lord Jesus,'],
        ], [['sermon', 2051.20, 3755.0], ['song', 3755.0, 3885.0]], timeline: [[3740, 3750, 0.01, 0.7], [3750, 3900, 0.65, 0.02]]);
        $spans = [['start_time' => 2051.20, 'end_time' => 3755.0]];
        $this->introduction($log, $spans, [1, false, 1], [1, false, 1]);
        $this->bank($log, 3745.90, [
            ['start' => 3744.20, 'end' => 3744.40, 'word' => ' holy'],
            ['start' => 3744.45, 'end' => 3744.85, 'word' => ' by'],
            ['start' => 3744.90, 'end' => 3745.30, 'word' => ' his'],
            ['start' => 3745.30, 'end' => 3745.85, 'word' => ' blood.'],
            ['start' => 3746.50, 'end' => 3746.80, 'word' => " Let's"],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, $spans, sermonEnd: true);

        $this->assertGreaterThanOrEqual(3745.85, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(3746.50, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /**
     * 1111: the introduction is a section of its own. The sermon runs on through it to the line
     * the answer keeps, across the boundary the old rule stopped at.
     */
    #[Test]
    public function a_tied_introduction_in_a_section_of_its_own_joins_the_sermon(): void
    {
        $log = $this->service($this->quotationAcrossTheSongStart(), [['sermon', 1871.36, 3702.58], ['other', 3702.70, 3709.0], ['song', 3710.0, 3897.92]],
            timeline: [[3690, 3710, 0.01, 0.78], [3710, 3900, 0.8, 0.01]]);
        $spans = [['start_time' => 1871.36, 'end_time' => 3702.58]];
        $this->introduction($log, $spans, [1, true, 3], [1, true, 3]);
        $this->bank($log, 3706.0, [
            ['start' => 3704.70, 'end' => 3705.10, 'word' => ' tidings'],
            ['start' => 3705.10, 'end' => 3705.40, 'word' => ' all'],
            ['start' => 3705.40, 'end' => 3705.95, 'word' => ' around.'],
            ['start' => 3706.20, 'end' => 3706.60, 'word' => " Let's"],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, $spans, sermonEnd: true);

        $this->assertGreaterThanOrEqual(3705.95, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(3706.20, $plan['segments'][0]['end_time']);
    }

    /**
     * Never asked is evidence not yet gathered, as undecoded words are: the plan waits for the
     * edge step, which asks before it decodes. A re-cut route that plans first (`sermons:re-extract`)
     * therefore goes on to the edge step instead of refusing the run.
     */
    #[Test]
    public function an_end_beside_a_song_never_asked_about_waits_for_the_edge_step(): void
    {
        $log = $this->service($this->quotationAcrossTheSongStart(), [['sermon', 1871.36, 3702.58], ['song', 3702.70, 3897.92]],
            timeline: [[3690, 3710, 0.01, 0.78], [3710, 3900, 0.8, 0.01]]);
        $this->bank($log, 3702.58, [
            ['start' => 3701.70, 'end' => 3702.31, 'word' => ' command.'],
        ]);

        $this->expectException(OutputEdgeTimingsMissing::class);

        app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 1871.36, 'end_time' => 3702.58]], sermonEnd: true);
    }

    /** Asked, with no answer naming a line (the model failed): the end is the operator's. */
    #[Test]
    public function an_end_beside_a_song_with_no_answer_is_asked(): void
    {
        $log = $this->service($this->quotationAcrossTheSongStart(), [['sermon', 1871.36, 3702.58], ['song', 3702.70, 3897.92]],
            timeline: [[3690, 3710, 0.01, 0.78], [3710, 3900, 0.8, 0.01]]);
        $spans = [['start_time' => 1871.36, 'end_time' => 3702.58]];
        OpenAI::fake([new \RuntimeException('down'), new \RuntimeException('down')]);
        app(SermonSongIntroductionCheck::class)->prepare($log, $spans);
        $this->bank($log->fresh(), 3702.58, [
            ['start' => 3701.70, 'end' => 3702.31, 'word' => ' command.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), $spans, sermonEnd: true);

        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
        $this->assertSame(CueSafeExtractionPlan::SPOKEN_END_UNRESOLVED, collect($plan['cue_edge_widening'])->last()['reason']);
    }

    /** @return list<array{start: float, end: float, text: string}> */
    private function quotationAcrossTheSongStart(): array
    {
        return [
            ['start' => 3699.76, 'end' => 3702.58, 'text' => "Onward, tis our Lord's command."],
            ['start' => 3702.84, 'end' => 3704.54, 'text' => 'Jesus saves.'],
            ['start' => 3704.60, 'end' => 3706.00, 'text' => 'Spread the tidings all around.'],
            ['start' => 3706.10, 'end' => 3708.00, 'text' => "Let's stand and sing."],
            ['start' => 3736.74, 'end' => 3740.08, 'text' => 'Every man by the saints'],
        ];
    }

    /** @return list<array{start: float, end: float, text: string}> */
    private function unfinishedThought(): array
    {
        return [
            ['start' => 3699.76, 'end' => 3702.58, 'text' => "Onward, tis our Lord's command."],
            ['start' => 3702.84, 'end' => 3704.54, 'text' => 'Jesus saves.'],
            ['start' => 3704.54, 'end' => 3705.90, 'text' => "Let's stand and"],
            ['start' => 3706.10, 'end' => 3707.20, 'text' => 'sing our final hymn.'],
            ['start' => 3736.74, 'end' => 3740.08, 'text' => 'Every man by the saints'],
        ];
    }

    /**
     * Canary 11 (F07 after a reading, 6/6): the speech before a song is rightly left out of a
     * reading, and a reading inside the sermon's composition is not its end.
     */
    #[Test]
    public function only_the_sermon_outputs_end_runs_on_to_the_song(): void
    {
        $log = $this->service([
            ['start' => 3699.76, 'end' => 3702.58, 'text' => "Onward, tis our Lord's command."],
            ['start' => 3702.84, 'end' => 3704.54, 'text' => 'Jesus saves.'],
        ], [['bible_reading', 3600.0, 3702.0], ['song', 3706.01, 3897.92], ['sermon', 3900.0, 5000.0]]);

        $this->bank($log, 3702.0, []);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 3600.0, 'end_time' => 3702.0]]);

        $this->assertSame(3702.58, $plan['segments'][0]['end_time'], 'the cut line stays whole; the next is not taken');
    }

    /** Only a song's start pulls the end on: the next item spoken keeps its own boundary. */
    #[Test]
    public function a_sermon_end_beside_a_spoken_item_is_not_extended(): void
    {
        $log = $this->service([
            ['start' => 3699.76, 'end' => 3702.58, 'text' => 'and that is the end of my sermon.'],
            ['start' => 3702.84, 'end' => 3704.54, 'text' => 'Let us pray.'],
        ], [['sermon', 1871.36, 3702.58], ['prayer', 3702.84, 3760.0]]);

        $this->assertSame([], app(CueSafeExtractionPlan::class)->spokenEndsBesideSongs($log, [['start_time' => 1871.36, 'end_time' => 3702.58]]));
    }

    /**
     * 1117: the sermon's cut (3980.15) sits in a 0.6 s gap between "Amen." and "Well, let's stand
     * and sing", touching no cue, so no words were decoded and the cut was never checked; canary 11
     * heard the "Amen" lost. A wordless edge in a short gap between two lines is decoded, and the
     * cut goes in the pause nearest it.
     */
    #[Test]
    public function an_edge_in_a_short_gap_between_two_lines_is_decoded_and_placed_in_the_nearest_pause(): void
    {
        $amen = ['start' => 3979.52, 'end' => 3980.00, 'text' => 'Amen.'];
        $next = ['start' => 3980.58, 'end' => 3982.52, 'text' => "Well, let's stand and sing."];
        $log = $this->service([
            ['start' => 3974.44, 'end' => 3977.80, 'text' => "Hear our prayer. We ask it in Jesus' name. Amen."],
            $amen,
            $next,
        ], [['sermon', 2157.84, 3980.15], ['other', 3980.57, 4005.0], ['song', 4013.12, 4175.0]]);
        $evidence = app(OutputEdgeWordTimings::class);

        $window = $evidence->window($evidence->cues($log), 3980.15, $log->duration);

        $this->assertNotNull($window);
        $this->assertSame(['start' => 3978.52, 'end' => 3983.52], ['start' => $window['start'], 'end' => $window['end']]);
        $this->bank($log, 3980.15, [
            ['start' => 3976.90, 'end' => 3977.40, 'word' => ' name.'],
            ['start' => 3979.70, 'end' => 3980.38, 'word' => ' Amen.'],
            ['start' => 3980.66, 'end' => 3980.95, 'word' => ' Well,'],
            ['start' => 3981.00, 'end' => 3981.30, 'word' => " let's"],
            ['start' => 3981.30, 'end' => 3981.70, 'word' => ' stand'],
            ['start' => 3981.70, 'end' => 3981.90, 'word' => ' and'],
            ['start' => 3981.90, 'end' => 3982.40, 'word' => ' sing.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 2157.84, 'end_time' => 3980.15]]);

        $this->assertGreaterThanOrEqual(3980.38, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(3980.66, $plan['segments'][0]['end_time']);
    }

    /** A long gap between lines is no edge inside speech: it keeps the whole-cue rule, unread. */
    #[Test]
    public function an_edge_in_a_long_gap_is_not_decoded(): void
    {
        $log = $this->service([
            ['start' => 3747.80, 'end' => 3749.92, 'text' => 'I am trusting you, Lord Jesus.'],
            ['start' => 3758.86, 'end' => 3764.78, 'text' => 'I am trusting you, Lord Jesus,'],
        ], [['sermon', 2051.20, 3755.0]]);
        $evidence = app(OutputEdgeWordTimings::class);

        $this->assertNull($evidence->window($evidence->cues($log), 3755.0, $log->duration));
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{0: string, 1: float, 2: float}>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>|null  $timeline
     */
    private function service(array $cues, array $sections, ?array $timeline = null): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000,
            'audio_timeline_path' => $timeline !== null ? 'service-transcripts/spoken-end.classes.json' : null]);
        if ($timeline !== null) {
            Storage::disk('local')->put('service-transcripts/spoken-end.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        }
        $log->putServiceTranscriptPath('temp/spoken-end.json');
        Storage::disk('local')->put('temp/spoken-end.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        foreach ($sections as [$type, $start, $end]) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => ServiceSectionType::from($type),
                'start_time' => $start,
                'end_time' => $end,
            ]);
        }

        return $log->fresh();
    }

    /** @param list<array{start: float, end: float, word: string}> $words */
    private function bank(MediaProcessingLog $log, float $edge, array $words): void
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->window($evidence->cues($log), $edge, $log->duration);
        $this->assertNotNull($window);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
            'identity' => $evidence->identity($log, $window), 'words' => $words, 'compute_seconds' => 1.0,
        ]);
        $log->refresh();
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @param  array{0: int, 1: bool, 2: int}  ...$answers  sermon_last_line, ties_to_sermon, video_last_line
     */
    private function introduction(MediaProcessingLog $log, array $spans, array ...$answers): void
    {
        OpenAI::fake(array_map(static fn (array $answer): CreateResponse => CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => $answer[0], 'ties_to_sermon' => $answer[1], 'video_last_line' => $answer[2], 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]]), $answers));
        app(SermonSongIntroductionCheck::class)->prepare($log, $spans);
        $log->refresh();
    }
}
