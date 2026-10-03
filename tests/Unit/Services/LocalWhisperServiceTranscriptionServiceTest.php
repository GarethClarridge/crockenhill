<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Exceptions\TranscriptionException;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioChunkingService;
use App\Services\Media\Audio\LocalWhisperServiceTranscriptionService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Processing\SermonProcessingLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalWhisperServiceTranscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private LocalWhisperServiceTranscriptionService $service;

    private mixed $chunkingService;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.transcription.local_whisper_url', 'http://whisper:8000');

        $logger = Mockery::mock(SermonProcessingLogger::class);
        $logger->shouldIgnoreMissing();

        $this->chunkingService = Mockery::mock(AudioChunkingService::class);

        /** @var SermonProcessingLogger $loggerDependency */
        $loggerDependency = $logger;
        /** @var AudioChunkingService $chunkingDependency */
        $chunkingDependency = $this->chunkingService;

        $this->service = new LocalWhisperServiceTranscriptionService($loggerDependency, $chunkingDependency);
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
    public function edge_decode_returns_words_without_banking_service_artifacts(): void
    {
        Http::fake(function ($request) {
            $this->assertStringContainsString('verbose_json', $request->body());
            $this->assertStringContainsString('timestamp_granularities[]', $request->body());
            return Http::response(['words' => [['start' => 1, 'end' => 2, 'word' => 'Amen']]]);
        });
        $this->assertSame([['start' => 1.0, 'end' => 2.0, 'word' => 'Amen']], $this->service->transcribeEdgeWindow($this->makeTempFile('audio')));

        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    #[Test]
    public function edge_decode_accepts_no_words_and_segment_nested_words(): void
    {
        Http::fakeSequence()->push(['words' => []])->push(['segments' => [['words' => [['start' => 0, 'end' => 1, 'word' => 'Hello']]]]]);
        $path = $this->makeTempFile('audio');
        $this->assertSame([], $this->service->transcribeEdgeWindow($path));
        $this->assertSame([['start' => 0.0, 'end' => 1.0, 'word' => 'Hello']], $this->service->transcribeEdgeWindow($path));
    }

    #[Test]
    public function it_parses_verbose_json_segments_into_cues(): void
    {
        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->with($sourcePath, 'proc-local-1')
            ->andReturn($compressedPath);

        Http::fake([
            'whisper:8000/v1/audio/transcriptions' => Http::response([
                'task' => 'transcribe',
                'duration' => 55.0,
                'text' => 'Good morning and welcome. Our first hymn.',
                'segments' => [
                    ['id' => 0, 'start' => 0.0, 'end' => 20.0, 'text' => ' Good morning and welcome. '],
                    ['id' => 1, 'start' => 20.0, 'end' => 55.0, 'text' => 'Our first hymn.'],
                ],
            ]),
        ]);

        $transcript = $this->service->transcribeService($sourcePath, 'proc-local-1');

        $this->assertSame(ChurchServiceTranscript::SOURCE_LOCAL_WHISPER, $transcript->source);
        $this->assertSame(55.0, $transcript->duration);
        $this->assertSame(
            [
                ['start' => 0.0, 'end' => 20.0, 'text' => 'Good morning and welcome.'],
                ['start' => 20.0, 'end' => 55.0, 'text' => 'Our first hymn.'],
            ],
            $transcript->cues
        );

        Http::assertSent(function ($request): bool {
            // Multipart request: data() is a list of name/contents parts.
            foreach ($request->data() as $part) {
                if (($part['name'] ?? null) === 'response_format') {
                    return ($part['contents'] ?? null) === 'verbose_json';
                }
            }

            return false;
        });
    }

    #[Test]
    public function it_stops_the_decoder_carrying_its_own_text_forward_while_keeping_the_prompt(): void
    {
        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->andReturn($compressedPath);

        Http::fake([
            'whisper:8000/v1/audio/transcriptions' => Http::response([
                'duration' => 20.0,
                'segments' => [
                    ['id' => 0, 'start' => 0.0, 'end' => 20.0, 'text' => 'Good morning and welcome.'],
                ],
            ]),
        ]);

        $this->service->transcribeService($sourcePath, 'proc-local-context');

        Http::assertSent(function ($request): bool {
            $fields = collect($request->data())
                ->reject(fn (array $part): bool => $part['name'] === 'file')
                ->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents']]);

            return $fields->get('max_context') === '0'
                && $fields->get('carry_initial_prompt') === 'true'
                && $fields->get('prompt') === (string) config('media-processing.transcription.prompts.full_service');
        });
    }

    #[Test]
    public function it_throws_when_the_server_returns_no_segments(): void
    {
        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->andReturn($compressedPath);

        Http::fake([
            'whisper:8000/v1/audio/transcriptions' => Http::response([
                'text' => 'A transcript without timings.',
            ]),
        ]);

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('no timestamped segments');

        $this->service->transcribeService($sourcePath, 'proc-local-2');
    }

    #[Test]
    public function it_throws_on_a_failed_response(): void
    {
        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->andReturn($compressedPath);

        Http::fake([
            'whisper:8000/v1/audio/transcriptions' => Http::response('server exploded', 500),
        ]);

        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->service->transcribeService($sourcePath, 'proc-local-3');
    }

    /**
     * WP-A2/A3/A6: the archive must be able to recover the exact bytes the
     * transcription service returned, and know which model produced them.
     */
    #[Test]
    public function it_archives_the_raw_payload_with_its_provenance_and_the_service_audio(): void
    {
        Storage::fake('public');
        Config::set('media-processing.storage.transcript_disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.transcription.local_whisper_model', 'large-v3-turbo');

        $log = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-22',
        ]);

        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->andReturn($compressedPath);

        Http::fake([
            'whisper:8000/v1/audio/transcriptions' => Http::response([
                'duration' => 30.0,
                'text' => 'Good morning.',
                'segments' => [[
                    'id' => 0,
                    'start' => 0.0,
                    'end' => 30.0,
                    'text' => ' Good morning. ',
                    'words' => [['word' => ' Good', 'start' => 0.0, 'end' => 0.4]],
                ]],
            ]),
        ]);

        $this->service->transcribeService($sourcePath, $log->processing_id);

        $artifacts = collect(ServiceArtifactStorage::recordedFor($log->refresh()))
            ->keyBy('kind');

        $this->assertTrue($artifacts->has('raw'), 'The unmodified payload must be archived.');
        $this->assertTrue($artifacts->has('audio'), 'The compressed service audio must be archived.');

        Storage::disk('public')->assertExists($artifacts['raw']['path']);
        Storage::disk('public')->assertExists($artifacts['audio']['path']);

        // The raw payload is the untouched response, words and all.
        $raw = json_decode((string) Storage::disk('public')->get($artifacts['raw']['path']), true);
        $this->assertSame(' Good morning. ', $raw['segments'][0]['text']);
        $this->assertSame(0.4, $raw['segments'][0]['words'][0]['end']);

        $recorded = collect($log->processing_metadata->toArray()[ServiceArtifactStorage::METADATA_KEY])
            ->firstWhere('kind', 'raw');

        $this->assertSame('local_whisper', $recorded['transcription_service']);
        $this->assertSame('large-v3-turbo', $recorded['model']);
        $this->assertStringContainsString('whisper:8000', $recorded['endpoint']);
        $this->assertTrue($recorded['word_timestamps_requested']);
        $this->assertTrue($recorded['word_timestamps_present']);
    }

    #[Test]
    public function it_records_word_timestamps_as_unavailable_when_the_server_omits_them(): void
    {
        Storage::fake('public');
        Config::set('media-processing.storage.transcript_disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'public');

        $log = MediaProcessingLog::factory()->livestream()->create();

        $sourcePath = $this->makeTempFile('source video bytes');
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldReceive('compressAudioForTranscription')
            ->once()
            ->andReturn($compressedPath);

        Http::fake([
            'whisper:8000/v1/audio/transcriptions' => Http::response([
                'duration' => 30.0,
                'segments' => [['id' => 0, 'start' => 0.0, 'end' => 30.0, 'text' => 'Good morning.']],
            ]),
        ]);

        $transcript = $this->service->transcribeService($sourcePath, $log->processing_id);

        // WP-A3 degrades to "confirmed unavailable" rather than failing the run.
        $this->assertCount(1, $transcript->cues);

        $recorded = collect($log->refresh()->processing_metadata->toArray()[ServiceArtifactStorage::METADATA_KEY])
            ->firstWhere('kind', 'raw');

        $this->assertTrue($recorded['word_timestamps_requested']);
        $this->assertFalse($recorded['word_timestamps_present']);
    }

    /**
     * H10b re-decodes a completed run to measure the decoder. Banking would
     * overwrite that run's archived audio and raw response with the new decode.
     */
    #[Test]
    public function it_decodes_compressed_audio_without_banking_anything_against_the_run(): void
    {
        Storage::fake('public');
        Config::set('media-processing.storage.transcript_disk', 'public');
        Config::set('media-processing.storage.sermon_disk', 'public');

        $log = MediaProcessingLog::factory()->livestream()->create();
        $compressedPath = $this->makeTempFile('compressed audio');

        $this->chunkingService->shouldNotReceive('compressAudioForTranscription');

        Http::fake([
            'whisper:8000/v1/audio/transcriptions' => Http::response([
                'duration' => 30.0,
                'segments' => [['id' => 0, 'start' => 0.0, 'end' => 30.0, 'text' => ' Good morning. ']],
            ]),
        ]);

        $transcript = $this->service->decodeCompressedAudio($compressedPath, $log->processing_id);

        $this->assertSame([['start' => 0.0, 'end' => 30.0, 'text' => 'Good morning.']], $transcript->cues);
        $this->assertSame([], ServiceArtifactStorage::recordedFor($log->refresh()));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertFileExists($compressedPath, 'The caller owns the compressed audio.');

        Http::assertSent(function ($request): bool {
            $fields = collect($request->data())->pluck('contents', 'name');

            return $fields['max_context'] === '0' && $fields['response_format'] === 'verbose_json';
        });
    }

    private function makeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'local-whisper-service-test-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
