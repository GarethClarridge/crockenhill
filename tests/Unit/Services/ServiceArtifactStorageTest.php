<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceArtifactStorageTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArtifactStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
        ]);

        $this->storage = app(ServiceArtifactStorage::class);
    }

    #[Test]
    public function it_keys_artifacts_by_service_date_and_processing_id(): void
    {
        $log = $this->log();

        $path = $this->storage->putJson($log->processing_id, 'normalized', ['cues' => []]);

        $this->assertSame(
            "service-transcripts/2026-03-22/morning-{$log->processing_id}.normalized.json",
            $path,
        );
        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function artifacts_are_private_even_on_a_publicly_visible_disk(): void
    {
        $log = $this->log();
        $this->assertSame('public', config('filesystems.disks.public.visibility'));

        $path = $this->storage->putJson($log->processing_id, 'normalized', ['cues' => []]);

        $this->assertSame('private', Storage::disk('public')->getVisibility($path));
    }

    #[Test]
    public function it_records_every_artifact_with_its_disk(): void
    {
        $log = $this->log();
        Storage::disk('local')->put('temp/rms.log', 'lavfi.astats.Overall.RMS_level=-20.0');
        $audioPath = tempnam(sys_get_temp_dir(), 'service-audio-');
        file_put_contents($audioPath, 'compressed audio');

        $this->storage->putJson($log->processing_id, 'raw', ['segments' => []]);
        $this->storage->archiveRms($log->processing_id, 'temp/rms.log');
        $this->storage->archiveAudio($log->processing_id, $audioPath);

        unlink($audioPath);

        $artifacts = collect(ServiceArtifactStorage::recordedFor($log->refresh()));

        $this->assertEqualsCanonicalizing(
            ['raw', 'rms', 'audio'],
            $artifacts->pluck('kind')->all(),
        );

        foreach ($artifacts as $artifact) {
            Storage::disk($artifact['disk'])->assertExists($artifact['path']);
        }

        $this->assertSame(
            'service-audio/2026-03-22/morning-'.$log->processing_id.'.mp3',
            $artifacts->firstWhere('kind', 'audio')['path'],
        );
    }

    #[Test]
    public function rerunning_the_same_model_rewrites_the_artifact_in_place(): void
    {
        $log = $this->log();

        $first = $this->storage->putJson($log->processing_id, 'raw', ['take' => 1], ['model' => 'small']);
        $second = $this->storage->putJson($log->processing_id, 'raw', ['take' => 2], ['model' => 'small']);

        $this->assertSame($first, $second);
        $this->assertCount(1, ServiceArtifactStorage::recordedFor($log->refresh()));

        $stored = json_decode((string) Storage::disk('public')->get($second), true);
        $this->assertSame(2, $stored['take']);
    }

    #[Test]
    public function rerunning_a_different_model_preserves_the_earlier_payload(): void
    {
        $log = $this->log();

        $small = $this->storage->putJson($log->processing_id, 'raw', ['model' => 'small'], ['model' => 'small']);
        $large = $this->storage->putJson($log->processing_id, 'raw', ['model' => 'large-v3'], ['model' => 'large-v3']);

        $this->assertNotSame($small, $large);
        Storage::disk('public')->assertExists($small);
        Storage::disk('public')->assertExists($large);

        $this->assertSame(
            'small',
            json_decode((string) Storage::disk('public')->get($small), true)['model'],
            'A better model must never silently destroy what the previous one returned.',
        );

        $this->assertCount(2, ServiceArtifactStorage::recordedFor($log->refresh()));
    }

    /**
     * Artifacts are small, read by every re-derivation and costly to remake, so they get a
     * disk of their own rather than following the (large, possibly external) media disk.
     */
    #[Test]
    public function every_artifact_goes_to_the_service_artifact_disk_when_one_is_configured(): void
    {
        Storage::fake('service_artifacts');
        config(['media-processing.storage.service_artifact_disk' => 'service_artifacts']);
        $log = $this->log();
        Storage::disk('local')->put('temp/rms.log', 'lavfi.astats.Overall.RMS_level=-20.0');
        $audioPath = tempnam(sys_get_temp_dir(), 'service-audio-');
        file_put_contents($audioPath, 'compressed audio');

        $jsonPath = $this->storage->putJson($log->processing_id, 'raw', ['segments' => []]);
        $rmsPath = $this->storage->archiveRms($log->processing_id, 'temp/rms.log');
        $this->storage->archiveAudio($log->processing_id, $audioPath);

        unlink($audioPath);

        $audio = $this->storage->audioLocation($log->processing_id);
        $this->assertSame('service_artifacts', $audio['disk']);

        foreach ([$jsonPath, $rmsPath, $audio['path']] as $path) {
            Storage::disk('service_artifacts')->assertExists($path);
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertSame(
            ['service_artifacts'],
            collect(ServiceArtifactStorage::recordedFor($log->refresh()))->pluck('disk')->unique()->values()->all(),
        );
        $this->assertSame('service_artifacts', ServiceArtifactDisk::for($jsonPath));
    }

    #[Test]
    public function without_a_service_artifact_disk_artifacts_follow_the_transcript_disk(): void
    {
        config([
            'media-processing.storage.service_artifact_disk' => null,
            'media-processing.storage.transcript_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
        ]);
        $log = $this->log();

        $jsonPath = $this->storage->putJson($log->processing_id, 'raw', ['segments' => []]);

        Storage::disk('local')->assertExists($jsonPath);
        $this->assertSame('local', $this->storage->audioLocation($log->processing_id)['disk']);
        $this->assertSame('local', ServiceArtifactDisk::for($jsonPath));
    }

    private function log(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-03-22',
            'extracted_service' => 'morning',
        ]);
    }
}
