<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\TranscriptionException;
use App\Services\Media\Audio\AudioChunkingService;
use App\Services\Media\Audio\OpenAiServiceTranscriptionService;
use App\Services\Processing\SermonProcessingLogger;
use Illuminate\Support\Facades\Config;
use Mockery;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Audio\TranscriptionResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAiServiceTranscriptionServiceTest extends TestCase
{
    private OpenAiServiceTranscriptionService $service;

    private mixed $chunkingService;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.transcription.openai_api_key', 'test-key');

        $logger = Mockery::mock(SermonProcessingLogger::class);
        $logger->shouldIgnoreMissing();

        $this->chunkingService = Mockery::mock(AudioChunkingService::class);

        /** @var SermonProcessingLogger $loggerDependency */
        $loggerDependency = $logger;
        /** @var AudioChunkingService $chunkingDependency */
        $chunkingDependency = $this->chunkingService;

        $this->service = new OpenAiServiceTranscriptionService($loggerDependency, $chunkingDependency);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_transcribes_a_recording_within_the_upload_limit_in_one_pass(): void
    {
        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->with($sourcePath, 'proc-1')
            ->andReturn($compressedPath);

        OpenAI::fake([
            TranscriptionResponse::fake([
                'duration' => 42.0,
                'segments' => [
                    $this->segment(0, 0.0, 12.5, ' Good morning and welcome. '),
                    $this->segment(1, 12.5, 42.0, 'Our first hymn this morning.'),
                ],
            ]),
        ]);

        $transcript = $this->service->transcribeService($sourcePath, 'proc-1');

        $this->assertSame(ChurchServiceTranscript::SOURCE_WHISPER_API, $transcript->source);
        $this->assertSame(42.0, $transcript->duration);
        $this->assertSame(
            [
                ['start' => 0.0, 'end' => 12.5, 'text' => 'Good morning and welcome.'],
                ['start' => 12.5, 'end' => 42.0, 'text' => 'Our first hymn this morning.'],
            ],
            $transcript->cues
        );
        $this->assertFileDoesNotExist($compressedPath, 'The compressed working copy is cleaned up.');
    }

    #[Test]
    public function it_chunks_oversized_audio_and_reoffsets_cue_times(): void
    {
        // Force the chunking path: everything is "oversized".
        Config::set('media-processing.transcription.max_file_size', 1);

        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio, larger than one byte');
        $chunkOne = $this->makeTempFile('chunk one');
        $chunkTwo = $this->makeTempFile('chunk two');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->andReturn($compressedPath);
        $this->chunkingService->shouldReceive('getAudioDuration')
            ->once()
            ->with($compressedPath)
            ->andReturn(700.0);
        $this->chunkingService->shouldReceive('createAudioChunks')
            ->once()
            ->with($compressedPath, 'proc-2', 700.0)
            ->andReturn([$chunkOne, $chunkTwo]);
        $this->chunkingService->shouldReceive('getChunkDurationMinutes')->andReturn(6);
        $this->chunkingService->shouldReceive('getChunkOverlapSeconds')->andReturn(15);
        $this->chunkingService->shouldReceive('cleanupChunkFiles')
            ->once()
            ->with([$chunkOne, $chunkTwo], 'proc-2');

        // Chunk 1 covers 0–360 s; chunk 2 restarts 15 s earlier at 330 s, so
        // chunk 1 content repeats until 360 s — a 30 s (two-overlap) duplicated
        // window. Every chunk-2 cue starting inside it must be dropped.
        OpenAI::fake([
            TranscriptionResponse::fake([
                'duration' => 360.0,
                'segments' => [
                    $this->segment(0, 0.0, 180.0, 'First chunk, first cue.'),
                    $this->segment(1, 180.0, 360.0, 'First chunk, second cue.'),
                ],
            ]),
            TranscriptionResponse::fake([
                'duration' => 370.0,
                'segments' => [
                    $this->segment(0, 0.0, 14.0, 'Overlap repeat — dropped.'),
                    $this->segment(1, 15.0, 29.0, 'Still inside the duplicated window — dropped.'),
                    $this->segment(2, 30.0, 200.0, 'Second chunk, kept cue.'),
                    $this->segment(3, 200.0, 370.0, 'Second chunk, final cue.'),
                ],
            ]),
        ]);

        $transcript = $this->service->transcribeService($sourcePath, 'proc-2');

        $this->assertSame(
            [
                ['start' => 0.0, 'end' => 180.0, 'text' => 'First chunk, first cue.'],
                ['start' => 180.0, 'end' => 360.0, 'text' => 'First chunk, second cue.'],
                // Chunk 2 starts at 1 × (360 − 15) − 15 = 330 s into the
                // recording; chunk 1 already covered everything up to 360 s.
                ['start' => 360.0, 'end' => 530.0, 'text' => 'Second chunk, kept cue.'],
                ['start' => 530.0, 'end' => 700.0, 'text' => 'Second chunk, final cue.'],
            ],
            $transcript->cues
        );
        $this->assertSame(700.0, $transcript->duration);
    }

    #[Test]
    public function it_throws_when_the_response_has_no_segments(): void
    {
        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->andReturn($compressedPath);

        // The default fake fixture includes a segment and fake() merges, so
        // replace the whole attribute set to model a segmentless response.
        OpenAI::fake([
            TranscriptionResponse::fake([
                'task' => 'transcribe',
                'language' => 'english',
                'duration' => 10.0,
                'segments' => [],
                'text' => 'A transcript without timings.',
            ], strategy: OverrideStrategy::Replace),
        ]);

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('no timestamped segments');

        $this->service->transcribeService($sourcePath, 'proc-3');
    }

    #[Test]
    public function it_rejects_a_missing_api_key_as_non_retryable(): void
    {
        Config::set('media-processing.transcription.openai_api_key', null);

        $this->expectException(NonRetryableTranscriptionException::class);

        $this->service->transcribeService($this->makeTempFile('bytes'), 'proc-4');
    }

    #[Test]
    public function it_throws_when_the_recording_is_missing(): void
    {
        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Recording not found');

        $this->service->transcribeService('/nonexistent/recording.mp4', 'proc-5');
    }

    private function makeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'service-transcription-test-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function segment(int $id, float $start, float $end, string $text): array
    {
        return [
            'id' => $id,
            'seek' => 0,
            'start' => $start,
            'end' => $end,
            'text' => $text,
            'tokens' => [],
            'temperature' => 0.0,
            'avg_logprob' => -0.2,
            'compression_ratio' => 1.0,
            'no_speech_prob' => 0.01,
            'transient' => false,
        ];
    }
}
