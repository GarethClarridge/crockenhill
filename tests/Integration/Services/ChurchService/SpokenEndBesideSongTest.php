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
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Canary 12 (operator, 2026-10-06): sermon ends extended to the song after them (A) landed
 * mid-word or mid-sentence. 1028 clipped half of "nine" ("Number 749."); 1304 kept most of a
 * quoted hymn verse but not its last two lines. The spoken stretch before the singing goes into
 * the sermon whole, and the cut sits in the pause after its last word, not on the decoded end of
 * that word. 1112's extended end (ruled right) sits in a pause already and stays.
 */
class SpokenEndBesideSongTest extends TestCase
{
    use DatabaseTransactions;

    /** 1028: the edge falls inside "749"; the decode ends "49" early, so the cut keeps a tail. */
    #[Test]
    public function a_sermon_end_beside_a_song_keeps_a_tail_after_its_last_word(): void
    {
        $log = $this->service([
            ['start' => 4111.56, 'end' => 4113.60, 'text' => 'Oh, how the grace of God amazes me.'],
            ['start' => 4113.66, 'end' => 4115.72, 'text' => 'Number 749.'],
            ['start' => 4130.92, 'end' => 4137.10, 'text' => 'The grace of God'],
        ], [['sermon', 1965.95, 4115.0], ['song', 4115.0, 4303.18]], timeline: [[4100, 4120, 0.01, 0.75], [4120, 4300, 0.66, 0.02]]);
        $this->bank($log, 4115.72, [
            ['start' => 4112.78, 'end' => 4113.60, 'word' => ' amazes me.'],
            ['start' => 4113.66, 'end' => 4113.80, 'word' => ' Number'],
            ['start' => 4113.80, 'end' => 4114.43, 'word' => ' 7'],
            ['start' => 4114.45, 'end' => 4115.78, 'word' => '49.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 1965.95, 'end_time' => 4115.0]], sermonEnd: true);

        $this->assertGreaterThanOrEqual(4115.98, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(4116.72, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']), 'the hymn is sung next: the stretch is whole');
    }

    /**
     * 1304: the section ends at 3702.58, in the middle of the preacher quoting the hymn, while the
     * song starts at 3706.01 after "Let's stand and sing". The quotation and the call to stand are
     * the spoken stretch before the singing: wholly in.
     */
    #[Test]
    public function a_sermon_end_beside_a_song_takes_the_whole_spoken_stretch_before_the_singing(): void
    {
        $log = $this->service([
            ['start' => 3697.06, 'end' => 3699.66, 'text' => 'Climb the steeps and cross the waves.'],
            ['start' => 3699.76, 'end' => 3702.58, 'text' => "Onward, tis our Lord's command."],
            ['start' => 3702.84, 'end' => 3704.54, 'text' => 'Jesus saves.'],
            ['start' => 3704.54, 'end' => 3705.48, 'text' => "Let's stand and sing."],
            ['start' => 3706.74, 'end' => 3708.74, 'text' => 'Amen.'],
            ['start' => 3736.74, 'end' => 3740.08, 'text' => 'Every man by the saints'],
        ], [['sermon', 1871.36, 3702.58], ['song', 3706.01, 3897.92]], timeline: [[3690, 3705, 0.01, 0.77], [3705, 3710, 0.59, 0.49], [3710, 3900, 0.8, 0.01]]);
        $this->bank($log, 3705.48, [
            ['start' => 3703.20, 'end' => 3704.02, 'word' => ' saves.'],
            ['start' => 3704.64, 'end' => 3704.87, 'word' => " Let's"],
            ['start' => 3705.10, 'end' => 3705.66, 'word' => ' stand'],
            ['start' => 3705.66, 'end' => 3706.01, 'word' => ' and'],
            ['start' => 3706.01, 'end' => 3706.47, 'word' => ' sing.'],
        ]);

        $plans = app(CueSafeExtractionPlan::class);
        $plan = $plans->forSpans($log, [['start_time' => 1871.36, 'end_time' => 3702.58]], sermonEnd: true);

        $this->assertGreaterThanOrEqual(3706.47, $plan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(3706.74, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
        $this->assertSame([3705.48], $plans->spokenEndsBesideSongs($log, [['start_time' => 1871.36, 'end_time' => 3702.58]]),
            'the edge step decodes the window the extended end reads');
    }

    /**
     * Codex review: a line before the song's recorded start is not a whole thought. "Let's stand
     * and | sing our final hymn." continues in a line that starts after it; where the classifier
     * hears that line as speech, it goes in too.
     */
    #[Test]
    public function a_thought_continuing_past_the_songs_recorded_start_goes_in_whole(): void
    {
        $log = $this->service($this->unfinishedThought(), [['sermon', 1871.36, 3702.58], ['song', 3706.01, 3897.92]],
            timeline: [[3600, 3710, 0.05, 0.8], [3710, 3900, 0.8, 0.02]]);
        $plans = app(CueSafeExtractionPlan::class);
        $this->assertSame([3707.2], $plans->spokenEndsBesideSongs($log, [['start_time' => 1871.36, 'end_time' => 3702.58]]));
        $this->bank($log, 3707.2, [
            ['start' => 3706.15, 'end' => 3706.40, 'word' => ' sing'],
            ['start' => 3706.40, 'end' => 3706.55, 'word' => ' our'],
            ['start' => 3706.55, 'end' => 3706.80, 'word' => ' final'],
            ['start' => 3706.80, 'end' => 3707.15, 'word' => ' hymn.'],
        ]);

        $plan = $plans->forSpans($log, [['start_time' => 1871.36, 'end_time' => 3702.58]], sermonEnd: true);

        $this->assertGreaterThanOrEqual(3707.15, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /** Where nothing shows the next line is speech, the unfinished thought is a question, not a cut. */
    #[Test]
    public function an_unfinished_thought_the_evidence_cannot_complete_is_unresolved(): void
    {
        $log = $this->service($this->unfinishedThought(), [['sermon', 1871.36, 3702.58], ['song', 3706.01, 3897.92]],
            timeline: [[3600, 3705, 0.05, 0.8], [3705, 3900, 0.8, 0.02]]);
        $this->bank($log, 3705.9, [
            ['start' => 3704.64, 'end' => 3704.87, 'word' => " Let's"],
            ['start' => 3705.10, 'end' => 3705.40, 'word' => ' stand'],
            ['start' => 3705.40, 'end' => 3705.85, 'word' => ' and'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 1871.36, 'end_time' => 3702.58]], sermonEnd: true);

        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
        $this->assertSame(CueSafeExtractionPlan::SPOKEN_END_UNRESOLVED, collect($plan['cue_edge_widening'])->last()['reason']);
    }

    /**
     * Codex review: whisper punctuates one thought as two sentences ("…let's have our morning
     * reading. | It's quite a short one.", canary 11). A quotation that goes on past the song's
     * recorded start after a full stop is still the preacher speaking: where the classifier hears
     * the next line as speech it goes in, whatever the punctuation.
     */
    #[Test]
    public function a_full_stop_does_not_end_the_spoken_stretch_while_speech_goes_on(): void
    {
        $log = $this->service($this->quotationAcrossTheSongStart(), [['sermon', 1871.36, 3702.58], ['song', 3702.70, 3897.92]],
            timeline: [[3690, 3710, 0.01, 0.78], [3710, 3900, 0.8, 0.01]]);
        $plans = app(CueSafeExtractionPlan::class);
        $this->assertSame([3708.0], $plans->spokenEndsBesideSongs($log, [['start_time' => 1871.36, 'end_time' => 3702.58]]));
        $this->bank($log, 3708.0, [
            ['start' => 3706.20, 'end' => 3706.60, 'word' => " Let's"],
            ['start' => 3706.60, 'end' => 3707.00, 'word' => ' stand'],
            ['start' => 3707.00, 'end' => 3707.20, 'word' => ' and'],
            ['start' => 3707.20, 'end' => 3707.80, 'word' => ' sing.'],
        ]);

        $plan = $plans->forSpans($log, [['start_time' => 1871.36, 'end_time' => 3702.58]], sermonEnd: true);

        $this->assertGreaterThanOrEqual(3707.8, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /** Where the classifier cannot say whether the line after a full stop is spoken, it is asked. */
    #[Test]
    public function a_full_stop_followed_by_a_line_of_unknown_kind_is_unresolved(): void
    {
        $log = $this->service($this->quotationAcrossTheSongStart(), [['sermon', 1871.36, 3702.58], ['song', 3702.70, 3897.92]],
            timeline: [[3690, 3700, 0.01, 0.78], [3700, 3900, 0.05, 0.05]]);
        $this->bank($log, 3702.58, [
            ['start' => 3701.70, 'end' => 3702.31, 'word' => ' command.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 1871.36, 'end_time' => 3702.58]], sermonEnd: true);

        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
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

    /** 1112 (ruled right): the speech ended before the section did; the end stays in its pause. */
    #[Test]
    public function a_sermon_end_already_after_the_spoken_stretch_stays(): void
    {
        $log = $this->service([
            ['start' => 3746.42, 'end' => 3747.32, 'text' => "Let's stand to sing,"],
            ['start' => 3747.80, 'end' => 3749.92, 'text' => 'I am trusting you, Lord Jesus.'],
            ['start' => 3758.86, 'end' => 3764.78, 'text' => 'I am trusting you, Lord Jesus,'],
        ], [['sermon', 2051.20, 3755.0], ['song', 3755.0, 3885.0]], timeline: [[3740, 3750, 0.01, 0.7], [3750, 3900, 0.65, 0.02]]);

        $plans = app(CueSafeExtractionPlan::class);
        $plan = $plans->forSpans($log, [['start_time' => 2051.20, 'end_time' => 3755.0]], sermonEnd: true);

        $this->assertSame(3755.0, $plan['segments'][0]['end_time']);
        $this->assertSame([], $plans->spokenEndsBesideSongs($log, [['start_time' => 2051.20, 'end_time' => 3755.0]]));
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
}
