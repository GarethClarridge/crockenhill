<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceTranscriptReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ServiceTranscriptReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('transcripts');
        Storage::fake('temp');
        Config::set('media-processing.storage.transcript_disk', 'transcripts');
        Config::set('media-processing.storage.temp_disk', 'temp');
    }

    #[Test]
    public function it_reads_a_service_transcripts_key_from_the_transcript_disk(): void
    {
        $log = $this->logWithTranscriptAt('service-transcripts/2024-10-06/morning.json', 'transcripts');

        self::assertSame('Some speech.', app(ServiceTranscriptReader::class)->read($log)->sliceText(0.0, 10.0));
    }

    #[Test]
    public function it_reads_any_other_key_from_the_temporary_disk(): void
    {
        $log = $this->logWithTranscriptAt('temp/service_transcript.json', 'temp');

        self::assertSame('Some speech.', app(ServiceTranscriptReader::class)->read($log)->sliceText(0.0, 10.0));
    }

    #[Test]
    public function it_fails_when_no_transcript_is_recorded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No full-service transcript recorded for this run.');

        app(ServiceTranscriptReader::class)->read(MediaProcessingLog::factory()->livestream()->completed()->create());
    }

    #[Test]
    public function it_fails_when_the_recorded_transcript_is_gone(): void
    {
        $log = $this->logWithTranscriptAt('service-transcripts/2024-10-06/morning.json', 'transcripts');
        Storage::disk('transcripts')->delete('service-transcripts/2024-10-06/morning.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recorded full-service transcript is unavailable.');

        app(ServiceTranscriptReader::class)->read($log);
    }

    #[Test]
    public function try_read_returns_null_instead_of_throwing(): void
    {
        $log = $this->logWithTranscriptAt('service-transcripts/2024-10-06/morning.json', 'transcripts');
        Storage::disk('transcripts')->delete('service-transcripts/2024-10-06/morning.json');

        self::assertNull(app(ServiceTranscriptReader::class)->tryRead($log));
    }

    private function logWithTranscriptAt(string $path, string $disk): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();

        Storage::disk($disk)->put($path, json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 10.0, 'text' => 'Some speech.'],
        ], 10.0, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($path);

        return $log->fresh();
    }
}
