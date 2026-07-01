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
    public function it_skips_runs_outside_the_segmentation_pipeline(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $this->runJob($log);

        $this->assertNull($log->refresh()->serviceTranscriptPath());
    }

    #[Test]
    public function it_includes_the_transcript_in_temporary_file_cleanup(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        Storage::disk('local')->put((string) $log->source_file_path, 'fake video bytes');

        $this->runJob($log);

        $log->refresh();
        $this->assertContains((string) $log->serviceTranscriptPath(), $log->temporaryFilePaths());
    }

    private function runJob(MediaProcessingLog $log): void
    {
        (new TranscribeFullService($log))->handle(
            app(StorageAdapterHelper::class),
            app(ServiceTranscriptionInterface::class),
        );
    }
}
