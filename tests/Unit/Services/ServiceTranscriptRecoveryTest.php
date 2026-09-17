<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Services\Media\Audio\PathologicalWindowSoundSpans;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use App\Services\Media\Audio\ServiceTranscriptRecovery;
use App\Services\Media\Audio\SupersededTranscriptFallback;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ServiceTranscriptRecoveryTest extends TestCase
{
    #[Test]
    public function it_replaces_only_the_pathological_window_with_offset_retry_cues(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->with('/recording.mp4', 0.0, 1200.0, 'run-1')->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->with('/clip.mp3', 'run-1-recovery-1', '')->andReturn(
            ChurchServiceTranscript::fromCues([
                ['start' => 10.0, 'end' => 20.0, 'text' => 'Once in royal David’s city.'],
            ], 1200.0, ChurchServiceTranscript::SOURCE_WHISPER_API),
        );

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertSame('Once in royal David’s city.', $recovered->cues[0]['text']);
        $this->assertSame(10.0, $recovered->cues[0]['start']);
        $this->assertSame('Closing prayer.', $recovered->cues[1]['text']);
        $this->assertSame([], $recovered->unobservableWindows);
    }

    #[Test]
    public function it_removes_corrupt_cues_and_marks_the_window_when_the_retry_loops_throughout(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andReturn($this->loopingRetry(0.0, 40));

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertSame(['Closing prayer.'], array_column($recovered->cues, 'text'));
        $this->assertSame([[
            'start' => 0.0,
            'end' => 1200.0,
            'reason' => 'retranscription_failed',
        ]], $recovered->unobservableWindows);
    }

    #[Test]
    public function it_marks_the_whole_window_when_the_retry_yields_nothing_at_all(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andReturn(
            ChurchServiceTranscript::fromCues([], 1200.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertSame(['Closing prayer.'], array_column($recovered->cues, 'text'));
        $this->assertSame([[
            'start' => 0.0,
            'end' => 1200.0,
            'reason' => 'retranscription_failed',
        ]], $recovered->unobservableWindows);
    }

    /**
     * Run 1229's shape: a loop over the music at the window's leading edge, then
     * the sermon. Judging the retry as one verdict discarded all 3,282 recovered
     * words and banked the whole 2,037-second window as unobservable.
     */
    #[Test]
    public function it_keeps_the_speech_a_partly_looping_retry_recovered(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andReturn($this->partlyLoopingRetry());

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertSame([[
            'start' => 0.0,
            'end' => 182.0,
            'reason' => 'retranscription_failed',
        ]], $recovered->unobservableWindows, 'Only the looping region is unobservable.');

        $this->assertSame([
            'Our text this morning is in Romans chapter five.',
            'And having been justified by faith, we have peace with God.',
            'Closing prayer.',
        ], array_column($recovered->cues, 'text'));
    }

    /**
     * The residual window is measured against the retry clip, which starts at
     * zero. It has to be placed back on the recording's own clock.
     */
    #[Test]
    public function it_offsets_a_residual_looping_region_onto_the_recording_clock(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->with('/recording.mp4', 600.0, 1800.0, 'run-1')->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andReturn($this->partlyLoopingRetry());

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(600.0), '/recording.mp4', 'run-1');

        $this->assertSame([[
            'start' => 600.0,
            'end' => 782.0,
            'reason' => 'retranscription_failed',
        ]], $recovered->unobservableWindows);

        $this->assertSame(790.0, $recovered->cues[0]['start'], 'Recovered cues carry the same offset.');
    }

    #[Test]
    public function it_retranscribes_an_isolated_window_without_whole_service_priming(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once();

        // The full-service prompt describes a whole service. Priming a music-only
        // window with it makes the model emit service-shaped text over non-speech,
        // which is the very pathology this retry exists to clear.
        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')
            ->once()
            ->with('/clip.mp3', 'run-1-recovery-1', '')
            ->andReturn(ChurchServiceTranscript::fromCues([
                ['start' => 0.0, 'end' => 10.0, 'text' => 'All praise to him who reigns above.'],
            ], 1200.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER));

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertSame([], $recovered->unobservableWindows);
    }

    #[Test]
    public function it_keeps_the_original_cues_when_the_window_cannot_be_extracted(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andThrow(new RuntimeException('ffmpeg missing'));
        $extractor->shouldNotReceive('delete');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldNotReceive('transcribeService');

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertCount(41, $recovered->cues, 'An infrastructure failure must not destroy transcript content.');
        $this->assertSame([[
            'start' => 0.0,
            'end' => 1200.0,
            'reason' => 'retranscription_unavailable',
        ]], $recovered->unobservableWindows);
    }

    #[Test]
    public function it_keeps_the_original_cues_when_retranscription_throws(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andThrow(new RuntimeException('whisper timed out'));

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertCount(41, $recovered->cues, 'A transcription outage must not destroy transcript content.');
        $this->assertSame('retranscription_unavailable', $recovered->unobservableWindows[0]['reason']);
    }

    /**
     * Run 1340's shape (2021-01-17). The looping window ran 1355–1595, but the
     * recording holds only a −52 dB noise floor to 1520, then sixty seconds of
     * digital silence, with speech resuming about 1576. Decoding the window
     * whole hands the model a clip that is five-sixths silence and it loops
     * again; a clip around the speech edge transcribes the sentence cleanly.
     */
    #[Test]
    public function it_aims_the_retry_at_the_sound_rather_than_the_whole_looping_window(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        // Only the sound-bearing edge is cut: 1574 (1576 less the margin) to
        // the window end, not the 1,200 s window.
        $extractor->shouldReceive('extract')->once()
            ->with('/recording.mp4', 1574.0, 1595.0, 'run-1')
            ->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->with('/clip.mp3', 'run-1-recovery-1', '')->andReturn(
            ChurchServiceTranscript::fromCues([
                ['start' => 2.0, 'end' => 20.0, 'text' => 'This tells the story of a woman living in France.'],
            ], 21.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover(
            $this->transcriptLoopingBetween(1355.0, 1595.0),
            '/recording.mp4',
            'run-1',
            $this->rmsLogWithSpeechFrom(1576.0, 1595.0, 1355.0),
        );

        // The sentence lands at its real recording time, not at the window start.
        $texts = array_column($recovered->cues, 'text');
        $this->assertContains('This tells the story of a woman living in France.', $texts);

        $sentence = collect($recovered->cues)->firstWhere('text', 'This tells the story of a woman living in France.');
        $this->assertEqualsWithDelta(1576.0, $sentence['start'], 0.01);
        $this->assertSame([], $recovered->unobservableWindows);
    }

    /**
     * When the window really is silent there is nothing to decode, and paying a
     * provider to confirm it is waste. It is recorded as a property of the
     * recording rather than as a retry that failed — the acceptance accounting
     * treats unfinished work and a silent service differently.
     */
    #[Test]
    public function it_skips_the_decode_and_names_a_window_that_holds_no_sound(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldNotReceive('extract');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldNotReceive('transcribeService');

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover(
            $this->transcriptLoopingBetween(1355.0, 1595.0),
            '/recording.mp4',
            'run-1',
            $this->silentRmsLog(1355.0, 1595.0),
        );

        $this->assertSame([[
            'start' => 1355.0,
            'end' => 1595.0,
            'reason' => 'window_holds_no_sound',
        ]], $recovered->unobservableWindows);
    }

    /**
     * Without an RMS log there is nothing to narrow by, so the window is
     * retried whole — the behaviour this replaced — and keeps the historic
     * `…-recovery-N` artifact name the replay addresses banked retries by.
     */
    #[Test]
    public function it_retries_the_whole_window_and_keeps_the_legacy_artifact_name_without_an_rms_log(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->with('/recording.mp4', 1355.0, 1595.0, 'run-1')->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->with('/clip.mp3', 'run-1-recovery-1', '')->andReturn(
            ChurchServiceTranscript::fromCues([
                ['start' => 221.0, 'end' => 239.0, 'text' => 'This tells the story of a woman living in France.'],
            ], 240.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover($this->transcriptLoopingBetween(1355.0, 1595.0), '/recording.mp4', 'run-1');

        $sentence = collect($recovered->cues)->firstWhere('text', 'This tells the story of a woman living in France.');
        $this->assertEqualsWithDelta(1576.0, $sentence['start'], 0.01);
    }

    /**
     * The five 09-17 macro-song re-runs each came back pointing at a fresh
     * transcript whose window the new pass had failed to decode, while the
     * recovered file holding real speech for that window sat on the disk
     * untouched. When the retry still fails, the transcript being replaced is
     * consulted before the window is written off.
     */
    #[Test]
    public function it_carries_a_failed_windows_speech_from_the_transcript_it_replaces(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        // This pass decodes nothing for the window.
        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andReturn(
            ChurchServiceTranscript::fromCues([], 240.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $superseded = ChurchServiceTranscript::fromCues([
            ['start' => 1347.3, 'end' => 1348.3, 'text' => 'And I will be able to find the way.'],
            ['start' => 1576.3, 'end' => 1595.3, 'text' => 'Montgomery Boyce tells the story of a woman living in...'],
        ], 1615.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover(
            $this->transcriptLoopingBetween(1355.0, 1595.0),
            '/recording.mp4',
            'run-1',
            null,
            $superseded,
        );

        $texts = array_column($recovered->cues, 'text');
        $this->assertContains('Montgomery Boyce tells the story of a woman living in...', $texts);

        // 1347.3 falls *before* the window, so the fallback does not reach it.
        // The window is the unit of repair: a cue the fresh pass simply did not
        // produce outside one is not this path's to restore, and inventing a
        // wider reach would carry old text over new on sound the pass did read.
        $this->assertNotContains('And I will be able to find the way.', $texts);

        // The text is real but it did not come from this decode, so the window
        // stays recorded — with a reason that says where the words came from.
        $this->assertSame([[
            'start' => 1355.0,
            'end' => 1595.0,
            'reason' => SupersededTranscriptFallback::REASON,
        ]], $recovered->unobservableWindows);
    }

    /**
     * Measured silence is not a gap an older transcript may fill: if this pass
     * found no sound there, an older transcript claiming speech was inventing
     * it, and carrying that forward would reinstate a hallucination.
     */
    #[Test]
    public function it_does_not_carry_speech_into_a_window_measured_as_silent(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldNotReceive('extract');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldNotReceive('transcribeService');

        $superseded = ChurchServiceTranscript::fromCues([
            ['start' => 1400.0, 'end' => 1420.0, 'text' => 'Speech no microphone ever heard.'],
        ], 1615.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover(
            $this->transcriptLoopingBetween(1355.0, 1595.0),
            '/recording.mp4',
            'run-1',
            $this->silentRmsLog(1355.0, 1595.0),
            $superseded,
        );

        $this->assertNotContains('Speech no microphone ever heard.', array_column($recovered->cues, 'text'));
        $this->assertSame('window_holds_no_sound', $recovered->unobservableWindows[0]['reason']);
    }

    /**
     * An older transcript can loop in the same place. Carrying its loop forward
     * would re-create the defect this whole path exists to clear.
     */
    #[Test]
    public function it_does_not_carry_a_loop_the_superseded_transcript_had_in_the_same_window(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andReturn(
            ChurchServiceTranscript::fromCues([], 240.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $recovered = (new ServiceTranscriptRecovery(
            new ServiceTranscriptPathologyDetector,
            $extractor,
            $transcription,
            new RmsAnalysisService,
            new PathologicalWindowSoundSpans,
            new SupersededTranscriptFallback(new ServiceTranscriptPathologyDetector),
        ))->recover(
            $this->transcriptLoopingBetween(1355.0, 1595.0),
            '/recording.mp4',
            'run-1',
            null,
            $this->transcriptLoopingBetween(1355.0, 1595.0),
        );

        $this->assertNotContains('Thank you.', array_column($recovered->cues, 'text'));
        $this->assertSame('retranscription_failed', $recovered->unobservableWindows[0]['reason']);
    }

    /**
     * A transcript whose only pathology is one 30-second-chunk loop between the
     * given times, with real speech either side.
     */
    private function transcriptLoopingBetween(float $start, float $end): ChurchServiceTranscript
    {
        $cues = [['start' => $start - 20.0, 'end' => $start, 'text' => 'Refresh my soul in death.']];

        for ($at = $start; $at < $end; $at += 30.0) {
            $cues[] = ['start' => $at, 'end' => min($at + 30.0, $end), 'text' => 'Thank you.'];
        }

        $cues[] = ['start' => $end + 6.0, 'end' => $end + 16.0, 'text' => 'It became known as her promise box.'];

        return ChurchServiceTranscript::fromCues($cues, $end + 20.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }

    /**
     * An astats log reading digital silence across the window except for speech
     * between $speechFrom and $speechTo.
     */
    private function rmsLogWithSpeechFrom(float $speechFrom, float $speechTo, float $windowStart): string
    {
        $lines = [];

        for ($at = $windowStart - 30.0; $at <= $speechTo + 10.0; $at += 1.0) {
            $level = $at >= $speechFrom && $at <= $speechTo ? '-26.500000' : '-inf';
            $lines[] = sprintf('frame:0    pts:0       pts_time:%.3f', $at);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.$level;
        }

        return implode("\n", $lines);
    }

    private function silentRmsLog(float $from, float $to): string
    {
        $lines = [];

        for ($at = $from; $at <= $to; $at += 1.0) {
            $lines[] = sprintf('frame:0    pts:0       pts_time:%.3f', $at);
            // The noise floor run 1340 actually records where it is not silent.
            $lines[] = 'lavfi.astats.Overall.RMS_level=-52.000000';
        }

        return implode("\n", $lines);
    }

    /**
     * A full-service transcript that looped for 1,200 seconds from $start, then
     * recorded one real cue.
     */
    private function pathologicalTranscript(float $start = 0.0): ChurchServiceTranscript
    {
        $cues = $this->loopingCues($start, 40, 30.0, 'Thank you.');
        $cues[] = ['start' => $start + 1200.0, 'end' => $start + 1210.0, 'text' => 'Closing prayer.'];

        return ChurchServiceTranscript::fromCues($cues, $start + 1210.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }

    /**
     * A retry that looped over its whole clip and recovered nothing.
     */
    private function loopingRetry(float $start, int $count): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues(
            $this->loopingCues($start, $count, 30.0, 'Thank you.'),
            $count * 30.0,
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
        );
    }

    /**
     * A retry shaped like run 1229's: whisper hallucinated a chunk-length loop
     * over the music at the clip's leading edge, then transcribed the sermon.
     */
    private function partlyLoopingRetry(): ChurchServiceTranscript
    {
        $cues = $this->loopingCues(0.0, 7, 26.0, 'Amen.');
        $cues[] = ['start' => 190.0, 'end' => 200.0, 'text' => 'Our text this morning is in Romans chapter five.'];
        $cues[] = ['start' => 200.0, 'end' => 210.0, 'text' => 'And having been justified by faith, we have peace with God.'];

        return ChurchServiceTranscript::fromCues($cues, 1200.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }

    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private function loopingCues(float $start, int $count, float $length, string $text): array
    {
        $cues = [];

        for ($index = 0; $index < $count; $index++) {
            $cues[] = [
                'start' => $start + ($index * $length),
                'end' => $start + (($index + 1) * $length),
                'text' => $text,
            ];
        }

        return $cues;
    }
}
