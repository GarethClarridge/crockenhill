<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\DependentSongOpening;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\Media\Audio\LocalWhisperServiceTranscriptionService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\MediaProcessingVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Audio\TranscriptionResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class WordTimedOutputEdgesTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    #[DataProvider('edgeCases')]
    public function output_edges_use_the_largest_adjacent_pause(string $edge, float $original, array $cue, array $words, float $expected): void
    {
        $log = $this->log($cue);
        $this->bank($log, $cue, $words);
        $span = ['start_time' => $edge === 'start' ? $original : 10.0, 'end_time' => $edge === 'end' ? $original : 5000.0];
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [$span]);
        $this->assertEqualsWithDelta($expected, $plan['segments'][0][$edge.'_time'], 0.001);
        $this->assertSame($original, $plan['cue_edge_widening'][0]['original_time']);
    }

    public static function edgeCases(): array
    {
        return [
            '936 prayer name then amen then sermon' => ['start', 2594.18, ['start' => 2593.98, 'end' => 2594.38, 'text' => 'name.'], [
                ['start' => 2593.0, 'end' => 2593.98, 'word' => 'Jesus'],
                ['start' => 2593.98, 'end' => 2594.38, 'word' => 'name.'],
                ['start' => 2594.4, 'end' => 2594.55, 'word' => 'Amen.'],
                ['start' => 2595.3, 'end' => 2595.38, 'word' => 'Well'],
            ], 2594.55],
            '1346 prayer and talk share cue' => ['start', 366.001, ['start' => 362.08, 'end' => 366.41, 'text' => 'things in Jesus name and for his sake. Amen. Well, I'], [
                ['start' => 361.1, 'end' => 362.08, 'word' => 'these'],
                ['start' => 362.08, 'end' => 364.8, 'word' => 'prayer'],
                ['start' => 364.9, 'end' => 365.1, 'word' => 'Amen.'],
                ['start' => 366.0, 'end' => 366.2, 'word' => 'Well,'],
                ['start' => 366.25, 'end' => 366.5, 'word' => 'I'],
                ['start' => 366.55, 'end' => 367.0, 'word' => 'wonder'],
            ], 366.0],
            '949 exclude prayer include thank you Mark' => ['start', 2744.99, ['start' => 2738.96, 'end' => 2744.99, 'text' => 'him in jesus name we ask amen thank you mark it is such a'], [
                ['start' => 2738.0, 'end' => 2738.9, 'word' => 'through'],
                ['start' => 2739.0, 'end' => 2740.1, 'word' => 'prayer'],
                ['start' => 2740.2, 'end' => 2740.4, 'word' => 'amen'],
                ['start' => 2741.5, 'end' => 2741.7, 'word' => 'thank'],
                ['start' => 2741.75, 'end' => 2744.99, 'word' => 'you Mark it is such a'],
                ['start' => 2745.0, 'end' => 2745.5, 'word' => 'privilege'],
            ], 2741.5],
            '1342 music cue must not swallow song' => ['end', 1581.8, ['start' => 1581.7, 'end' => 1621.0, 'text' => 'sung words'], [
                ['start' => 1580.8, 'end' => 1581.5, 'word' => 'Amen'],
                ['start' => 1584.0, 'end' => 1621.5, 'word' => 'singing'],
            ], 1581.8],
            '1148 thank you stretched over music tail' => ['end', 100.3, ['start' => 100.0, 'end' => 130.0, 'text' => 'Thank you.'], [
                ['start' => 100.0, 'end' => 100.2, 'word' => 'Thank'],
                ['start' => 100.2, 'end' => 100.5, 'word' => 'you.'],
            ], 100.8],
            // whisper.cpp marks a new word with a leading space; "ibles" continues " B".
            '1208 sub-word gap inside Bibles is not a pause' => ['start', 1400.2, ['start' => 1399.0, 'end' => 1401.0, 'text' => 'turn your Bibles, to'], [
                ['start' => 1398.0, 'end' => 1398.9, 'word' => ' Well'],
                ['start' => 1399.0, 'end' => 1399.33, 'word' => ' turn'],
                ['start' => 1399.33, 'end' => 1399.83, 'word' => ' your'],
                ['start' => 1399.83, 'end' => 1399.95, 'word' => ' B'],
                ['start' => 1400.42, 'end' => 1400.58, 'word' => 'ibles'],
                ['start' => 1400.58, 'end' => 1400.7, 'word' => ','],
                ['start' => 1400.75, 'end' => 1401.0, 'word' => ' to'],
                ['start' => 1401.05, 'end' => 1401.95, 'word' => ' Genesis'],
            ], 1399.0],
            // An edge on a cue boundary keeps the whole cue: the window margin before "And" is the
            // largest gap, but cutting there drops the reading's last verse.
            '949 reading end keeps its last verse' => ['end', 2447.26, ['start' => 2444.9, 'end' => 2447.26, 'text' => 'And he bowed his head and gave up his spirit.'], [
                ['start' => 2444.94, 'end' => 2445.1, 'word' => ' And'],
                ['start' => 2445.12, 'end' => 2445.3, 'word' => ' he'],
                ['start' => 2445.32, 'end' => 2445.6, 'word' => ' bowed'],
                ['start' => 2445.62, 'end' => 2445.75, 'word' => ' his'],
                ['start' => 2445.78, 'end' => 2446.0, 'word' => ' head'],
                ['start' => 2446.05, 'end' => 2446.15, 'word' => ' and'],
                ['start' => 2446.18, 'end' => 2446.4, 'word' => ' gave'],
                ['start' => 2446.42, 'end' => 2446.5, 'word' => ' up'],
                ['start' => 2446.52, 'end' => 2446.6, 'word' => ' his'],
                ['start' => 2446.62, 'end' => 2447.3, 'word' => ' spirit.'],
            ], 2447.6],
            // Whisper times "During" before the cue starts; the cue still opens with it.
            '964 sermon start keeps its first word' => ['start', 2069.1, ['start' => 2069.1, 'end' => 2075.23, 'text' => 'During the feast of tabernacles, one of the Jewish feasts,'], [
                ['start' => 2068.25, 'end' => 2068.98, 'word' => ' During'],
                ['start' => 2069.34, 'end' => 2069.42, 'word' => ' the'],
                ['start' => 2069.42, 'end' => 2070.01, 'word' => ' Feast'],
                ['start' => 2070.28, 'end' => 2070.44, 'word' => ' of'],
                ['start' => 2070.44, 'end' => 2072.16, 'word' => ' Tabernacles,'],
                ['start' => 2072.17, 'end' => 2072.3, 'word' => ' one'],
                ['start' => 2072.3, 'end' => 2072.5, 'word' => ' of'],
                ['start' => 2072.5, 'end' => 2072.86, 'word' => ' the'],
                ['start' => 2072.86, 'end' => 2074.21, 'word' => ' Jewish'],
                ['start' => 2074.21, 'end' => 2076.22, 'word' => ' feasts,'],
            ], 2068.25],
            // The later "the" is nearer the boundary; only the cue's opening run of words finds its start.
            'repeated opening word anchors on the opening phrase' => ['start', 10.0, ['start' => 10.0, 'end' => 12.0, 'text' => 'the word of the Lord'], [
                ['start' => 9.2, 'end' => 9.35, 'word' => ' the'],
                ['start' => 9.4, 'end' => 9.7, 'word' => ' word'],
                ['start' => 9.75, 'end' => 9.95, 'word' => ' of'],
                ['start' => 10.1, 'end' => 10.2, 'word' => ' the'],
                ['start' => 10.25, 'end' => 10.8, 'word' => ' Lord'],
            ], 9.2],
            'repeated closing word anchors on the closing phrase' => ['end', 102.0, ['start' => 100.0, 'end' => 102.0, 'text' => 'glory unto him'], [
                ['start' => 100.5, 'end' => 100.95, 'word' => ' glory'],
                ['start' => 101.0, 'end' => 101.3, 'word' => ' unto'],
                ['start' => 101.35, 'end' => 101.6, 'word' => ' him'],
                ['start' => 101.7, 'end' => 101.95, 'word' => ' praise'],
                ['start' => 102.0, 'end' => 102.2, 'word' => ' him'],
            ], 101.7],
            // "amen" is still sounding when the cue's first word starts: no gap there to cut in.
            'start anchor overlapped by a sounding word cuts before it' => ['start', 10.0, ['start' => 10.0, 'end' => 12.0, 'text' => 'the Lord'], [
                ['start' => 9.8, 'end' => 10.5, 'word' => ' amen'],
                ['start' => 10.0, 'end' => 10.3, 'word' => ' the'],
                ['start' => 10.35, 'end' => 10.8, 'word' => ' Lord'],
            ], 9.8],
            'end anchor overlapped by a sounding word cuts after it' => ['end', 20.0, ['start' => 18.5, 'end' => 20.0, 'text' => 'his spirit'], [
                ['start' => 19.0, 'end' => 19.3, 'word' => ' his'],
                ['start' => 19.35, 'end' => 19.9, 'word' => ' spirit'],
                ['start' => 19.7, 'end' => 20.4, 'word' => ' and'],
                ['start' => 20.5, 'end' => 20.8, 'word' => ' then'],
            ], 20.45],
            '1267 sorry stretched over music tail' => ['start', 129.9, ['start' => 100.0, 'end' => 130.0, 'text' => "I'm sorry."], [
                ['start' => 100.0, 'end' => 100.2, 'word' => "I'm"],
                ['start' => 100.2, 'end' => 100.5, 'word' => 'sorry.'],
            ], 129.9],
        ];
    }

    /**
     * The cue's opening phrase is heard twice within reach, so the words cannot say which one the
     * cue opens with (F03). The largest pause cut after both, dropping the cue's own words; the
     * edge must instead stay unresolved for review, and the cut must keep every occurrence it
     * might be.
     */
    #[Test]
    public function an_ambiguous_opening_phrase_is_unresolved_and_the_cut_keeps_every_occurrence(): void
    {
        $cue = ['start' => 10.0, 'end' => 11.0, 'text' => 'thank you'];
        $log = $this->log($cue);
        $this->bank($log, $cue, [
            ['start' => 9.5, 'end' => 9.7, 'word' => ' thank'],
            ['start' => 9.7, 'end' => 9.9, 'word' => ' you'],
            ['start' => 10.1, 'end' => 10.3, 'word' => ' thank'],
            ['start' => 10.3, 'end' => 10.5, 'word' => ' you'],
            ['start' => 11.6, 'end' => 11.9, 'word' => ' so'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 10.0, 'end_time' => 5000.0]]);

        $this->assertLessThanOrEqual(9.5, $plan['segments'][0]['start_time']);
        $this->assertSame(CueSafeExtractionPlan::AMBIGUOUS_CUE_ANCHOR, $plan['cue_edge_widening'][0]['reason']);
        $this->assertSame([['span_index' => 0, 'edge' => 'start', 'original_time' => 10.0]], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    #[Test]
    public function an_ambiguous_closing_phrase_is_unresolved_and_the_cut_keeps_every_occurrence(): void
    {
        $cue = ['start' => 18.0, 'end' => 20.0, 'text' => 'thank you'];
        $log = $this->log($cue);
        $this->bank($log, $cue, [
            ['start' => 18.0, 'end' => 18.3, 'word' => ' thank'],
            ['start' => 18.3, 'end' => 18.6, 'word' => ' you'],
            ['start' => 19.6, 'end' => 19.8, 'word' => ' thank'],
            ['start' => 19.8, 'end' => 20.4, 'word' => ' you'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 5.0, 'end_time' => 20.0]]);

        $this->assertGreaterThanOrEqual(20.4, $plan['segments'][0]['end_time']);
        $this->assertSame([['span_index' => 0, 'edge' => 'end', 'original_time' => 20.0]], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    #[Test]
    public function a_resolved_edge_is_not_reported_unresolved(): void
    {
        $cue = ['start' => 10.0, 'end' => 12.0, 'text' => 'the word of the Lord'];
        $log = $this->log($cue);
        $this->bank($log, $cue, [
            ['start' => 9.2, 'end' => 9.35, 'word' => ' the'],
            ['start' => 9.4, 'end' => 9.7, 'word' => ' word'],
            ['start' => 9.75, 'end' => 9.95, 'word' => ' of'],
            ['start' => 10.1, 'end' => 10.2, 'word' => ' the'],
            ['start' => 10.25, 'end' => 10.8, 'word' => ' Lord'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 10.0, 'end_time' => 5000.0]]);

        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /** Run 949: the prayer's cue runs on into the sermon, so the boundary between cues is shared. */
    #[Test]
    public function a_start_shared_with_the_previous_cue_keeps_the_largest_pause(): void
    {
        $prayer = ['start' => 2738.96, 'end' => 2744.99, 'text' => 'him in jesus name we ask amen thank you mark it is such a'];
        $sermon = ['start' => 2744.99, 'end' => 2749.0, 'text' => 'joy to be with you'];
        $log = $this->log($prayer);
        Storage::disk('local')->put('temp/edge-test.json', json_encode(ChurchServiceTranscript::fromCues([$prayer, $sermon], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->window($evidence->cues($log), 2744.99, 5000.0);
        $this->assertCount(2, $window['cues']);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), ['identity' => $evidence->identity($log, $window), 'words' => [
            ['start' => 2739.0, 'end' => 2740.1, 'word' => ' prayer'],
            ['start' => 2740.2, 'end' => 2740.4, 'word' => ' amen'],
            ['start' => 2741.5, 'end' => 2741.7, 'word' => ' thank'],
            ['start' => 2741.75, 'end' => 2744.99, 'word' => ' you Mark it is such a'],
            ['start' => 2745.0, 'end' => 2745.5, 'word' => ' joy'],
            ['start' => 2745.55, 'end' => 2748.9, 'word' => ' to be with you'],
        ], 'compute_seconds' => 1.0]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2744.99, 'end_time' => 4000.0]]);

        $this->assertEqualsWithDelta(2741.5, $plan['segments'][0]['start_time'], 0.001);
    }

    /**
     * Canary 13 listening: the largest pause was a mid-sentence gap before an end on a line that
     * closes its sentence (1286 "and | preach"), or the window margin before a smeared word, 17 s
     * into the item before (1273's 5.7 s "Let's"; 30 s for 1311's 15 s "The" under a hallucinated
     * "Thank you."). 949's shared start still takes the largest pause (its own test).
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start: float, end: float, word: string}>  $words
     */
    #[Test]
    #[DataProvider('canary13MisplacedEdges')]
    public function an_edge_keeps_a_closing_sentence_and_ignores_gaps_beside_smeared_words(string $edge, float $original, array $cues, array $words, float $expected): void
    {
        $log = $this->logWithCues($cues);
        $this->bankWindow($log, $original, $words);
        $span = ['start_time' => $edge === 'start' ? $original : 10.0, 'end_time' => $edge === 'end' ? $original : 4900.0];

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [$span]);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0][$edge.'_time'], 0.001);
        $this->assertEqualsWithDelta($expected, app(CueSafeExtractionPlan::class)
            ->wordPauseEdge($log->fresh(), app(OutputEdgeWordTimings::class)->cues($log), $original, $edge)['time'], 0.001);
    }

    public static function canary13MisplacedEdges(): array
    {
        return [
            '1286 reading end before the next announcement' => ['end', 1485.86, [
                ['start' => 1481.36, 'end' => 1485.86, 'text' => 'and Mark will come and preach on those verses soon.'],
                ['start' => 1485.86, 'end' => 1487.76, 'text' => "We're going to sing two songs now,"],
            ], [
                ['start' => 1481.48, 'end' => 1481.66, 'word' => ' and'],
                ['start' => 1481.76, 'end' => 1481.96, 'word' => ' Mark'],
                ['start' => 1481.97, 'end' => 1482.25, 'word' => ' will'],
                ['start' => 1482.25, 'end' => 1482.53, 'word' => ' come'],
                ['start' => 1482.53, 'end' => 1482.65, 'word' => ' and'],
                ['start' => 1482.87, 'end' => 1483.54, 'word' => ' preach'],
                ['start' => 1483.54, 'end' => 1483.67, 'word' => ' on'],
                ['start' => 1483.76, 'end' => 1484.01, 'word' => ' those'],
                ['start' => 1484.01, 'end' => 1484.44, 'word' => ' verses'],
                ['start' => 1484.44, 'end' => 1485.88, 'word' => ' soon.'],
                ['start' => 1485.88, 'end' => 1488.75, 'word' => " We're"],
                ['start' => 1488.75, 'end' => 1488.75, 'word' => ' going'],
                ['start' => 1488.75, 'end' => 1488.75, 'word' => ' to'],
            ], 1485.88],
            '1273 song start after its announcement' => ['start', 563.64, [
                ['start' => 547.26, 'end' => 563.64, 'text' => "Let's remain standing as we sing quietly through together."],
                ['start' => 563.64, 'end' => 564.74, 'text' => 'verse 477,'],
            ], [
                ['start' => 546.4, 'end' => 552.09, 'word' => " Let's"],
                ['start' => 552.09, 'end' => 559.13, 'word' => ' remain'],
                ['start' => 559.13, 'end' => 559.7, 'word' => ' standing'],
                ['start' => 559.7, 'end' => 560.22, 'word' => ' as'],
                ['start' => 560.22, 'end' => 560.74, 'word' => ' we'],
                ['start' => 560.74, 'end' => 561.78, 'word' => ' sing'],
                ['start' => 561.78, 'end' => 562.37, 'word' => ' quietly'],
                ['start' => 562.37, 'end' => 562.96, 'word' => ' through'],
                ['start' => 562.96, 'end' => 563.64, 'word' => ' together'],
                ['start' => 563.64, 'end' => 564.74, 'word' => ' 477'],
                ['start' => 564.74, 'end' => 564.87, 'word' => ' or'],
                ['start' => 564.87, 'end' => 565.4, 'word' => ' heavenly'],
                ['start' => 565.4, 'end' => 565.73, 'word' => ' clear.'],
            ], 563.64],
            '1311 song start after a hallucinated line' => ['start', 1656.08, [
                ['start' => 1626.1, 'end' => 1656.08, 'text' => 'Thank you.'],
            ], [
                ['start' => 1625.5, 'end' => 1640.09, 'word' => ' The'],
                ['start' => 1640.09, 'end' => 1655.08, 'word' => ' End'],
                ['start' => 1655.27, 'end' => 1657.08, 'word' => ' Oh,'],
                ['start' => 1657.08, 'end' => 1657.08, 'word' => ' God.'],
            ], 1655.27],
            // Canary 14: with its section start back at 1626.1 the cut jumped 29 s later, past the
            // smeared "The" and "End" to the gap before "Oh,". Words of unknown timing are not a
            // gap to skip: with no placeable pause the edge stays where detection put it.
            '1311 song start on a hallucinated line keeps its detected start' => ['start', 1626.1, [
                ['start' => 1626.1, 'end' => 1656.08, 'text' => 'Thank you.'],
            ], [
                ['start' => 1625.5, 'end' => 1640.09, 'word' => ' The'],
                ['start' => 1640.09, 'end' => 1655.08, 'word' => ' End'],
                ['start' => 1655.27, 'end' => 1657.08, 'word' => ' Oh,'],
                ['start' => 1657.08, 'end' => 1657.08, 'word' => ' God.'],
            ], 1626.1],
            // 10-26 listening: the window opens a second before "426", while "just been" is still
            // being said. Its margin before the first decoded word is clipped speech, not a pause:
            // taking it started the song on the sermon's "talking about".
            '1216 song start after the sermon’s closing words' => ['start', 4185.0, [
                ['start' => 4183.46, 'end' => 4184.08, 'text' => 'just been'],
                ['start' => 4184.08, 'end' => 4184.84, 'text' => 'talking about'],
                ['start' => 4184.84, 'end' => 4186.52, 'text' => '426'],
            ], [
                ['start' => 4183.98, 'end' => 4183.98, 'word' => ' in'],
                ['start' => 4183.98, 'end' => 4184.43, 'word' => ' talking'],
                ['start' => 4184.48, 'end' => 4184.82, 'word' => ' about'],
                ['start' => 4184.82, 'end' => 4186.50, 'word' => ' 426,'],
                ['start' => 4186.55, 'end' => 4186.96, 'word' => ' how'],
                ['start' => 4186.96, 'end' => 4187.51, 'word' => ' deep...'],
            ], 4184.82],
        ];
    }

    /**
     * 1050's reading ends where its sermon starts. Placing both edges ended the reading after
     * "do good." and started the sermon after "…the apostle Peter.", dropping the sermon's first
     * sentence once the parts no longer overlapped: parts that meet are one stretch, not a cut.
     */
    #[Test]
    public function parts_that_meet_are_not_cut_between(): void
    {
        $cues = [
            ['start' => 76.94, 'end' => 79.0, 'text' => 'do good.'],
            ['start' => 79.0, 'end' => 85.15, 'text' => 'This letter, this epistle was written by the apostle Peter'],
        ];
        $log = $this->logWithCues($cues);
        $this->bankWindow($log, 79.0, [
            ['start' => 76.09, 'end' => 76.3, 'word' => ' to'],
            ['start' => 76.96, 'end' => 77.38, 'word' => ' do'],
            ['start' => 77.38, 'end' => 78.54, 'word' => ' good.'],
            ['start' => 79.42, 'end' => 79.42, 'word' => ' This'],
            ['start' => 79.57, 'end' => 80.4, 'word' => ' letter,'],
            ['start' => 80.44, 'end' => 80.77, 'word' => ' this'],
            ['start' => 80.77, 'end' => 81.16, 'word' => ' epistle'],
            ['start' => 81.7, 'end' => 81.84, 'word' => ' was'],
            ['start' => 81.84, 'end' => 82.07, 'word' => ' written'],
            ['start' => 83.16, 'end' => 83.16, 'word' => ' by'],
            ['start' => 83.27, 'end' => 83.76, 'word' => ' the'],
            ['start' => 83.9, 'end' => 84.45, 'word' => ' Apostle'],
            ['start' => 84.45, 'end' => 85.02, 'word' => ' Peter.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [
            ['start_time' => 10.0, 'end_time' => 79.0], ['start_time' => 79.0, 'end_time' => 4900.0],
        ]);

        // Still met at 79.0, not moved: each part keeps its own sound treatment (Codex review, 2026-10-13).
        $this->assertEqualsWithDelta([['start_time' => 10.0, 'end_time' => 79.0], ['start_time' => 79.0, 'end_time' => 4900.0]], $plan['segments'], 0.001);
    }

    /**
     * 1105's sermon ends on "Amen." heard twice within reach of the edge: a closing sentence that
     * cannot be placed once keeps the largest pause it had (ruled right), not an unresolved edge.
     */
    #[Test]
    public function a_closing_sentence_heard_twice_keeps_the_largest_pause(): void
    {
        $cues = [
            ['start' => 1398.0, 'end' => 1399.0, 'text' => 'Amen.'],
            ['start' => 1399.0, 'end' => 1400.0, 'text' => 'Shall we sing again?'],
        ];
        $log = $this->logWithCues($cues);
        $this->bankWindow($log, 1399.0, [
            ['start' => 1397.14, 'end' => 1397.47, 'word' => ' Amen.'],
            ['start' => 1398.92, 'end' => 1399.32, 'word' => ' Amen.'],
            ['start' => 1399.55, 'end' => 1399.55, 'word' => ' Shall'],
            ['start' => 1399.58, 'end' => 1399.64, 'word' => ' we'],
            ['start' => 1399.64, 'end' => 1399.82, 'word' => ' sing'],
            ['start' => 1399.82, 'end' => 1400.21, 'word' => ' again?'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 1300.0, 'end_time' => 1399.0]]);

        $this->assertEqualsWithDelta(1398.92, $plan['segments'][0]['end_time'], 0.001);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /**
     * 941's sermon ends on its own line "Amen." (K16), and "Amen." is heard twice within reach: once
     * just before the line and once inside it. At an end, the cut after the later one keeps both,
     * so a closing sentence heard twice is placed, as 1105's was, not left as a question; it held
     * 941, 1025, 1066 and 1236 at manual review in the re-cut round.
     */
    #[Test]
    public function a_closing_sentence_heard_twice_on_its_own_line_ends_after_the_later_one(): void
    {
        $cue = ['start' => 4178.82, 'end' => 4179.82, 'text' => 'Amen.'];
        $log = $this->log($cue);
        $this->bank($log, $cue, [
            ['start' => 4177.94, 'end' => 4178.22, 'word' => ' Amen.'],
            ['start' => 4179.32, 'end' => 4179.74, 'word' => ' Amen.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 4000.0, 'end_time' => 4179.82]]);

        $this->assertGreaterThanOrEqual(4179.74, $plan['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /**
     * 1300's sermon opens on its own line "Politicians.", which the edge decoding heard twice; the
     * operator heard it once (2026-10-18). At a start, the cut before the earlier one keeps both, so
     * an opening sentence heard twice is placed, as a closing one is at an end, not left as a question.
     */
    #[Test]
    public function an_opening_sentence_heard_twice_on_its_own_line_starts_before_the_earlier_one(): void
    {
        $cue = ['start' => 2105.24, 'end' => 2106.2, 'text' => 'Politicians.'];
        $log = $this->log($cue);
        $this->bank($log, $cue, [
            ['start' => 2104.38, 'end' => 2106.09, 'word' => ' Politicians.'],
            ['start' => 2106.32, 'end' => 2107.2, 'word' => ' Politicians.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2105.24, 'end_time' => 3862.2]]);

        $this->assertLessThanOrEqual(2104.38, $plan['segments'][0]['start_time']);
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /**
     * 1292's reading ends on "old." decoded to 1506.0000000000002 in a window ending at 1506: no
     * gap after its last word, so the cut fell back to the window margin before "The man who…",
     * dropping the reading's last verse.
     */
    #[Test]
    public function a_decode_ending_on_the_closing_word_keeps_it(): void
    {
        $cue = ['start' => 1501.12, 'end' => 1505.0, 'text' => 'The man who was miraculously healed was over 40 years old.'];
        $log = $this->logWithCues([$cue]);
        $this->bankWindow($log, 1505.0, [
            ['start' => 1500.44, 'end' => 1500.44, 'word' => ' The'],
            ['start' => 1500.59, 'end' => 1500.76, 'word' => ' man'],
            ['start' => 1500.76, 'end' => 1501.08, 'word' => ' who'],
            ['start' => 1501.09, 'end' => 1501.38, 'word' => ' was'],
            ['start' => 1501.48, 'end' => 1502.69, 'word' => ' miraculously'],
            ['start' => 1502.69, 'end' => 1503.33, 'word' => ' healed'],
            ['start' => 1503.34, 'end' => 1503.67, 'word' => ' was'],
            ['start' => 1503.67, 'end' => 1504.09, 'word' => ' over'],
            ['start' => 1504.18, 'end' => 1504.75, 'word' => ' 40'],
            ['start' => 1504.75, 'end' => 1505.27, 'word' => ' years'],
            ['start' => 1505.27, 'end' => 1506.0000000000002, 'word' => ' old.'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 1290.0, 'end_time' => 1505.0]]);

        $this->assertGreaterThanOrEqual(1505.99, $plan['segments'][0]['end_time']);
    }

    #[Test]
    #[DataProvider('dependentAnnouncements')]
    public function a_song_drops_only_a_matching_non_looping_linking_announcement(string $text, string $decoded, int $droppedWords, bool $bankFollowOn = true, float $end = 4900.0): void
    {
        $cue = ['start' => 100.0, 'end' => 110.0, 'text' => $text];
        $log = $this->log($cue);
        $timing = [['start' => 100.0, 'end' => 100.2, 'word' => 'And']];
        $this->bank($log, $cue, $timing);
        $words = [];
        foreach (explode(' ', $decoded) as $i => $word) {
            $words[] = ['start' => 100.0 + $i * 0.2, 'end' => 100.2 + $i * 0.2, 'word' => $word];
        }
        $window = app(DependentSongOpening::class)->window($log, [$cue], 100.2);
        if ($bankFollowOn && $window !== null) {
            $evidence = app(OutputEdgeWordTimings::class);
            $identity = $evidence->identity($log, $window);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => $words]);
        }

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => $end]], songEnds: true);

        $this->assertEqualsWithDelta(($droppedWords === 0 ? 100.2 : 100.0 + $droppedWords * 0.2), $plan['segments'][0]['start_time'], 0.001);
    }

    public static function dependentAnnouncements(): array
    {
        return [
            '1023 announcement followed by standalone instruction' => ["And we're going to sing. Let's stand and sing.", "And we're going to sing. Let's stand and sing.", 5],
            'a chain ends at the first standalone sentence' => ['AND sing. Then stand. Praise the Lord.', 'and sing. then stand. Praise the Lord.', 4],
            '1226 sung line' => ['And then he is exalted. The king is exalted on high.', 'And then he is exalted. The king is exalted on high.', 0],
            '1042 transcript loop' => ["And then we'll sing. And then we'll sing.", "And then we'll sing. And then we'll sing.", 0],
            'decoder does not match' => ['And then sing.', 'Thank you.', 0],
            'missing decode is a soft miss' => ['And then sing.', 'And then sing.', 0, false],
            'announcement words are whole words' => ['And a songbird sings.', 'And a songbird sings.', 0],
            '1096 title without announcement word' => ['And secondly I know my Redeemer lives.', 'And secondly I know my Redeemer lives.', 0],
            'cannot consume the whole span' => ['And then sing.', 'And then sing.', 0, true, 100.6],
            '1073 play-through fragment keeps under 30 s' => ['And then sing.', 'And then sing.', 0, true, 125.0],
            'unfinished sentence' => ['And then sing', 'And then sing', 0],
        ];
    }

    /**
     * Decoded word ends run early: 21 of the full pass's 22 endings ruled "word clipped" cut
     * within 0.1 s of the last word's decoded end ("Amen." in 975, 1160, 1298…), while every
     * ending left 0.2–0.5 s of tail was ruled right (operator, 2026-10-12).
     */
    #[Test]
    #[DataProvider('spokenEndTails')]
    public function a_spoken_end_leaves_tail_room_after_its_last_word(array $cue, array $words, float $expected): void
    {
        $log = $this->logWithCues([$cue]);
        $this->bankWindow($log, $cue['end'], $words);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 10.0, 'end_time' => $cue['end']]]);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['end_time'], 0.001);
        $this->assertEqualsWithDelta($expected, $plan['cue_edge_widening'][0]['time'], 0.001);
    }

    public static function spokenEndTails(): array
    {
        return [
            '975 Amen decoded just past its cue' => [['start' => 3193.9, 'end' => 3194.64, 'text' => 'Amen.'], [
                ['start' => 3193.94, 'end' => 3194.66, 'word' => ' Amen.'],
            ], 3194.96],
            '1298 Amen decoded just inside its cue' => [['start' => 3539.9, 'end' => 3541.98, 'text' => "In Jesus' name. Amen."], [
                ['start' => 3538.9, 'end' => 3540.2, 'word' => ' In'],
                ['start' => 3540.2, 'end' => 3540.8, 'word' => " Jesus'"],
                ['start' => 3540.8, 'end' => 3541.3, 'word' => ' name.'],
                ['start' => 3541.5, 'end' => 3541.96, 'word' => ' Amen.'],
            ], 3542.26],
            '1124 the next word halves the tail' => [['start' => 608.5, 'end' => 611.04, 'text' => 'you step down into darkness.'], [
                ['start' => 607.5, 'end' => 608.5, 'word' => ' you'],
                ['start' => 608.5, 'end' => 609.0, 'word' => ' step'],
                ['start' => 609.0, 'end' => 609.5, 'word' => ' down'],
                ['start' => 609.5, 'end' => 610.2, 'word' => ' into'],
                ['start' => 610.2, 'end' => 611.06, 'word' => ' darkness.'],
                ['start' => 611.24, 'end' => 611.6, 'word' => " Let's"],
            ], 611.15],
        ];
    }

    /**
     * The largest pause sat mid-sentence, inward of a closing "did?" or "shall we?": both cuts were
     * ruled "sentence cut off" (full pass, 2026-10-12). The end stops at the sentence end passed.
     */
    #[Test]
    #[DataProvider('midSentenceEnds')]
    public function an_end_moved_into_a_sentence_stops_at_the_sentence_end_it_passed(array $cue, array $words, float $expected): void
    {
        $log = $this->logWithCues([$cue]);
        $this->bankWindow($log, $cue['end'], $words);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 10.0, 'end_time' => $cue['end']]]);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['end_time'], 0.001);
    }

    public static function midSentenceEnds(): array
    {
        return [
            '1335 did? decoded past the detected end' => [['start' => 841.64, 'end' => 844.36, 'text' => 'in the way that Adoniram Judson did?'], [
                ['start' => 840.5, 'end' => 841.3, 'word' => ' whatever'],
                ['start' => 841.3, 'end' => 841.6, 'word' => ' consequences,'],
                ['start' => 841.64, 'end' => 841.8, 'word' => ' in'],
                ['start' => 841.8, 'end' => 841.9, 'word' => ' the'],
                ['start' => 841.9, 'end' => 842.1, 'word' => ' way'],
                ['start' => 842.1, 'end' => 842.26, 'word' => ' that'],
                ['start' => 842.78, 'end' => 843.4, 'word' => ' Adonai'],
                ['start' => 843.4, 'end' => 843.6, 'word' => ' and'],
                ['start' => 843.6, 'end' => 844.1, 'word' => ' Judson'],
                ['start' => 844.1, 'end' => 845.06, 'word' => ' did?'],
            ], 845.21],
            '948 shall we? before the next line' => [['start' => 848.72, 'end' => 853.85, 'text' => "together as God's people. So let's stand, shall we? And we"], [
                ['start' => 847.7, 'end' => 848.7, 'word' => ' about'],
                ['start' => 848.72, 'end' => 849.3, 'word' => ' together'],
                ['start' => 849.3, 'end' => 849.6, 'word' => ' as'],
                ['start' => 849.96, 'end' => 850.3, 'word' => " God's"],
                ['start' => 850.3, 'end' => 850.8, 'word' => ' people.'],
                ['start' => 850.85, 'end' => 851.0, 'word' => ' So'],
                ['start' => 851.0, 'end' => 851.3, 'word' => " let's"],
                ['start' => 851.3, 'end' => 851.7, 'word' => ' stand,'],
                ['start' => 851.75, 'end' => 852.0, 'word' => ' shall'],
                ['start' => 852.0, 'end' => 852.3, 'word' => ' we?'],
                ['start' => 852.6, 'end' => 854.1, 'word' => " We'll"],
                ['start' => 854.1, 'end' => 854.8, 'word' => ' sing'],
            ], 852.45],
        ];
    }

    /**
     * A decode smeared over the edge says nothing about where the words were said: 932's "We" ran
     * 20 s across the sermon's start and its later words collapsed to one instant, and 1237's
     * "busy" ran 6 s across it. The start moved inward to the pause after them and dropped the
     * sermon's first sentence (operator listening, 2026-10-11).
     */
    #[Test]
    #[DataProvider('smearedStarts')]
    public function a_start_smeared_over_stays_where_the_classifier_hears_speech(array $timeline, float $expected): void
    {
        $cues = [
            ['start' => 414.68, 'end' => 415.68, 'text' => 'Amen.'],
            ['start' => 415.68, 'end' => 433.82, 'text' => 'We live on the outskirts of a very large city.'],
        ];
        $log = $this->logWithCues($cues);
        $log->update(['audio_timeline_path' => 'service-transcripts/edge-test.classes.json']);
        Storage::disk('local')->put('service-transcripts/edge-test.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        $this->bankWindow($log, 415.68, [
            ['start' => 414.35, 'end' => 434.81, 'word' => ' We'],
            ['start' => 434.81, 'end' => 434.81, 'word' => ' live'],
            ['start' => 434.81, 'end' => 434.81, 'word' => ' on'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 415.68, 'end_time' => 2000.0]]);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
    }

    /**
     * 1275: a 30 s "Thank you." whisper heard over the next song widened the sermon end's window;
     * the largest pause after a sung "Amen." took the sermon 19 s into the song.
     */
    #[Test]
    #[DataProvider('endsIntoTheNextSong')]
    public function a_spoken_end_is_not_carried_into_music(array $timeline, float $expected): void
    {
        $cues = [
            ['start' => 4166.86, 'end' => 4169.44, 'text' => "Let's stand and sing and then Michael draws to a close."],
            ['start' => 4171.0, 'end' => 4200.98, 'text' => 'Thank you.'],
        ];
        $log = $this->logWithCues($cues);
        $log->update(['audio_timeline_path' => 'service-transcripts/edge-test.classes.json']);
        Storage::disk('local')->put('service-transcripts/edge-test.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        $this->bankWindow($log, 4180.0, [
            ['start' => 4176.0, 'end' => 4180.0, 'word' => ' Glory'],
            ['start' => 4180.2, 'end' => 4198.0, 'word' => ' to the Lamb'],
            ['start' => 4198.11, 'end' => 4199.0, 'word' => ' Amen.'],
        ]);

        $plans = app(CueSafeExtractionPlan::class);
        $plan = $plans->forSpans($log->fresh(), [['start_time' => 2146.34, 'end_time' => 4180.0]]);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['end_time'], 0.001);
        // The sentence check is asked about the cut the plan makes, or its answer is never found
        // (Codex review, 2026-10-13); the plan's tail comes after the check.
        $asked = $plans->wordPauseEdge($log->fresh(), app(OutputEdgeWordTimings::class)->cues($log->fresh()), 4180.0, 'end');
        $this->assertEqualsWithDelta($plan['cue_edge_widening'][0]['time'] - ($expected === 4180.0 ? 0.0 : 0.3), $asked['time'], 0.001);
    }

    public static function endsIntoTheNextSong(): array
    {
        return [
            'the song is music: the end stays' => [[[4160, 4170, 0.05, 0.8], [4170, 4210, 0.8, 0.02]], 4180.0],
            'speech goes on: the pause still places it' => [[[4160, 4210, 0.05, 0.8]], 4199.3],
        ];
    }

    /**
     * A detected musical opening is always in (D6): the pause after a decode stretched over a
     * song's start moved it later and dropped the introduction (corpus measurement, 2026-10-25).
     * Across a spoken introduction, the move stands.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $timeline
     */
    #[Test]
    #[DataProvider('songStartsAfterStretchedWords')]
    public function a_song_start_is_not_moved_later_across_its_musical_opening(array $cues, array $words, array $timeline, float $original, float $expected): void
    {
        $log = $this->logWithCues($cues);
        $log->update(['audio_timeline_path' => 'service-transcripts/edge-test.classes.json']);
        Storage::disk('local')->put('service-transcripts/edge-test.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        $this->bankWindow($log, $original, $words);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => $original, 'end_time' => 4900.0]], songEnds: true);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
    }

    public static function songStartsAfterStretchedWords(): array
    {
        return [
            '1267 §3352: a 10.7 s "Your" over the introduction' => [
                [['start' => 1991.2, 'end' => 1992.2, 'text' => 'Of the Christ who died for me'], ['start' => 1992.2, 'end' => 2005.2, 'text' => 'Your word is good']],
                [['start' => 1991.5, 'end' => 2002.19, 'word' => ' Your'], ['start' => 2002.2, 'end' => 2003.2, 'word' => ' word'], ['start' => 2003.2, 'end' => 2004.2, 'word' => ' is'], ['start' => 2004.2, 'end' => 2005.39, 'word' => ' good,']],
                [[1980, 1990, 0.5, 0.01], [1990, 2010, 0.8, 0.01]], 1992.2, 1992.2,
            ],
            '1267 §3353: a ruled-right start kept from moving 1 s' => [
                [['start' => 2233.3, 'end' => 2241.9, 'text' => 'For more.'], ['start' => 2241.9, 'end' => 2250.0, 'text' => 'Thank you.']],
                [['start' => 2232.45, 'end' => 2238.32, 'word' => ' Thank'], ['start' => 2240.51, 'end' => 2242.89, 'word' => ' you.']],
                [[2230, 2235, 0.58, 0.01], [2235, 2240, 0.22, 0.02], [2240, 2260, 0.85, 0.01]], 2241.9, 2241.9,
            ],
            '1275 §3451: a music marker over the introduction' => [
                [['start' => 4171.0, 'end' => 4200.98, 'text' => 'Thank you.']],
                [['start' => 4172.01, 'end' => 4184.0, 'word' => 'music*'], ['start' => 4184.1, 'end' => 4188.98, 'word' => ' Thank']],
                [[4170, 4175, 0.55, 0.07], [4175, 4180, 0.02, 0.01], [4180, 4195, 0.72, 0.0]], 4180.0, 4180.0,
            ],
            '1252 §3156: a spoken introduction is still left out' => [
                [['start' => 1055.48, 'end' => 1058.94, 'text' => "Let's stand and sing from the breaking of the dawn."], ['start' => 1058.94, 'end' => 1079.91, 'text' => 'From the breaking of the dawn to the setting of the sun, I']],
                [['start' => 1057.02, 'end' => 1057.56, 'word' => ' breaking'], ['start' => 1057.56, 'end' => 1057.79, 'word' => ' of'], ['start' => 1057.84, 'end' => 1058.58, 'word' => ' the'], ['start' => 1058.58, 'end' => 1060.4, 'word' => ' dawn'], ['start' => 1071.03, 'end' => 1080.9, 'word' => ' oh']],
                [[1045, 1060, 0.01, 0.78], [1060, 1075, 0.72, 0.01]], 1058.94, 1060.4,
            ],
            '1180 §165012: a spoken introduction over the band playing in is still left out' => [
                [['start' => 1808.5, 'end' => 1810.0, 'text' => "Let's stand and sing."], ['start' => 1810.0, 'end' => 1820.0, 'text' => 'O worship the King']],
                [['start' => 1808.6, 'end' => 1808.9, 'word' => " Let's"], ['start' => 1808.9, 'end' => 1809.3, 'word' => ' stand'], ['start' => 1809.3, 'end' => 1809.85, 'word' => ' and'], ['start' => 1809.85, 'end' => 1810.32, 'word' => ' sing.']],
                [[1805, 1810, 0.01, 0.86], [1810, 1815, 0.57, 0.63], [1815, 1830, 0.58, 0.02]], 1810.0, 1810.32,
            ],
            '1200 §2509: a start detected in the spoken title moves to the cut heard as right' => [
                [['start' => 308.7, 'end' => 310.46, 'text' => 'I will give thanks to you.'], ['start' => 310.46, 'end' => 320.0, 'text' => 'I will give thanks to you, my God']],
                [['start' => 307.84, 'end' => 307.97, 'word' => " Let's"], ['start' => 307.97, 'end' => 308.24, 'word' => ' stand'], ['start' => 308.24, 'end' => 308.4, 'word' => ' and'], ['start' => 308.4, 'end' => 308.79, 'word' => ' sing.'], ['start' => 308.79, 'end' => 308.84, 'word' => ' I'], ['start' => 308.84, 'end' => 309.03, 'word' => ' will'], ['start' => 309.03, 'end' => 309.26, 'word' => ' give'], ['start' => 309.26, 'end' => 309.75, 'word' => ' thanks'], ['start' => 309.75, 'end' => 309.94, 'word' => ' to'], ['start' => 309.94, 'end' => 310.48, 'word' => ' you.']],
                [[300, 310, 0.05, 0.7], [310, 315, 0.63, 0.47], [315, 330, 0.59, 0.02]], 309.58, 310.48,
            ],
        ];
    }

    /** @param list<array{start: float, end: float, word: string}> $words */
    #[Test]
    #[DataProvider('songSentenceStarts')]
    public function a_song_start_drops_the_rest_of_a_split_sentence(array $words, float $cut, float $expected, float $end = 4900.0): void
    {
        $log = $this->log(['start' => 100.0, 'end' => 110.0, 'text' => "Let's stand and sing. Praise the Lord."]);
        $log->update(['audio_timeline_path' => 'service-transcripts/edge-test.classes.json']);
        // Announcements can be classified as music; the sentence rule must still work.
        Storage::disk('local')->put('service-transcripts/edge-test.classes.json', AudioTimelineFixture::json([[90, 120, 0.8, 0.1]], 5000.0));
        $this->bankWindow($log, $cut, $words);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => $cut, 'end_time' => $end]], songEnds: true);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
        if ($expected > $cut) {
            $this->assertSame('song_start_sentence', $plan['cue_edge_widening'][0]['reason']);
        }
    }

    /**
     * Decoded word ends run early: 1023 §1361's "again." ended at 1488.42 but sounded to 1489.15, and the
     * song opened on its tail (operator, 10-27). A start the sentence rule moves waits for the first quiet.
     *
     * @param  list<array{0: float, 1: float, 2: float|null}>  $stretches
     */
    #[Test]
    #[DataProvider('droppedSentenceTails')]
    public function a_song_start_waits_for_the_dropped_sentences_last_word_to_stop_sounding(array $stretches, float $expected): void
    {
        $words = array_map(static fn (array $word): array => [...$word, 'start' => $word['start'] + 3000.0, 'end' => $word['end'] + 3000.0],
            self::songSentenceStarts()['between words of the announcement'][0]);
        $cue = ['start' => 3100.0, 'end' => 3110.0, 'text' => "Let's stand and sing. Praise the Lord."];
        $log = $this->log($cue);
        // As a_song_start_drops_the_rest_of_a_split_sentence: the trim stops at "sing.".
        $log->update(['audio_timeline_path' => 'service-transcripts/edge-test.classes.json']);
        Storage::disk('local')->put('service-transcripts/edge-test.classes.json', AudioTimelineFixture::json([[3090, 3120, 0.8, 0.1]], 5000.0));
        $this->withLevels($log, $stretches);
        // With levels, the wide sentence window differs from the cue's own; both decoded the same words.
        $this->bank($log, $cue, $words);
        $this->bankWindow($log, 3100.8, $words);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 3100.8, 'end_time' => 4200.0]], songEnds: true);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
        $this->assertSame('song_start_sentence', $plan['cue_edge_widening'][0]['reason']);
    }

    public static function droppedSentenceTails(): array
    {
        // "sing." is decoded 3101.1–3101.5 and "Praise" starts at 3103.0; outside the stretches the level is −30 dB.
        return [
            'the word sounds on past its decoded end, through a dip between syllables' => [[[3101.6, 3101.7, -60.0], [3102.2, 3103.0, -60.0]], 3102.2],
            'the word is quiet at its decoded end' => [[[3101.5, 3103.0, -60.0]], 3101.5],
            'sound runs on past a second: the band has started' => [[[3102.7, 3103.0, -60.0]], 3101.5],
        ];
    }

    #[Test]
    #[DataProvider('sentenceSoundClasses')]
    public function the_classifier_cannot_veto_a_song_opening_sentence_trim(float $music, float $speech, float $expected): void
    {
        $words = self::songSentenceStarts()['between words of the announcement'][0];
        $log = $this->log(['start' => 100.0, 'end' => 110.0, 'text' => "Let's stand and sing. Praise the Lord."]);
        $log->update(['audio_timeline_path' => 'service-transcripts/sentence.classes.json']);
        Storage::disk('local')->put($log->audio_timeline_path, AudioTimelineFixture::json([[90, 120, $music, $speech]], 5000.0));
        $this->bankWindow($log, 100.8, $words);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.8, 'end_time' => 4900.0]], songEnds: true);
        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
    }

    public static function sentenceSoundClasses(): array
    {
        return [
            // It cannot tell an announcement over the band from singing (4735); refusing would leave the split.
            'leader speaking' => [0.05, 0.85, 105.0],
            'leader over an intro' => [0.75, 0.75, 105.0],
            // The end of "Let's stand and sing.", as a_song_start_drops_the_rest_of_a_split_sentence asserts.
            'announcement classified as music' => [0.8, 0.05, 101.5],
            'unknown' => [0.05, 0.05, 105.0],
        ];
    }

    public static function songSentenceStarts(): array
    {
        $words = [
            ['start' => 100.0, 'end' => 100.3, 'word' => " Let's"],
            ['start' => 100.3, 'end' => 100.7, 'word' => ' stand'],
            ['start' => 100.9, 'end' => 101.1, 'word' => ' and'],
            ['start' => 101.1, 'end' => 101.5, 'word' => ' sing.'],
            ['start' => 103.0, 'end' => 103.6, 'word' => ' Praise'],
            ['start' => 103.6, 'end' => 104.0, 'word' => ' the'],
            ['start' => 104.0, 'end' => 105.0, 'word' => ' Lord.'],
        ];
        $unpunctuated = $words;
        $unpunctuated[3]['word'] = ' sing';
        $unfinished = array_slice($unpunctuated, 0, 3);
        $smeared = $words;
        $smeared[1]['end'] = 103.0;
        $zeroLength = $words;
        array_splice($zeroLength, 4, 0, [['start' => 102.0, 'end' => 102.0, 'word' => ' phantom']]);

        return [
            'inside a word in a music window' => [$words, 100.5, 101.5],
            'between words of the announcement' => [$words, 100.8, 101.5],
            'pause ends an unpunctuated sentence' => [$unpunctuated, 100.8, 101.5],
            'complete sentence already excluded' => [$words, 102.0, 102.0],
            'no evidenced sentence end keeps the existing cut' => [$unfinished, 100.8, 100.8],
            'a smeared word cannot authorise a trim' => [$smeared, 100.5, 100.5],
            'zero-length words do not extend the announcement' => [$zeroLength, 100.8, 101.5],
            'a sentence trim cannot consume the whole song' => [$words, 100.8, 100.8, 101.5],
        ];
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start: float, end: float, word: string}>  $words
     */
    #[Test]
    #[DataProvider('sentenceEndsAtTheDecodeEdge')]
    public function a_song_start_ignores_a_sentence_end_the_decode_heard_only_in_part(array $cues, array $words, float $original, float $expected): void
    {
        $log = $this->logWithCues($cues);
        $this->bankWindow($log, $original, $words);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => $original, 'end_time' => 4900.0]], songEnds: true);

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
    }

    /** Heard 10-26 (Sentence Starts): B cut mid-sentence where the decode ended inside a cue that ran on. */
    public static function sentenceEndsAtTheDecodeEdge(): array
    {
        return [
            '948 §711: "the song that we play." is 1 s of "played through before the service began."' => [
                [['start' => 1898.44, 'end' => 1901.62, 'text' => "We're going to be looking into that passage of Scripture in"], ['start' => 1901.62, 'end' => 1903.32, 'text' => 'just a few moments.'], ['start' => 1903.54, 'end' => 1906.53, 'text' => "But first we're going to stand and sing the song that we"], ['start' => 1906.53, 'end' => 1910.16, 'text' => 'played through before the service began.']],
                [['start' => 1902.61, 'end' => 1902.61, 'word' => ' A'], ['start' => 1902.69, 'end' => 1902.82, 'word' => ' few'], ['start' => 1902.82, 'end' => 1903.37, 'word' => ' moments,'], ['start' => 1903.48, 'end' => 1903.57, 'word' => ' but'], ['start' => 1903.64, 'end' => 1903.96, 'word' => ' first'], ['start' => 1904.01, 'end' => 1904.08, 'word' => " we're"], ['start' => 1904.12, 'end' => 1904.25, 'word' => ' going'], ['start' => 1904.26, 'end' => 1904.34, 'word' => ' to'], ['start' => 1904.34, 'end' => 1904.62, 'word' => ' stand'], ['start' => 1904.62, 'end' => 1904.79, 'word' => ' and'], ['start' => 1904.8, 'end' => 1905.04, 'word' => ' sing'], ['start' => 1905.04, 'end' => 1905.35, 'word' => ' the'], ['start' => 1905.35, 'end' => 1905.77, 'word' => ' song'], ['start' => 1905.77, 'end' => 1906.25, 'word' => ' that'], ['start' => 1906.25, 'end' => 1906.55, 'word' => ' we'], ['start' => 1906.61, 'end' => 1907.52, 'word' => ' play.']],
                1903.54, 1903.48,
            ],
            '1235 §2916: "Great Is Thy Faith." is 1 s of "Great is Thy Faithfulness."' => [
                [['start' => 662.16, 'end' => 667.92, 'text' => "And it's good for us to sing about the faithfulness of God."], ['start' => 668.04, 'end' => 669.98, 'text' => "And so that's what we're going to do now."], ['start' => 670.06, 'end' => 674.66, 'text' => "We're going to stand and sing a well-known hymn entitled,"], ['start' => 674.66, 'end' => 676.02, 'text' => 'Great is Thy Faithfulness.'], ['start' => 676.18, 'end' => 679.36, 'text' => "And after we've sung it through, we'll sing a little chorus"]],
                [['start' => 669.21, 'end' => 669.28, 'word' => ' Now'], ['start' => 669.38, 'end' => 669.64, 'word' => " we're"], ['start' => 669.64, 'end' => 670.01, 'word' => ' going'], ['start' => 670.01, 'end' => 670.15, 'word' => ' to'], ['start' => 670.15, 'end' => 670.52, 'word' => ' stand'], ['start' => 670.52, 'end' => 670.74, 'word' => ' and'], ['start' => 670.74, 'end' => 671.08, 'word' => ' sing'], ['start' => 671.08, 'end' => 671.16, 'word' => ' a'], ['start' => 671.22, 'end' => 672.68, 'word' => ' well-known'], ['start' => 672.9, 'end' => 673.38, 'word' => ' hymn'], ['start' => 673.38, 'end' => 674.66, 'word' => ' entitled'], ['start' => 674.66, 'end' => 674.98, 'word' => ' Great'], ['start' => 674.98, 'end' => 675.08, 'word' => ' Is'], ['start' => 675.11, 'end' => 675.23, 'word' => ' Thy'], ['start' => 675.3, 'end' => 675.64, 'word' => ' Faith.']],
                670.06, 669.38,
            ],
            '1312 §3962: "the mercy." is 1 s of "the mercy of God."' => [
                [['start' => 322.66, 'end' => 323.8, 'text' => 'Lord.'], ['start' => 325.9, 'end' => 330.04, 'text' => "Our theme for this morning's service is mercy."], ['start' => 330.8, 'end' => 335.73, 'text' => 'And I want us to begin by singing a hymn that picks up on'], ['start' => 335.73, 'end' => 338.16, 'text' => 'the mercy of God.'], ['start' => 338.16, 'end' => 341.76, 'text' => 'Your mercy, my God, is the theme of my song.']],
                [['start' => 325.7, 'end' => 325.7, 'word' => ' Our'], ['start' => 325.85, 'end' => 325.92, 'word' => ' theme'], ['start' => 325.92, 'end' => 326.3, 'word' => ' for'], ['start' => 326.3, 'end' => 326.81, 'word' => ' this'], ['start' => 327.21, 'end' => 327.95, 'word' => " morning's"], ['start' => 327.95, 'end' => 328.84, 'word' => ' service'], ['start' => 328.84, 'end' => 329.04, 'word' => ' is'], ['start' => 329.2, 'end' => 329.98, 'word' => ' mercy,'], ['start' => 330.25, 'end' => 330.4, 'word' => ' and'], ['start' => 330.43, 'end' => 330.52, 'word' => ' I'], ['start' => 330.52, 'end' => 330.9, 'word' => ' want'], ['start' => 330.9, 'end' => 331.22, 'word' => ' us'], ['start' => 331.22, 'end' => 331.48, 'word' => ' to'], ['start' => 331.71, 'end' => 332.08, 'word' => ' begin'], ['start' => 332.08, 'end' => 332.32, 'word' => ' by'], ['start' => 332.32, 'end' => 333.17, 'word' => ' singing'], ['start' => 333.17, 'end' => 333.29, 'word' => ' a'], ['start' => 333.29, 'end' => 333.82, 'word' => ' hymn'], ['start' => 333.82, 'end' => 334.59, 'word' => ' that'], ['start' => 334.59, 'end' => 335.1, 'word' => ' picks'], ['start' => 335.29, 'end' => 335.37, 'word' => ' up'], ['start' => 335.37, 'end' => 335.57, 'word' => ' on'], ['start' => 335.57, 'end' => 335.83, 'word' => ' the'], ['start' => 335.86, 'end' => 336.72, 'word' => ' mercy.']],
                330.793, 330.9,
            ],
            '1222 §2734: "worthy to be..." is 1 s of a longer line' => [
                [['start' => 342.26, 'end' => 345.74, 'text' => 'Let us remain standing as we sing,'], ['start' => 345.86, 'end' => 348.52, 'text' => 'Worthy, oh worthy are you Lord.'], ['start' => 348.62, 'end' => 352.42, 'text' => 'Worthy to be thanked and praised and worshipped and adored.']],
                [['start' => 345.01, 'end' => 345.15, 'word' => ' We'], ['start' => 345.15, 'end' => 345.83, 'word' => ' sing,'], ['start' => 345.83, 'end' => 346.2, 'word' => ' worthy,'], ['start' => 346.2, 'end' => 346.41, 'word' => ' oh'], ['start' => 346.48, 'end' => 347.3, 'word' => ' worthy'], ['start' => 347.3, 'end' => 347.64, 'word' => ' are'], ['start' => 347.78, 'end' => 348.02, 'word' => ' you'], ['start' => 348.02, 'end' => 348.66, 'word' => ' Lord,'], ['start' => 348.66, 'end' => 349.09, 'word' => ' worthy'], ['start' => 349.09, 'end' => 349.25, 'word' => ' to'], ['start' => 349.25, 'end' => 349.48, 'word' => ' be...']],
                345.86, 345.83,
            ],
            '986 §1125: a title the decode heard to the end of its cue still moves' => [
                [['start' => 247.5, 'end' => 250.8, 'text' => "Let's stand and sing together number 103B"], ['start' => 251.56, 'end' => 256.58, 'text' => 'in the praise hymn, but praise my soul, the King of heaven.']],
                [['start' => 250.69, 'end' => 251.56, 'word' => ' 103b'], ['start' => 251.56, 'end' => 251.68, 'word' => ' in'], ['start' => 251.68, 'end' => 251.83, 'word' => ' the'], ['start' => 251.96, 'end' => 252.23, 'word' => ' praise'], ['start' => 252.23, 'end' => 252.5, 'word' => ' hymn'], ['start' => 252.5, 'end' => 252.84, 'word' => ' book,'], ['start' => 252.93, 'end' => 253.47, 'word' => ' Praise'], ['start' => 253.47, 'end' => 253.68, 'word' => ' my'], ['start' => 253.68, 'end' => 254.4, 'word' => ' soul,'], ['start' => 254.53, 'end' => 254.62, 'word' => ' the'], ['start' => 254.94, 'end' => 255.32, 'word' => ' King'], ['start' => 255.32, 'end' => 255.52, 'word' => ' of'], ['start' => 255.54, 'end' => 256.58, 'word' => ' heaven.']],
                251.56, 256.58,
            ],
            '1223 §2751: a title ending with its cue still moves' => [
                [['start' => 811.92, 'end' => 815.78, 'text' => 'our next song, number 462 in the praise hymn books.'], ['start' => 815.84, 'end' => 820.48, 'text' => 'I know that my Redeemer lives.'], ['start' => 821.18, 'end' => 822.26, 'text' => "Let's stand and sing."]],
                [['start' => 814.98, 'end' => 815.31, 'word' => ' Praise'], ['start' => 815.31, 'end' => 815.55, 'word' => ' him'], ['start' => 815.55, 'end' => 816.1, 'word' => ' because'], ['start' => 816.1, 'end' => 816.18, 'word' => ' I'], ['start' => 816.18, 'end' => 816.51, 'word' => ' know'], ['start' => 816.51, 'end' => 817.37, 'word' => ' that'], ['start' => 817.42, 'end' => 817.9, 'word' => ' my'], ['start' => 817.9, 'end' => 819.1, 'word' => ' Redeemer'], ['start' => 819.1, 'end' => 820.48, 'word' => ' lives.']],
                815.84, 820.48,
            ],
        ];
    }

    public static function smearedStarts(): array
    {
        return [
            '932 speech at the start keeps it' => [[[400, 410, 0.7, 0.1], [410, 440, 0.2, 0.45]], 415.68],
            'a song outro at the start lets the move stand' => [[[400, 430, 0.8, 0.1], [430, 440, 0.1, 0.8]], 434.81],
        ];
    }

    #[Test]
    public function absent_cache_blocks_instead_of_using_whole_cues(): void
    {
        $log = $this->log(['start' => 99.0, 'end' => 101.0, 'text' => 'Amen']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('edge_word_timings_missing');
        app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 100.0, 'end_time' => 200.0]]);
    }

    #[Test]
    public function no_words_preserves_whole_cue_and_records_the_reason(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
        $this->assertSame(99.0, $plan['segments'][0]['start_time']);
        $this->assertSame('no_words_whole_cue', $plan['cue_edge_widening'][0]['reason']);
    }

    #[Test]
    public function cache_identity_binds_the_model_and_media_processing_version(): void
    {
        config(['media-processing.service_structure.transcription_service' => 'local']);
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        config(['media-processing.transcription.local_whisper_model' => 'different']);
        $this->expectExceptionMessage('edge_word_timings_missing');
        app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
    }

    #[Test]
    public function changed_processing_version_cannot_reuse_edge_evidence(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        config(['media-processing.media_processing_version' => 999]);
        $this->expectExceptionMessage('edge_word_timings_missing');
        app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
    }

    #[Test]
    public function sub_millisecond_cue_noise_does_not_widen_a_no_words_edge(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.000000000001, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 10.0, 'end_time' => 101.0]]);
        $this->assertSame(101.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function disagreeing_words_still_supply_the_pause_and_are_audited(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, [['start' => 99.0, 'end' => 99.5, 'word' => 'Thanks']]);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
        $this->assertSame(100.0, $plan['segments'][0]['start_time']);
        $this->assertTrue($plan['cue_edge_widening'][0]['text_disagreement']);
        $this->assertSame('Thanks', $plan['cue_edge_widening'][0]['word_before']['word']);
    }

    #[Test]
    public function candidate_preparation_banks_new_prayer_window_without_installing_text(): void
    {
        $log = $this->log(['start' => 403.1, 'end' => 408.0, 'text' => 'The service continues.']);
        $log->writeProcessingMetadata(fn (array $metadata): array => [...$metadata,
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/test.mp3']]]);
        $candidate = ChurchServiceTranscript::fromCues([
            ['start' => 351.62, 'end' => 353.26, 'text' => "Let's join together in prayer."],
        ], 500, 'local_whisper');
        $extractor = \Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->with('/audio.mp3', 350.62, 354.26, $log->processing_id)->andReturn('/window.mp3');
        $extractor->shouldReceive('delete')->once()->with('/window.mp3');
        $storage = \Mockery::mock(StorageAdapterHelper::class);
        $storage->shouldReceive('downloadToTemp')->once()->andReturn('/audio.mp3');
        $storage->shouldReceive('isS3CompatibleDisk')->once()->andReturn(false);
        $whisper = \Mockery::mock(LocalWhisperServiceTranscriptionService::class);
        $whisper->shouldReceive('transcribeEdgeWindow')->once()->andReturn([['start' => 1.0, 'end' => 1.4, 'word' => "Let's"]]);
        $service = new PrepareOutputEdgeWordTimings(app(OutputEdgeWordTimings::class), $whisper, $extractor, app(ServiceArtifactStorage::class), $storage);
        $song = [['start_time' => 300.0, 'end_time' => 350.0]];
        $planner = app(CueSafeExtractionPlan::class);
        self::assertSame([], $planner->songEndSpeechEdges($log->fresh(), $song));
        self::assertSame([351.62], $planner->songEndSpeechEdges($log->fresh(), $song, $candidate));
        try {
            $planner->forSpans($log->fresh(), $song, songEnds: true, transcript: $candidate);
            self::fail('A new prayer edge must require its exact word evidence.');
        } catch (OutputEdgeTimingsMissing $exception) {
            self::assertStringContainsString('350.620000', $exception->getMessage());
        }
        $spans = [['start_time' => 351.62, 'end_time' => 351.62]];
        self::assertSame(1, $service->prepare($log->fresh(), $spans, $candidate)['decoded']);
        self::assertSame(0, $service->prepare($log->fresh(), $spans, $candidate)['decoded']);
        self::assertSame("Let's", app(OutputEdgeWordTimings::class)->read($log->fresh(), ['start' => 350.62, 'end' => 354.26])['words'][0]['word']);
        $plan = $planner->forSpans($log->fresh(), $song, songEnds: true, transcript: $candidate);
        self::assertSame('song_end_speech', $plan['cue_edge_widening'][0]['reason']);
        self::assertLessThanOrEqual(351.62, $plan['segments'][0]['end_time']);
        self::assertSame('temp/edge-test.json', $log->fresh()->serviceTranscriptPath());
    }

    #[Test]
    public function preparation_maps_window_words_back_to_service_time_and_reuses_its_cache(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $log->writeProcessingMetadata(fn (array $metadata): array => [...$metadata,
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/test.mp3']]]);
        $extractor = \Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->with('/audio.mp3', 98.0, 102.0, $log->processing_id)->andReturn('/window.mp3');
        $extractor->shouldReceive('delete')->once()->with('/window.mp3');
        $storage = \Mockery::mock(StorageAdapterHelper::class);
        $storage->shouldReceive('downloadToTemp')->once()->andReturn('/audio.mp3');
        $storage->shouldReceive('isS3CompatibleDisk')->once()->andReturn(false);
        $whisper = \Mockery::mock(LocalWhisperServiceTranscriptionService::class);
        $whisper->shouldReceive('transcribeEdgeWindow')->once()->with('/window.mp3')->andReturn([['start' => 1.0, 'end' => 1.5, 'word' => 'Amen']]);
        $service = new PrepareOutputEdgeWordTimings(app(OutputEdgeWordTimings::class), $whisper, $extractor, app(ServiceArtifactStorage::class), $storage);
        $spans = [['start_time' => 100.0, 'end_time' => 200.0]];
        $this->assertSame(1, $service->prepare($log->fresh(), $spans)['decoded']);
        $this->assertSame(0, $service->prepare($log->fresh(), $spans)['decoded']);
        $payload = app(OutputEdgeWordTimings::class)->read($log->fresh(), ['start' => 98.0, 'end' => 102.0]);
        $this->assertEquals([['start' => 99.0, 'end' => 99.5, 'word' => 'Amen']], $payload['words']);
        $this->assertSame('temp/edge-test.json', $log->fresh()->serviceTranscriptPath());
    }

    #[Test]
    public function preparation_uses_the_configured_openai_provider_without_local_whisper(): void
    {
        config(['media-processing.service_structure.transcription_service' => 'openai',
            'media-processing.service_structure.transcription_model' => 'whisper-1',
            'media-processing.transcription.openai_api_key' => 'test-key']);
        Http::preventStrayRequests();
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $log->writeProcessingMetadata(fn (array $metadata): array => [...$metadata,
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/test.mp3']]]);
        $audio = tempnam(sys_get_temp_dir(), 'edge-provider-');
        file_put_contents($audio, 'edge audio');
        $extractor = \Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn($audio);
        $extractor->shouldReceive('delete')->once()->with($audio);
        $this->app->instance(ServiceAudioWindowExtractor::class, $extractor);
        $storage = \Mockery::mock(StorageAdapterHelper::class);
        $storage->shouldReceive('downloadToTemp')->once()->andReturn('/audio.mp3');
        $storage->shouldReceive('isS3CompatibleDisk')->once()->andReturn(false);
        $this->app->instance(StorageAdapterHelper::class, $storage);
        OpenAI::fake([
            TranscriptionResponse::fake(['words' => [['start' => 1.0, 'end' => 1.5, 'word' => 'Amen']]]),
        ]);
        try {
            app(PrepareOutputEdgeWordTimings::class)->prepare($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
            $payload = app(OutputEdgeWordTimings::class)->read($log->fresh(), ['start' => 98.0, 'end' => 102.0]);
            $this->assertSame([['start' => 99.0, 'end' => 99.5, 'word' => 'Amen']], $payload['words']);
            $this->assertSame('openai', $payload['identity']['provider']);
            $this->assertSame('whisper-1', $payload['identity']['model']);
            $this->assertSame('temp/edge-test.json', $log->fresh()->serviceTranscriptPath());
            $this->assertNotContains('raw', array_column(ServiceArtifactStorage::recordedFor($log->fresh()), 'kind'));
            Http::assertNothingSent();
        } finally {
            unlink($audio);
        }
    }

    #[Test]
    public function changing_provider_with_the_same_model_name_cannot_reuse_cached_words(): void
    {
        config(['media-processing.service_structure.transcription_service' => 'local',
            'media-processing.transcription.local_whisper_model' => 'same-model',
            'media-processing.service_structure.transcription_model' => 'same-model']);
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        config(['media-processing.service_structure.transcription_service' => 'openai']);
        $this->expectExceptionMessage('edge_word_timings_missing');
        app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
    }

    #[Test]
    public function legacy_local_edge_receipts_keep_their_original_cache_identity(): void
    {
        config(['media-processing.service_structure.transcription_service' => 'local']);
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $identity = ['processing_id' => $log->processing_id, 'start' => 98.0, 'end' => 102.0,
            'model' => (string) config('media-processing.transcription.local_whisper_model', 'small'), 'media_processing' => MediaProcessingVersion::signature()];
        $evidence = app(OutputEdgeWordTimings::class);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => []]);
        $this->assertSame($identity, $evidence->identity($log, ['start' => 98.0, 'end' => 102.0]));
        $this->assertSame([], $evidence->read($log->fresh(), ['start' => 98.0, 'end' => 102.0])['words']);
    }

    /**
     * 1023's sermon opened on 16 s of a muted feed while the children went out: the first cue and
     * its decoded "Well," were stretched back over the silence, and the cut began there (operator
     * listening, 2026-10-15: "there's no speech in the first two clips"). Single-frame noises in the
     * dead feed are not sound.
     */
    #[Test]
    public function a_start_in_a_dead_feed_moves_to_where_the_speech_is_heard(): void
    {
        $log = $this->deadFeedStart();
        $this->withLevels($log, [[2121.0, 2149.4, null], [2143.5, 2143.6, -40.0], [2149.4, 2200.0, -30.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2133.5, 'end_time' => 3735.0]]);

        $this->assertEqualsWithDelta(2149.1, $plan['segments'][0]['start_time'], 0.001);
        $this->assertSame(2149.1, round($plan['cue_edge_widening'][0]['dead_feed']['time'], 3));
    }

    /** A short word inside the dead stretch is sound: the start stops before it, so no word is lost. */
    #[Test]
    public function a_start_in_a_dead_feed_keeps_a_short_word_heard_inside_it(): void
    {
        $log = $this->deadFeedStart();
        $this->withLevels($log, [[2121.0, 2149.4, null], [2140.0, 2140.3, -30.0], [2149.4, 2200.0, -30.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2133.5, 'end_time' => 3735.0]]);

        $this->assertEqualsWithDelta(2139.7, $plan['segments'][0]['start_time'], 0.001);
    }

    /**
     * A quiet room is not a dead feed: a speaker's pause sits tens of decibels above the dropout
     * floor ({@see DeadFeedInsideSection}), so where the stretch is quiet but live, the edge stays.
     */
    #[Test]
    public function a_start_in_a_quiet_but_live_room_stays_where_the_words_put_it(): void
    {
        $log = $this->deadFeedStart();
        $placed = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2133.5, 'end_time' => 3735.0]]);
        $this->withLevels($log, [[2121.0, 2149.4, -62.0], [2149.4, 2200.0, -30.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2133.5, 'end_time' => 3735.0]]);

        $this->assertEqualsWithDelta($placed['segments'][0]['start_time'], $plan['segments'][0]['start_time'], 0.001);
        $this->assertArrayNotHasKey('dead_feed', $plan['cue_edge_widening'][0]);
    }

    /**
     * 1275's sermon ended 39 s into a muted feed, after an "Amen." the decode smeared across it.
     * The end moves back to where the feed goes dead, keeping the word's decay in the room tone
     * before it; a single dropped frame in that decay is not where the feed goes dead.
     */
    #[Test]
    public function an_end_in_a_dead_feed_moves_back_to_where_the_feed_goes_dead(): void
    {
        $log = $this->deadFeedEnd();
        $this->withLevels($log, [[4100.0, 4161.4, -30.0], [4161.4, 4161.8, -62.0], [4161.5, 4161.6, null], [4161.8, 4200.0, null]]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2146.34, 'end_time' => 4198.0]]);

        $this->assertEqualsWithDelta(4161.8, $plan['segments'][0]['end_time'], 0.001);
    }

    /**
     * 1025's last word sounds after 1.5 s of dead feed and only touches the threshold: 0.5 s of live
     * audio peaking at −46 dB against −51 dB, ruled "word clipped" (operator, 2026-10-12). Its level
     * matches the noises in 1023's dead stretch, so anything live that reaches the threshold is kept.
     */
    #[Test]
    public function an_end_in_a_dead_feed_keeps_a_quiet_last_word_heard_after_a_gap(): void
    {
        $log = $this->deadFeedEnd();
        $this->withLevels($log, [[4100.0, 4161.4, -30.0], [4161.4, 4163.0, null], [4163.0, 4163.5, -62.0], [4163.2, 4163.3, -44.0], [4163.5, 4200.0, null]]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2146.34, 'end_time' => 4198.0]]);

        $this->assertEqualsWithDelta(4163.5, $plan['segments'][0]['end_time'], 0.001);
    }

    /** Song starts now remove digital zero; finite room sound and the song's end stay intact. */
    #[Test]
    public function a_song_output_is_left_to_its_own_dead_feed_rule(): void
    {
        $log = $this->deadFeedStart();
        $this->withLevels($log, [[2121.0, 2149.4, -62.0], [2149.4, 2200.0, -30.0]]);
        $live = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2133.5, 'end_time' => 3735.0]], songEnds: true);
        $this->withLevels($log, [[2121.0, 2149.4, null], [2149.4, 2200.0, -30.0]]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 2133.5, 'end_time' => 3735.0]], songEnds: true);

        $this->assertLessThan($plan['segments'][0]['start_time'], $live['segments'][0]['start_time']);
        $this->assertEqualsWithDelta(2149.1, $plan['segments'][0]['start_time'], 0.001);
        $this->assertSame($live['segments'][0]['end_time'], $plan['segments'][0]['end_time']);
        $this->assertSame($live['audio_fade_out'], $plan['audio_fade_out']);
    }

    private function deadFeedStart(): MediaProcessingLog
    {
        $log = $this->logWithCues([
            ['start' => 2120.5, 'end' => 2123.4, 'text' => 'in the back hall.'],
            ['start' => 2133.5, 'end' => 2153.4, 'text' => "Well, I'm not following a particular passage in the Bible."],
        ]);
        $this->bankWindow($log, 2133.5, [
            ['start' => 2132.64, 'end' => 2135.46, 'word' => ' Well,'],
            ['start' => 2149.6, 'end' => 2150.0, 'word' => " I'm"],
            ['start' => 2150.0, 'end' => 2150.4, 'word' => ' not'],
        ]);

        return $log;
    }

    private function deadFeedEnd(): MediaProcessingLog
    {
        $log = $this->logWithCues([
            ['start' => 4150.0, 'end' => 4160.6, 'text' => 'and we give thanks to God.'],
            ['start' => 4160.8, 'end' => 4198.0, 'text' => 'Amen.'],
        ]);
        $this->bankWindow($log, 4198.0, [
            ['start' => 4159.0, 'end' => 4160.6, 'word' => ' to God.'],
            ['start' => 4160.8, 'end' => 4198.0, 'word' => ' Amen.'],
        ]);

        return $log;
    }

    /**
     * The run's level log: live audio at −30 dB sampled every 0.1 s from 2000 s to 4300 s, with each
     * stretch at its level (null for `-inf`), later stretches over earlier ones. The threshold is
     * pinned at −45 dB, as a run's adaptive one is computed from its whole file.
     *
     * @param  list<array{0: float, 1: float, 2: float|null}>  $stretches
     */
    private function withLevels(MediaProcessingLog $log, array $stretches): void
    {
        config(['media-processing.segmentation.adaptive_thresholds.enabled' => false, 'media-processing.segmentation.rms_threshold' => -45.0]);
        $lines = [];

        for ($tenth = 20000; $tenth < 43000; $tenth++) {
            $time = $tenth / 10;
            $level = '-30.0';

            foreach ($stretches as [$from, $to, $stretchLevel]) {
                if ($time >= $from - 0.0001 && $time < $to - 0.0001) {
                    $level = $stretchLevel === null ? '-inf' : sprintf('%.1f', $stretchLevel);
                }
            }

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.$level;
        }

        $log->forceFill(['rms_log_path' => 'service-transcripts/edge-test.rms.log'])->save();
        Storage::disk('local')->put('service-transcripts/edge-test.rms.log', implode("\n", $lines)."\n");

        // These tests supply synthetic words. Bank them against the finalized RMS window.
        $evidence = app(OutputEdgeWordTimings::class);
        $cues = $evidence->cues($log);
        $receipts = ServiceArtifactStorage::recordedFor($log->fresh());
        foreach ($receipts as $receipt) {
            if (! str_starts_with($receipt['kind'], 'edge-words-')) {
                continue;
            }
            $payload = json_decode(Storage::disk($receipt['disk'])->get($receipt['path']), true, flags: JSON_THROW_ON_ERROR);
            foreach ($cues as $cue) {
                foreach ([$cue['start'], $cue['end']] as $edge) {
                    $legacy = $evidence->window($cues, $edge, $log->duration);
                    if ($legacy !== null && abs($legacy['start'] - $payload['identity']['start']) < 0.000001
                        && abs($legacy['end'] - $payload['identity']['end']) < 0.000001) {
                        $this->bankWindow($log, $edge, $payload['words']);
                    }
                }
            }
        }
    }

    private function log(array $cue): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $log->putServiceTranscriptPath('temp/edge-test.json');
        Storage::disk('local')->put('temp/edge-test.json', json_encode(ChurchServiceTranscript::fromCues([$cue], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        return $log;
    }

    /** @param list<array{start: float, end: float, text: string}> $cues */
    private function logWithCues(array $cues): MediaProcessingLog
    {
        $log = $this->log($cues[0]);
        Storage::disk('local')->put('temp/edge-test.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        return $log;
    }

    /** @param list<array{start: float, end: float, word: string}> $words */
    private function bankWindow(MediaProcessingLog $log, float $edge, array $words): void
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $identity = $evidence->identity($log, $evidence->window($evidence->cues($log), $edge, 5000.0, $log));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => $words, 'compute_seconds' => 1.0]);
    }

    private function bank(MediaProcessingLog $log, array $cue, array $words): void
    {
        $identity = app(OutputEdgeWordTimings::class)->identity($log, ['start' => max(0.0, $cue['start'] - 1), 'end' => $cue['end'] + 1]);
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'edge-words-'.$key, ['identity' => $identity, 'words' => $words, 'compute_seconds' => 1.0]);
    }
}
