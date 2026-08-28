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
    public function it_removes_corrupt_cues_and_marks_the_window_when_retry_is_still_pathological(): void
    {
        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn('/clip.mp3');
        $extractor->shouldReceive('delete')->once()->with('/clip.mp3');

        $transcription = Mockery::mock(ServiceTranscriptionInterface::class);
        $transcription->shouldReceive('transcribeService')->once()->andReturn($this->pathologicalTranscript());

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

    private function pathologicalTranscript(): ChurchServiceTranscript
    {
        $cues = [];

        for ($index = 0; $index < 40; $index++) {
            $cues[] = ['start' => $index * 30.0, 'end' => ($index + 1) * 30.0, 'text' => 'Thank you.'];
        }

        $cues[] = ['start' => 1200.0, 'end' => 1210.0, 'text' => 'Closing prayer.'];

        return ChurchServiceTranscript::fromCues($cues, 1210.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }
}
