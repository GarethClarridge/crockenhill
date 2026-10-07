<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An operator's answer to a refused edge is given on the evidence they heard and for the output
 * they were asked about (Codex review, 2026-10-07). Matching on the edge, its reason and two
 * timestamps let changed evidence that lands on the same times inherit the old answer, and let
 * an answer for one output clear the same edge in another.
 */
class EdgeAnswerBindingTest extends TestCase
{
    use DatabaseTransactions;

    private const CUE = ['start' => 10.0, 'end' => 11.0, 'text' => 'thank you'];

    private const WORDS = [
        ['start' => 9.5, 'end' => 9.7, 'word' => ' thank'],
        ['start' => 9.7, 'end' => 9.9, 'word' => ' you'],
        ['start' => 10.1, 'end' => 10.3, 'word' => ' thank'],
        ['start' => 10.3, 'end' => 10.5, 'word' => ' you'],
        ['start' => 11.6, 'end' => 11.9, 'word' => ' so'],
    ];

    private const OUTPUT = [['start_time' => 10.0, 'end_time' => 50.0]];

    #[Test]
    public function unchanged_evidence_reuses_its_answer(): void
    {
        $log = $this->log([self::CUE, ['start' => 30.0, 'end' => 32.0, 'text' => 'and so we come']], self::WORDS);
        $this->answer($log, self::OUTPUT, 9.9);

        // The same transcript written again, and a fresh read of the run: nothing the edge rests on changed.
        $this->transcript($log, [self::CUE, ['start' => 30.0, 'end' => 32.0, 'text' => 'and so we come']]);
        $plan = $this->plans()->forSpans($log->fresh(), self::OUTPUT);

        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
        $this->assertSame(9.9, $plan['segments'][0]['start_time']);
    }

    /** A line near the edge re-transcribed with the same timings: the operator heard other evidence. */
    #[Test]
    public function changed_transcript_with_unchanged_timestamps_invalidates_an_answer(): void
    {
        $log = $this->log([self::CUE, ['start' => 30.0, 'end' => 32.0, 'text' => 'and so we come']], self::WORDS);
        $answered = $this->answer($log, self::OUTPUT, 9.9);

        $this->transcript($log, [self::CUE, ['start' => 30.0, 'end' => 32.0, 'text' => 'and so we sing']]);
        $plan = $this->plans()->forSpans($log->fresh(), self::OUTPUT);
        $entry = $plan['cue_edge_widening'][0];

        $this->assertSame([$answered['time'], $answered['original_time']], [$entry['proposed_time'] ?? $entry['time'], $entry['original_time']], 'the timestamps did not change');
        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /** The edge re-decoded: the words differ, though they fall at the same times. */
    #[Test]
    public function changed_decoded_words_with_unchanged_timestamps_invalidate_an_answer(): void
    {
        $log = $this->log([self::CUE], self::WORDS);
        $answered = $this->answer($log, self::OUTPUT, 9.9);

        $words = self::WORDS;
        $words[4]['word'] = ' sir';
        $this->bank($log, self::CUE, $words);
        $plan = $this->plans()->forSpans($log->fresh(), self::OUTPUT);

        $entry = $plan['cue_edge_widening'][0];
        $this->assertSame([$answered['time'], $answered['original_time']], [$entry['proposed_time'] ?? $entry['time'], $entry['original_time']], 'the timestamps did not change');
        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    /**
     * The same refused edge in two outputs (a clip and the longer output that starts with it): an
     * answer about one is no answer about the other.
     */
    #[Test]
    public function an_answer_cannot_clear_another_outputs_question(): void
    {
        $log = $this->log([self::CUE], self::WORDS);
        $this->answer($log, self::OUTPUT, 9.9);

        $other = $this->plans()->forSpans($log->fresh(), [['start_time' => 10.0, 'end_time' => 5000.0]]);
        $own = $this->plans()->forSpans($log->fresh(), self::OUTPUT);

        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($other['cue_edge_widening']));
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($own['cue_edge_widening']));
    }

    /** New evidence moves the proposed cut: the question is new, and stays open. */
    #[Test]
    public function a_changed_proposed_cut_remains_unanswered(): void
    {
        $log = $this->log([self::CUE], self::WORDS);
        $answered = $this->answer($log, self::OUTPUT, 9.9);

        $words = self::WORDS;
        $words[0] = ['start' => 9.2, 'end' => 9.7, 'word' => ' thank'];
        $this->bank($log, self::CUE, $words);
        $plan = $this->plans()->forSpans($log->fresh(), self::OUTPUT);

        $this->assertNotSame($answered['time'], $plan['cue_edge_widening'][0]['proposed_time'] ?? $plan['cue_edge_widening'][0]['time']);
        $this->assertCount(1, CueSafeExtractionPlan::unresolvedEdges($plan['cue_edge_widening']));
    }

    private function plans(): CueSafeExtractionPlan
    {
        return app(CueSafeExtractionPlan::class);
    }

    /**
     * Answer the output's one refused edge as the answer command records it: against the key of
     * the refusal the operator heard.
     *
     * @param  list<array{start_time: float, end_time: float}>  $spans
     * @return array<string, mixed> The refused edge's audit entry
     */
    private function answer(MediaProcessingLog $log, array $spans, float $time): array
    {
        $entry = collect($this->plans()->forSpans($log->fresh(), $spans)['cue_edge_widening'])
            ->firstWhere('reason', CueSafeExtractionPlan::AMBIGUOUS_CUE_ANCHOR);
        $this->assertIsArray($entry);
        $log->writeProcessingMetadata(static function (array $metadata) use ($entry, $time): array {
            $metadata[CueSafeExtractionPlan::EDGE_ANSWERS_KEY][] = ['key' => CueSafeExtractionPlan::edgeAnswerKey($entry), 'edge' => $entry['edge'],
                'reason' => $entry['reason'], 'proposed_time' => $entry['time'], 'original_time' => $entry['original_time'],
                'decision' => 'cut_at', 'time' => $time];

            return $metadata;
        });
        $this->assertSame([], CueSafeExtractionPlan::unresolvedEdges($this->plans()->forSpans($log->fresh(), $spans)['cue_edge_widening']), 'the answer applies');

        return $entry;
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{start: float, end: float, word: string}>  $words
     */
    private function log(array $cues, array $words): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $log->putServiceTranscriptPath('temp/edge-answer.json');
        $this->transcript($log, $cues);
        $this->bank($log, $cues[0], $words);

        return $log->fresh();
    }

    /** @param list<array{start: float, end: float, text: string}> $cues */
    private function transcript(MediaProcessingLog $log, array $cues): void
    {
        Storage::disk('local')->put('temp/edge-answer.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{start: float, end: float, text: string}  $cue
     * @param  list<array{start: float, end: float, word: string}>  $words
     */
    private function bank(MediaProcessingLog $log, array $cue, array $words): void
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $identity = $evidence->identity($log, ['start' => max(0.0, $cue['start'] - 1), 'end' => $cue['end'] + 1]);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => $words, 'compute_seconds' => 1.0]);
    }
}
