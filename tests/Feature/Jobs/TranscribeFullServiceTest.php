<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Jobs\TranscribeFullService;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\MockServiceTranscriptionService;
use App\Services\Media\Audio\ServiceTranscriptRecovery;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\TranscriptPromptEchoDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscribeFullServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
        Config::set('media-processing.service_structure.transcription_service', 'mock');
    }

    protected function tearDown(): void
    {
        MockServiceTranscriptionService::useTranscript(null);

        parent::tearDown();
    }

    #[Test]
    public function it_stores_the_transcript_json_and_records_the_metadata_path(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');

        MockServiceTranscriptionService::useTranscript(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 30.0, 'text' => 'Good morning and welcome.'],
            ['start' => 30.0, 'end' => 90.0, 'text' => 'Our first hymn this morning.'],
        ], 5400.0, ChurchServiceTranscript::SOURCE_MOCK));

        $this->runJob($log);

        $expectedPath = 'service-transcripts/unknown-date/other-'.$log->processing_id.'.normalized.json';

        $log->refresh();
        $this->assertSame($expectedPath, $log->serviceTranscriptPath());
        Storage::disk('local')->assertExists($expectedPath);

        $stored = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk('local')->get($expectedPath), true)
        );
        $this->assertSame(5400.0, $stored->duration);
        $this->assertCount(2, $stored->cues);
        $this->assertSame('Good morning and welcome.', $stored->cues[0]['text']);
    }

    #[Test]
    public function it_strips_prompt_echo_cues_before_persisting_the_transcript(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');

        MockServiceTranscriptionService::useTranscript(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 3.5, 'text' => 'This is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.'],
            ['start' => 3.5, 'end' => 30.0, 'text' => 'Please sit down and let us pray together.'],
        ], 30.0, ChurchServiceTranscript::SOURCE_MOCK));

        $this->runJob($log);

        $stored = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk('local')->get((string) $log->refresh()->serviceTranscriptPath()), true)
        );

        $this->assertCount(1, $stored->cues);
        $this->assertSame('Please sit down and let us pray together.', $stored->cues[0]['text']);
        $this->assertSame(30.0, $stored->duration);
    }

    #[Test]
    public function it_overwrites_the_stored_transcript_on_rerun(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');

        MockServiceTranscriptionService::useTranscript(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 10.0, 'text' => 'First run transcript.'],
        ], 100.0, ChurchServiceTranscript::SOURCE_MOCK));

        $this->runJob($log);

        MockServiceTranscriptionService::useTranscript(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 20.0, 'text' => 'Second run transcript.'],
        ], 200.0, ChurchServiceTranscript::SOURCE_MOCK));

        $this->runJob($log->refresh());

        $log->refresh();
        $transcriptPath = (string) $log->serviceTranscriptPath();

        $stored = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk('local')->get($transcriptPath), true)
        );
        $this->assertSame('Second run transcript.', $stored->cues[0]['text']);
        $this->assertSame(200.0, $stored->duration);
    }

    #[Test]
    public function it_reuses_the_stored_transcript_when_the_source_media_is_gone(): void
    {
        // A reclassification run: the temp source video was cleaned up when
        // the original run completed, but its transcript artifact survives.
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $transcriptPath = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($transcriptPath, (string) json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 30.0, 'text' => 'Original run transcript.'],
        ], 100.0, ChurchServiceTranscript::SOURCE_MOCK)->toArray()));
        $log->putServiceTranscriptPath($transcriptPath);

        MockServiceTranscriptionService::useTranscript(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 10.0, 'text' => 'A fresh transcription that must not run.'],
        ], 50.0, ChurchServiceTranscript::SOURCE_MOCK));

        $this->runJob($log->refresh());

        $stored = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk('local')->get($transcriptPath), true)
        );
        $this->assertSame('Original run transcript.', $stored->cues[0]['text']);
        $this->assertSame(100.0, $stored->duration);
    }

    #[Test]
    public function it_still_fails_when_neither_source_media_nor_a_stored_transcript_exists(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $this->expectException(\RuntimeException::class);

        $this->runJob($log);
    }

    #[Test]
    public function it_skips_runs_outside_the_segmentation_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $this->runJob($log);

        $this->assertNull($log->refresh()->serviceTranscriptPath());
    }

    #[Test]
    public function it_transcribes_auto_trim_video_runs(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            ],
        ]);
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');

        $this->runJob($log);

        $this->assertNotNull($log->refresh()->serviceTranscriptPath());
    }

    #[Test]
    public function it_keeps_the_transcript_out_of_temporary_file_cleanup(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');

        $this->runJob($log);

        $log->refresh();
        // The stored transcript must survive run cleanup: structure:evaluate
        // --processing-id reads it after the run completes.
        $this->assertNotNull($log->serviceTranscriptPath());
        $this->assertNotContains((string) $log->serviceTranscriptPath(), $log->temporaryFilePaths());
    }

    #[Test]
    public function it_recovers_a_pathological_window_before_persisting_the_transcript(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');
        $initial = ChurchServiceTranscript::fromCues($this->repeatedThankYouCues(), 1500.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
        $recovered = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 1200.0, 'text' => 'Recovered carol service content.'],
        ], 1500.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
        MockServiceTranscriptionService::useTranscript($initial);

        $recovery = $this->mock(ServiceTranscriptRecovery::class);
        $recovery->shouldReceive('recover')
            ->once()
            ->withArgs(fn (ChurchServiceTranscript $transcript, string $path, string $processingId): bool => $transcript->cues === $initial->cues
                && $path !== ''
                && $processingId === $log->processing_id)
            ->andReturn($recovered);

        $this->runJob($log, $recovery);

        $stored = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk('local')->get((string) $log->refresh()->serviceTranscriptPath()), true),
        );

        $this->assertSame('Recovered carol service content.', $stored->cues[0]['text']);
        $this->assertSame([], $stored->unobservableWindows);
    }

    #[Test]
    public function it_persists_unobservable_windows_when_targeted_retranscription_still_fails(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');
        MockServiceTranscriptionService::useTranscript(
            ChurchServiceTranscript::fromCues($this->repeatedThankYouCues(), 1500.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );
        $unobservable = ChurchServiceTranscript::fromCues(
            [],
            1500.0,
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
            [['start' => 0.0, 'end' => 1200.0, 'reason' => 'retranscription_failed']],
        );

        $recovery = $this->mock(ServiceTranscriptRecovery::class);
        $recovery->shouldReceive('recover')->once()->andReturn($unobservable);

        $this->runJob($log, $recovery);

        $stored = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk('local')->get((string) $log->refresh()->serviceTranscriptPath()), true),
        );

        $this->assertSame([], $stored->cues);
        $this->assertSame($unobservable->unobservableWindows, $stored->unobservableWindows);
        $this->assertSame($unobservable->unobservableWindows, $log->refresh()->serviceTranscriptUnobservableWindows());
    }

    /** @return list<array{start: float, end: float, text: string}> */
    private function repeatedThankYouCues(): array
    {
        $cues = [];

        for ($index = 0; $index < 40; $index++) {
            $cues[] = ['start' => $index * 30.0, 'end' => ($index + 1) * 30.0, 'text' => 'Thank you.'];
        }

        return $cues;
    }

    private function runJob(MediaProcessingLog $log, ?ServiceTranscriptRecovery $recovery = null): void
    {
        (new TranscribeFullService($log))->handle(
            app(StorageAdapterHelper::class),
            app(ServiceTranscriptionInterface::class),
            app(TranscriptPromptEchoDetector::class),
            $recovery ?? app(ServiceTranscriptRecovery::class),
        );
    }
}
