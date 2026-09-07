<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use App\Services\Media\Audio\ServiceTranscriptRecovery;
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
        ))->recover($this->pathologicalTranscript(), '/recording.mp4', 'run-1');

        $this->assertCount(41, $recovered->cues, 'A transcription outage must not destroy transcript content.');
        $this->assertSame('retranscription_unavailable', $recovered->unobservableWindows[0]['reason']);
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
