<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Audio\TranscriptStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateSermonTranscriptFromServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
    }

    #[Test]
    public function it_stores_the_sermon_slice_of_the_full_service_transcript(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);
        $serviceTranscriptPath = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($serviceTranscriptPath, json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 90.0, 'end' => 110.0, 'text' => 'Welcome.'],
            ['start' => 110.0, 'end' => 190.0, 'text' => 'The sermon text.'],
            ['start' => 190.0, 'end' => 210.0, 'text' => 'Closing prayer.'],
        ], 300.0, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($serviceTranscriptPath);

        (new CreateSermonTranscriptFromService($log))->handle(app(TranscriptStorageService::class));

        $log->refresh();
        $sermon->refresh();

        $this->assertSame('transcripts/sermon_'.$sermon->id.'.md', $log->transcript_file_path);
        $this->assertSame($log->transcript_file_path, $sermon->transcript_file_path);
        Storage::disk('local')->assertExists((string) $sermon->transcript_file_path);
        $this->assertSame(
            'Welcome. The sermon text. Closing prayer.',
            Storage::disk('local')->get((string) $sermon->transcript_file_path),
        );
    }
}
