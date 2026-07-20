<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Jobs\TranscribeFullService;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\MockServiceTranscriptionService;
use App\Services\Processing\StorageAdapterHelper;
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

        $expectedPath = 'temp/service_transcript_'.$log->processing_id.'.json';

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

    private function runJob(MediaProcessingLog $log): void
    {
        (new TranscribeFullService($log))->handle(
            app(StorageAdapterHelper::class),
            app(ServiceTranscriptionInterface::class),
        );
    }
}
