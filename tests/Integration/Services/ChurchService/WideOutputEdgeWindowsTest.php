<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\DependentSongOpening;
use App\Services\ChurchService\OutputEdgeDecodeWindow;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\ChurchService\SpokenEdgeSentenceCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WideOutputEdgeWindowsTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    #[DataProvider('followOnWindows')]
    public function a_follow_on_window_reaches_the_first_standalone_sentence_within_its_cap(array $cues, float $duration, ?float $expectedEnd): void
    {
        $log = MediaProcessingLog::factory()->make(['duration' => $duration, 'rms_log_path' => null]);

        $window = app(DependentSongOpening::class)->window($log, $cues, 100.0);

        if ($expectedEnd === null) {
            $this->assertNull($window);

            return;
        }
        $this->assertSame(100.0, $window['start']);
        $this->assertSame($expectedEnd, $window['end']);
    }

    public static function followOnWindows(): array
    {
        $cues = [
            ['start' => 100.0, 'end' => 102.0, 'text' => "And we're going"],
            ['start' => 102.0, 'end' => 104.0, 'text' => 'to sing.'],
            ['start' => 104.0, 'end' => 108.0, 'text' => "Let's stand."],
            ['start' => 108.0, 'end' => 140.0, 'text' => 'Praise the Lord.'],
        ];

        return [
            'multi-cue announcement and first standalone sentence' => [$cues, 500.0, 109.0],
            'recording ends first' => [$cues, 108.5, 108.5],
            'the standalone sentence exceeds the cap' => [[
                ['start' => 100.0, 'end' => 104.0, 'text' => 'And sing.'],
                ['start' => 104.0, 'end' => 130.0, 'text' => 'Praise the Lord.'],
            ], 500.0, 115.0],
            'standalone instruction needs no follow-on decode' => [[
                ['start' => 100.0, 'end' => 104.0, 'text' => "So let's stand and sing."],
            ], 500.0, null],
        ];
    }

    #[Test]
    #[DataProvider('measuredWindows')]
    public function pause_candidates_match_the_frozen_step_one_measurements(array $measurement): void
    {
        $this->assertSame($measurement['expected'], app(OutputEdgeDecodeWindow::class)->bounds(
            $measurement['samples'], $measurement['threshold'], $measurement['edge'], $measurement['duration'],
        ));
    }

    public static function measuredWindows(): array
    {
        $rows = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Rulings/wide-decode-windows-20261027.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $row) {
            $cases[$row['id']] = [$row];
        }

        return $cases;
    }

    #[Test]
    public function captured_levels_reproduce_the_exact_window_used_by_the_banked_decode(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Rulings/wide-window-capture-precision.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $window = app(OutputEdgeDecodeWindow::class)->bounds($fixture['samples'], $fixture['threshold'], $fixture['edge'], $fixture['duration']);
        $this->assertSame($fixture['expected_start'], $window['start']);
        $this->assertSame($fixture['expected_end'], $window['end']);
    }

    #[Test]
    #[DataProvider('sentenceEvidence')]
    public function capped_punctuation_cannot_authorise_a_trim(string $boundary, float $expected): void
    {
        $words = [];
        for ($i = 0; $i < 25; $i++) {
            $words[] = ['start' => 100.0 + $i * 0.6, 'end' => 100.4 + $i * 0.6, 'word' => $i === 24 ? 'mercy.' : 'word'];
        }
        $method = new \ReflectionMethod(CueSafeExtractionPlan::class, 'afterSongOpeningSentence');
        $decision = $method->invoke(app(CueSafeExtractionPlan::class), $words,
            ['start' => 99.0, 'end' => 115.0, 'right_boundary' => $boundary, 'cues' => []], [],
            ['time' => 100.8, 'chosen_pause' => ['start' => 100.8, 'end' => 100.8], 'word_before' => null, 'word_after' => null]);
        $this->assertEqualsWithDelta($expected, $decision['time'], 0.001);
    }

    public static function sentenceEvidence(): array
    {
        return [
            'a stop at a pause' => ['pause', 114.8],
            'a stop in the last second at the cap' => ['cap', 100.8],
        ];
    }

    #[Test]
    public function a_measured_window_reaches_the_nearest_eight_tenths_pause_each_side(): void
    {
        $samples = [];
        for ($i = 850; $i <= 1150; $i++) {
            $samples[] = ['time' => $i / 10, 'rms' => ($i >= 900 && $i <= 910) || ($i >= 1100 && $i <= 1110) ? -70.0 : -20.0];
        }
        $window = app(OutputEdgeDecodeWindow::class)->bounds($samples, -45.0, 100.0, 500.0);
        $this->assertSame(['start' => 90.5, 'end' => 110.5, 'left_boundary' => 'pause', 'right_boundary' => 'pause'], $window);
    }

    #[Test]
    public function short_breaths_and_missing_samples_do_not_end_the_decode(): void
    {
        $samples = [['time' => 90.0, 'rms' => -70.0], ['time' => 91.0, 'rms' => -70.0]];
        for ($i = 1000; $i <= 1006; $i++) {
            $samples[] = ['time' => $i / 10, 'rms' => -70.0];
        }
        $this->assertSame(['start' => 85.0, 'end' => 115.0, 'left_boundary' => 'cap', 'right_boundary' => 'cap'],
            app(OutputEdgeDecodeWindow::class)->bounds($samples, -45.0, 100.0, 500.0));
    }

    #[Test]
    public function recording_limits_are_distinct_from_a_cap_in_speech(): void
    {
        $this->assertSame(['start' => 0.0, 'end' => 17.0, 'left_boundary' => 'recording', 'right_boundary' => 'cap'],
            app(OutputEdgeDecodeWindow::class)->bounds([], -45.0, 2.0, 500.0));
    }

    #[Test]
    public function a_hallucinated_cue_past_the_recording_uses_available_audio_instead_of_a_negative_window(): void
    {
        $this->assertSame(['start' => 4770.833, 'end' => 4785.833, 'left_boundary' => 'cap', 'right_boundary' => 'recording'],
            app(OutputEdgeDecodeWindow::class)->bounds([], -45.0, 4811.98, 4785.833));
    }

    #[Test]
    public function samples_outside_the_recording_cannot_create_a_pause_inside_it(): void
    {
        $samples = [];
        for ($i = 1194; $i <= 1202; $i++) {
            $samples[] = ['time' => $i / 10, 'rms' => -70.0];
        }
        $this->assertSame(['start' => 105.0, 'end' => 120.0, 'left_boundary' => 'cap', 'right_boundary' => 'recording'],
            app(OutputEdgeDecodeWindow::class)->bounds($samples, -45.0, 120.0, 120.0));
    }

    #[Test]
    public function production_windows_keep_the_touching_cues_but_use_measured_bounds(): void
    {
        $log = $this->runWithLevels();
        $cues = [['start' => 99.0, 'end' => 101.0, 'text' => 'The mercy'], ['start' => 101.0, 'end' => 106.0, 'text' => 'of God.']];
        $window = app(OutputEdgeWordTimings::class)->window($cues, 100.0, 500.0, $log);
        $this->assertSame(85.0, $window['start']);
        $this->assertSame(115.0, $window['end']);
        $this->assertSame([$cues[0]], $window['cues']);
        $this->assertSame(OutputEdgeDecodeWindow::RULE, $window['window_rule']);
    }

    #[Test]
    public function window_and_cut_planning_share_time_ordered_rms_samples(): void
    {
        $log = $this->runWithLevels();
        Storage::disk('local')->put($log->rms_log_path, "frame:0 pts:0 pts_time:3\nlavfi.astats.Overall.RMS_level=-20\nframe:1 pts:0 pts_time:1\nlavfi.astats.Overall.RMS_level=-30\nframe:2 pts:0 pts_time:2\nlavfi.astats.Overall.RMS_level=-25\n");
        $levels = app(OutputEdgeDecodeWindow::class)->levels($log);
        $this->assertSame([1.0, 2.0, 3.0], array_column($levels['samples'], 'time'));
    }

    #[Test]
    public function the_timing_word_union_excludes_wide_receipts_even_when_their_version_matches(): void
    {
        $log = $this->runWithLevels();
        $evidence = app(OutputEdgeWordTimings::class);
        $narrow = ['start' => 98.0, 'end' => 102.0];
        $identity = $evidence->identity($log, $narrow);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity,
            'words' => [['start' => 100.0, 'end' => 101.0, 'word' => 'timing.']]]);
        $this->assertSame('timing.', $evidence->heardWords($log->fresh())[0]['word']);
        $wide = $evidence->window([['start' => 99.0, 'end' => 101.0, 'text' => 'The mercy']], 100.0, 500.0, $log);
        $oldWide = [...$wide, 'window_rule' => 'pause-0.8-cap-15-v1'];
        $oldIdentity = $evidence->identity($log, $oldWide);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($oldIdentity), ['identity' => $oldIdentity,
            'words' => [['start' => 100.0, 'end' => 101.0, 'word' => 'old geometry.']]]);
        $this->assertSame('timing.', $evidence->heardWords($log->fresh())[0]['word']);
        $identity = $evidence->identity($log, $wide);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity,
            'words' => [['start' => 100.0, 'end' => 101.0, 'word' => 'fresh.']]]);
        $this->assertSame(['timing.'], array_column($evidence->heardWords($log->fresh()), 'word'));
        $this->assertCount(3, $evidence->bankedWindows($log->fresh(), includeWide: true));
        $this->assertArrayHasKey('window_rule', $identity);
    }

    #[Test]
    public function a_breath_cannot_make_the_evidence_window_narrower_than_its_cue(): void
    {
        $log = $this->runWithLevels();
        $samples = [];
        for ($i = 0; $i <= 300; $i++) {
            $samples[] = ['time' => $i / 10, 'rms' => $i >= 20 && $i <= 30 ? -70.0 : -20.0];
        }
        $cues = [['start' => 0.0, 'end' => 10.9, 'text' => 'An opening sentence with a breath.']];
        $window = app(OutputEdgeWordTimings::class)->window($cues, 0.0, 500.0, $log, ['samples' => $samples, 'threshold' => -45.0]);
        $this->assertSame(0.0, $window['start']);
        $this->assertGreaterThanOrEqual(11.9, $window['end']);
    }

    #[Test]
    public function wider_word_timestamp_changes_do_not_retime_an_ordinary_cut(): void
    {
        $log = $this->runWithLevels();
        $cues = [['start' => 99.0, 'end' => 101.0, 'text' => 'Mercy and grace']];
        $log->update(['processing_metadata' => ['service_transcript_path' => 'service-transcripts/timing.json']]);
        Storage::disk('local')->put('service-transcripts/timing.json', json_encode(['cues' => $cues, 'text' => 'Mercy and grace'], JSON_THROW_ON_ERROR));
        $evidence = app(OutputEdgeWordTimings::class);
        foreach ([
            [['start' => 98.0, 'end' => 102.0, 'cues' => $cues], 99.8, 100.2],
            [$evidence->window($cues, 100.0, 500.0, $log), 101.0, 101.5],
        ] as [$window, $before, $after]) {
            $identity = $evidence->identity($log, $window);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity,
                'words' => [['start' => isset($window['window_rule']) ? 100.0 : 99.0, 'end' => $before, 'word' => ' Mercy'], ['start' => $after, 'end' => $after + 0.3, 'word' => ' and']]]);
        }
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 400.0]]);
        $this->assertSame(100.5, $plan['segments'][0]['start_time']);
    }

    #[Test]
    public function wide_text_changes_sentence_evidence_without_changing_matching_word_times(): void
    {
        $timing = [];
        foreach (['the', 'mercy.', 'of', 'God.', 'we', 'sing'] as $index => $text) {
            $timing[] = ['start' => 100.0 + $index * 0.6, 'end' => 100.4 + $index * 0.6, 'word' => $text];
        }
        $wide = array_map(static fn (array $word): array => [...$word, 'start' => $word['start'] + 4, 'end' => $word['end'] + 4], $timing);
        $wide[1]['word'] = 'mercy';
        $planner = app(CueSafeExtractionPlan::class);
        $method = new \ReflectionMethod(CueSafeExtractionPlan::class, 'sentenceEvidenceAtTiming');
        $window = ['start' => 85.0, 'end' => 115.0, 'right_boundary' => 'pause', 'cues' => []];
        $mapped = $method->invoke($planner, $timing, $wide, $window, []);
        $this->assertSame('mercy', $mapped[1]['word']);
        $this->assertSame('God.', $mapped[3]['word']);
        $this->assertSame($timing[3]['end'], $mapped[3]['end']);
        $decision = (new \ReflectionMethod(CueSafeExtractionPlan::class, 'afterSongOpeningSentence'))->invoke($planner, $mapped, $window, [],
            ['time' => 100.5, 'chosen_pause' => ['start' => 100.4, 'end' => 100.6], 'word_before' => $timing[0], 'word_after' => $timing[1]], true);
        $this->assertSame($timing[3]['end'], $decision['time']);
    }

    #[Test]
    public function only_a_stop_beyond_the_timing_decode_needs_a_new_word_time(): void
    {
        $timing = [
            ['start' => 100.0, 'end' => 100.4, 'word' => 'the'],
            ['start' => 100.6, 'end' => 101.0, 'word' => 'mercy.'],
            ['start' => 101.2, 'end' => 101.6, 'word' => 'of'],
        ];
        $wide = [
            ['start' => 103.0, 'end' => 103.4, 'word' => 'the'],
            ['start' => 103.6, 'end' => 104.0, 'word' => 'mercy'],
            ['start' => 104.2, 'end' => 104.6, 'word' => 'of'],
            ['start' => 104.7, 'end' => 105.1, 'word' => 'God.'],
        ];
        $mapped = (new \ReflectionMethod(CueSafeExtractionPlan::class, 'sentenceEvidenceAtTiming'))->invoke(app(CueSafeExtractionPlan::class),
            $timing, $wide, ['start' => 85.0, 'end' => 115.0, 'right_boundary' => 'pause', 'cues' => []], []);
        $this->assertSame('mercy', $mapped[1]['word']);
        $this->assertSame(101.6, $mapped[2]['end']);
        $this->assertSame($wide[3], $mapped[3]);
    }

    #[Test]
    public function repeated_or_changed_phrases_cannot_supply_a_new_sentence_stop(): void
    {
        $words = [
            ['start' => 100.0, 'end' => 100.4, 'word' => 'praise'],
            ['start' => 100.6, 'end' => 101.0, 'word' => 'the'],
            ['start' => 101.2, 'end' => 101.6, 'word' => 'Lord.'],
        ];
        $method = new \ReflectionMethod(CueSafeExtractionPlan::class, 'sentenceEvidenceAtTiming');
        foreach ([array_merge($words, $words), [['start' => 100.0, 'end' => 101.0, 'word' => 'different.']]] as $wide) {
            $mapped = $method->invoke(app(CueSafeExtractionPlan::class), $words, $wide, ['start' => 85.0, 'end' => 115.0, 'cues' => []], []);
            $this->assertCount(3, $mapped);
            $this->assertSame('Lord', $mapped[2]['word']);
        }
    }

    #[Test]
    public function preparation_banks_separate_timing_and_sentence_receipts(): void
    {
        $log = $this->runWithLevels();
        $log->putServiceTranscriptPath('service-transcripts/preparation.json');
        Storage::disk('local')->put('service-transcripts/preparation.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 99.0, 'end' => 101.0, 'text' => 'The mercy of God.'],
        ], 500, 'mock')->toArray(), JSON_THROW_ON_ERROR));
        $windows = app(PrepareOutputEdgeWordTimings::class)->windows($log->fresh(), [['start_time' => 100.0, 'end_time' => 400.0]]);
        $this->assertCount(2, $windows);
        $this->assertSame([[98.0, 102.0], [85.0, 115.0]], array_map(static fn (array $window): array => [$window['start'], $window['end']], array_values($windows)));
    }

    #[Test]
    public function an_original_sentence_answer_is_reused_only_for_its_exact_original_question(): void
    {
        $log = $this->runWithLevels();
        $cues = [['start' => 99.0, 'end' => 101.0, 'text' => 'Mercy and grace.']];
        $log->putServiceTranscriptPath('service-transcripts/cached-sentence.json');
        Storage::disk('local')->put('service-transcripts/cached-sentence.json', json_encode(ChurchServiceTranscript::fromCues($cues, 500, 'mock')->toArray(), JSON_THROW_ON_ERROR));
        foreach ([[80.0, 100.0], [100.0, 200.0]] as [$start, $end]) {
            ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Prayer,
                'start_time' => $start, 'end_time' => $end]);
        }
        $evidence = app(OutputEdgeWordTimings::class);
        $words = [['start' => 99.0, 'end' => 99.8, 'word' => ' Mercy'], ['start' => 100.2, 'end' => 100.5, 'word' => ' and']];
        foreach ([$evidence->timingWindow($cues, 100.0, 500.0), $evidence->window($cues, 100.0, 500.0, $log)] as $window) {
            $identity = $evidence->identity($log, $window);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => $words]);
        }
        $log->refresh();
        $planner = app(CueSafeExtractionPlan::class);
        $placed = $planner->wordPauseEdge($log, $cues, 100.0, 'start');
        $this->assertNotNull($placed);
        $check = app(SpokenEdgeSentenceCheck::class);
        $oldIdentity = $check->identityFor($log, $cues, $placed['words'], $placed['window'], 100.0, 'start', $placed['time']);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, (new \ReflectionMethod($check, 'kind'))->invoke($check, $oldIdentity), ['identity' => $oldIdentity,
            'agreed' => true, 'decision' => 'keep', 'words_to_move' => '']);
        $plan = $planner->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 400.0]]);
        $this->assertSame($placed['time'], $plan['segments'][0]['start_time']);
        $this->assertTrue($plan['cue_edge_widening'][0]['sentence_check']['asked']);
        $this->assertSame('timing', $plan['cue_edge_widening'][0]['sentence_check']['evidence_source']);
        $wide = $evidence->window($cues, 100.0, 500.0, $log);
        $wideIdentity = $check->identityFor($log, $cues, $evidence->read($log->fresh(), $wide)['words'], $wide, 100.0, 'start', $placed['time']);
        $this->assertNull($check->read($log->fresh(), $wideIdentity));
        $changed = $placed['words'];
        $changed[0]['word'] = ' Changed';
        $timingIdentity = $evidence->identity($log, $placed['window']);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($timingIdentity), ['identity' => $timingIdentity, 'words' => $changed]);
        $changedPlan = $planner->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 400.0]]);
        $this->assertFalse($changedPlan['cue_edge_widening'][0]['sentence_check']['asked']);
        $this->assertNotNull($check->read($log->fresh(), $oldIdentity));
    }

    #[Test]
    public function sentence_projection_preserves_multibyte_names_and_quoted_stops(): void
    {
        $words = [
            ['start' => 100.0, 'end' => 100.4, 'word' => 'pray'],
            ['start' => 100.6, 'end' => 101.0, 'word' => 'for'],
            ['start' => 101.2, 'end' => 101.6, 'word' => 'Mę'],
            ['start' => 101.8, 'end' => 102.2, 'word' => 'today.”'],
        ];
        $mapped = (new \ReflectionMethod(CueSafeExtractionPlan::class, 'sentenceEvidenceAtTiming'))->invoke(app(CueSafeExtractionPlan::class),
            $words, $words, ['start' => 85.0, 'end' => 115.0, 'cues' => []], []);
        $this->assertSame('Mę', $mapped[2]['word']);
        $this->assertSame('today.', $mapped[3]['word']);
    }

    private function runWithLevels(): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local', 'media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => -45.0]);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 500, 'rms_log_path' => 'service-transcripts/wide.rms.log']);
        $lines = [];
        for ($i = 850; $i <= 1150; $i++) {
            $lines[] = 'frame:0 pts:0 pts_time:'.($i / 10);
            $lines[] = 'lavfi.astats.Overall.RMS_level=-20.0';
        }
        Storage::disk('local')->put($log->rms_log_path, implode("\n", $lines));

        return $log;
    }
}
