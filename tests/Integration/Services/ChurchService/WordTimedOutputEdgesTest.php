<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
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
            ], 100.5],
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
            ], 2447.3],
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
            ], 20.4],
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

        $this->assertEqualsWithDelta([['start_time' => 10.0, 'end_time' => 4900.0]], $plan['segments'], 0.001);
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
        $identity = $evidence->identity($log, $evidence->window($evidence->cues($log), $edge, 5000.0));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => $words, 'compute_seconds' => 1.0]);
    }

    private function bank(MediaProcessingLog $log, array $cue, array $words): void
    {
        $identity = app(OutputEdgeWordTimings::class)->identity($log, ['start' => max(0.0, $cue['start'] - 1), 'end' => $cue['end'] + 1]);
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'edge-words-'.$key, ['identity' => $identity, 'words' => $words, 'compute_seconds' => 1.0]);
    }
}
