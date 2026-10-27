<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeDecodeWindow;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\HistoricMedia\HistoricFailedTranscriptCandidates;
use App\Services\Media\Audio\AudioTimeline;
use App\Support\CanonicalJson;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricFailedTranscriptCandidatesTest extends TestCase
{
    #[Test]
    public function candidate_identity_follows_the_line_when_the_eligible_window_changes(): void
    {
        $service = app(HistoricFailedTranscriptCandidates::class);
        $donor = $this->donor([['start' => 71, 'end' => 72, 'text' => 'Thank you.']]);
        $first = ChurchServiceTranscript::fromCues([], 100, 'local_whisper',
            [['start' => 10.0, 'end' => 90.0, 'reason' => 'retranscription_failed']]);
        $second = ChurchServiceTranscript::fromCues([], 100, 'local_whisper',
            [['start' => 40.0, 'end' => 90.0, 'reason' => 'retranscription_failed']]);
        self::assertSame($service->inspect($first, $donor)['candidates'][0]['id'],
            $service->inspect($second, $donor)['candidates'][0]['id']);
    }

    #[Test]
    public function empty_stored_text_does_not_hide_the_banked_sermon_opening(): void
    {
        $current = ChurchServiceTranscript::fromCues(
            [['start' => 2010.3, 'end' => 2015.0, 'text' => 'The sermon continues.']],
            2200,
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
            [['start' => 1734.1, 'end' => 2010.1, 'reason' => 'retranscription_failed']],
        );
        $donor = ChurchServiceTranscript::fromCues(
            [['start' => 1939.26, 'end' => 1943.0, 'text' => 'Have any of you had a conversation where someone said']],
            2200,
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        );

        $report = app(HistoricFailedTranscriptCandidates::class)->inspect($current, $donor->toArray());

        self::assertCount(1, $report['candidates'], 'Failed windows with zero stored tokens must enter the candidate route.');
        self::assertSame(1939.26, $report['candidates'][0]['cue']['start']);
        self::assertSame([], $report['candidates'][0]['flags']);
    }

    #[Test]
    public function singleton_filler_is_quarantined_but_short_real_utterances_survive(): void
    {
        $service = app(HistoricFailedTranscriptCandidates::class);
        $current = $this->current();
        $report = $service->inspect($current, $this->donor([
            ['start' => 10, 'end' => 40, 'text' => 'Thank you.'],
            ['start' => 40, 'end' => 70, 'text' => 'The End'],
            ['start' => 71, 'end' => 72, 'text' => 'Thank you.'],
            ['start' => 73, 'end' => 74, 'text' => 'Amen.'],
        ]));
        self::assertContains('long_low_information', $report['candidates'][0]['flags']);
        self::assertContains('long_low_information', $report['candidates'][1]['flags']);
        self::assertSame([], $report['candidates'][2]['flags']);
        self::assertSame([], $report['candidates'][3]['flags']);
        $output = $service->materialize($current, $report, [$report['candidates'][3]['id'], $report['candidates'][2]['id']]);
        self::assertSame(['Known text', 'Thank you.', 'Amen.', 'Following text'], array_column($output->cues, 'text'));
        self::assertSame($current->duration, $output->duration);
        self::assertSame($current->source, $output->source);
        self::assertSame(['retranscription_failed', 'carried_from_h10b_redecode', 'retranscription_failed',
            'carried_from_h10b_redecode', 'retranscription_failed'], array_column($output->unobservableWindows, 'reason'));
    }

    #[Test]
    public function screening_full_context_catches_a_short_slice_of_a_long_loop(): void
    {
        $cues = [];
        for ($i = 0; $i < 6; $i++) {
            $cues[] = ['start' => $i * 30.0, 'end' => ($i + 1) * 30.0, 'text' => 'Thank you.'];
        }
        $current = ChurchServiceTranscript::fromCues([], 200, 'local_whisper', [['start' => 60, 'end' => 90, 'reason' => 'retranscription_failed']]);
        $report = app(HistoricFailedTranscriptCandidates::class)->inspect($current, $this->donor($cues));
        self::assertCount(1, $report['candidates']);
        self::assertContains('pathological', $report['candidates'][0]['flags']);
    }

    #[Test]
    public function crossing_and_overlapping_cues_are_quarantined_without_clipping_or_duplication(): void
    {
        $service = app(HistoricFailedTranscriptCandidates::class);
        $current = $this->current();
        $report = $service->inspect($current, $this->donor([
            ['start' => 5, 'end' => 11, 'text' => 'Crossing known text'],
            ['start' => 20, 'end' => 25, 'text' => 'First overlapping cue'],
            ['start' => 24, 'end' => 26, 'text' => 'Second overlapping cue'],
            ['start' => 30, 'end' => 32, 'text' => 'Duplicate'],
            ['start' => 30, 'end' => 32, 'text' => 'Duplicate'],
        ]));
        self::assertCount(4, $report['candidates']);
        self::assertSame(5.0, $report['candidates'][0]['cue']['start']);
        self::assertContains('crosses_failed_window', $report['candidates'][0]['flags']);
        foreach (array_slice($report['candidates'], 1) as $candidate) {
            self::assertContains('overlapping_donor_cues', $candidate['flags']);
        }
        self::assertSame($current->toArray(), $service->materialize($current, $report, [])->toArray());
        $this->expectException(RuntimeException::class);
        $service->materialize($current, $report, [$report['candidates'][0]['id']]);
    }

    #[Test]
    public function donor_blind_spans_and_malformed_cues_are_reported_before_normalization(): void
    {
        $donor = $this->donor([
            ['start' => -1, 'end' => 30, 'text' => 'Negative'],
            ['start' => 20, 'end' => 18, 'text' => 'Reversed'],
            ['start' => 50, 'end' => 120, 'text' => 'Past the recording'],
            ['start' => 22, 'end' => 23, 'text' => 'Blind speech'],
        ]);
        $donor['unobservable_windows'] = [['start' => 21, 'end' => 25, 'reason' => 'failed']];
        $report = app(HistoricFailedTranscriptCandidates::class)->inspect($this->current(), $donor);
        self::assertCount(3, $report['rejected']);
        self::assertCount(1, $report['candidates']);
        self::assertContains('donor_unobservable', $report['candidates'][0]['flags']);
    }

    #[Test]
    public function malformed_donor_blind_metadata_cannot_disappear_during_normalization(): void
    {
        $donor = $this->donor([['start' => 20, 'end' => 22, 'text' => 'Blind words']]);
        $donor['unobservable_windows'] = [['start' => 'unreadable', 'end' => 23, 'reason' => 'failed']];
        $this->expectExceptionMessage('Invalid donor blind span');
        app(HistoricFailedTranscriptCandidates::class)->inspect($this->current(), $donor);
    }

    #[Test]
    public function classifier_corroborated_sparse_timing_is_quarantined(): void
    {
        $timeline = AudioTimeline::fromArray(['model' => 'test', 'model_revision' => 'test', 'window_seconds' => 100,
            'audio_seconds' => 100, 'windows' => [['start' => 0, 'end' => 100, 'music' => 1, 'speech' => 0]]]);
        $report = app(HistoricFailedTranscriptCandidates::class)->inspect($this->current(),
            $this->donor([['start' => 20, 'end' => 38, 'text' => 'This timing is stretched over the singing']]), $timeline);
        self::assertContains('suspect_timing_or_no_words', $report['candidates'][0]['flags']);
    }

    #[Test]
    public function json_key_order_cannot_drop_provenance_windows(): void
    {
        $service = app(HistoricFailedTranscriptCandidates::class);
        $current = $this->current();
        $report = $service->inspect($current, $this->donor([['start' => 20, 'end' => 22, 'text' => 'Actual speech']]));
        $roundTrip = json_decode(CanonicalJson::encode($report), true, flags: JSON_THROW_ON_ERROR);
        $expected = $service->materialize($current, $report, [$report['candidates'][0]['id']]);
        $actual = $service->materialize($current, $roundTrip, [$report['candidates'][0]['id']]);
        self::assertSame($expected->toArray(), $actual->toArray());
        self::assertSame(HistoricFailedTranscriptCandidates::REASON, $actual->unobservableWindows[1]['reason']);
    }

    #[Test]
    #[DataProvider('ineligibleReasons')]
    public function other_window_reasons_are_preserved(string $reason): void
    {
        $current = ChurchServiceTranscript::fromCues([], 100, 'local_whisper', [['start' => 10, 'end' => 90, 'reason' => $reason]]);
        $service = app(HistoricFailedTranscriptCandidates::class);
        $report = $service->inspect($current, $this->donor([['start' => 20, 'end' => 22, 'text' => 'Real text']]));
        self::assertSame([], $report['candidates']);
        self::assertSame($current->toArray(), $service->materialize($current, $report, [])->toArray());
    }

    public static function ineligibleReasons(): array
    {
        return array_map(static fn (string $r): array => [$r], ['window_holds_no_sound', 'retranscription_unavailable',
            'repetition_retry_empty', 'repetition_retry_looped', HistoricFailedTranscriptCandidates::REASON]);
    }

    #[Test]
    public function the_real_planner_can_use_candidate_cues_without_reading_or_installing_a_transcript(): void
    {
        $evidence = Mockery::mock(OutputEdgeWordTimings::class, [app(OutputEdgeDecodeWindow::class)])->makePartial();
        $evidence->shouldNotReceive('cues');
        $evidence->shouldReceive('read')->andReturn(['words' => []]);
        $evidence->shouldReceive('heardWords')->andReturn([]);
        $this->app->instance(OutputEdgeWordTimings::class, $evidence);
        $run = new MediaProcessingLog(['processing_id' => 'read-only', 'duration' => 100, 'processing_metadata' => []]);
        $run->setRelation('serviceSections', collect());
        $candidate = ChurchServiceTranscript::fromCues([['start' => 15, 'end' => 25, 'text' => 'A complete spoken line']], 100, 'local_whisper');
        $before = $run->getAttributes();
        $plan = app(CueSafeExtractionPlan::class)->forSpans($run, [['start_time' => 20.0, 'end_time' => 30.0]], transcript: $candidate);
        self::assertSame(15.0, $plan['segments'][0]['start_time']);
        self::assertSame($before, $run->getAttributes());
        self::assertNull($run->serviceTranscriptPath());
    }

    private function current(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([['start' => 1, 'end' => 4, 'text' => 'Known text'],
            ['start' => 95, 'end' => 98, 'text' => 'Following text']], 100, 'local_whisper',
            [['start' => 10, 'end' => 90, 'reason' => 'retranscription_failed']]);
    }

    private function donor(array $cues): array
    {
        return ['source' => 'local_whisper', 'duration' => 300, 'cues' => $cues];
    }
}
