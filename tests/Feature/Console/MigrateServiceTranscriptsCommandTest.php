<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MigrateServiceTranscriptsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'public',
        ]);
    }

    #[Test]
    public function it_reports_without_writing_by_default(): void
    {
        $log = $this->legacyRun();

        $this->artisan('media:migrate-service-transcripts')
            ->assertSuccessful()
            ->expectsOutputToContain('Would migrate');

        $this->assertSame(
            'temp/service_transcript_'.$log->processing_id.'.json',
            $log->fresh()->serviceTranscriptPath(),
        );
    }

    #[Test]
    public function it_copies_a_legacy_transcript_onto_the_transcript_disk_with_apply(): void
    {
        $log = $this->legacyRun();

        $this->artisan('media:migrate-service-transcripts', ['--apply' => true])
            ->assertSuccessful();

        $newPath = $log->fresh()->serviceTranscriptPath();

        $this->assertTrue(ServiceArtifactDisk::isDurable((string) $newPath));
        Storage::disk('public')->assertExists((string) $newPath);
        $this->assertTrue($log->fresh()->hasStoredServiceTranscript());

        $stored = json_decode((string) Storage::disk('public')->get((string) $newPath), true);
        $this->assertSame('Welcome to the service', $stored['cues'][0]['text']);
    }

    #[Test]
    public function it_leaves_an_already_durable_transcript_alone(): void
    {
        $durablePath = 'service-transcripts/2026-03-22/morning-abc.normalized.json';
        Storage::disk('public')->put($durablePath, json_encode(['cues' => []]));

        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $log->putServiceTranscriptPath($durablePath);

        $this->artisan('media:migrate-service-transcripts', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame($durablePath, $log->fresh()->serviceTranscriptPath());
    }

    #[Test]
    public function it_warns_when_the_legacy_file_has_already_been_swept(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $log->putServiceTranscriptPath('temp/service_transcript_gone.json');

        $this->artisan('media:migrate-service-transcripts', ['--apply' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Already swept');
    }

    private function legacyRun(): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-03-22',
        ]);

        $legacyPath = 'temp/service_transcript_'.$log->processing_id.'.json';

        Storage::disk('local')->put($legacyPath, json_encode([
            'cues' => [['start' => 0.0, 'end' => 4.0, 'text' => 'Welcome to the service']],
        ], JSON_THROW_ON_ERROR));

        $log->putServiceTranscriptPath($legacyPath);

        return $log;
    }
}
