<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRunner;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class MoveServiceArtifactsCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('service_artifacts');
        Storage::fake('local');

        config([
            'filesystems.disks.historic_staging.root' => Storage::disk('historic_staging')->path(''),
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.service_artifact_disk' => 'service_artifacts',
        ]);
    }

    #[Test]
    public function the_dry_run_reports_what_would_move_and_writes_nothing(): void
    {
        $run = $this->routineRun();

        $this->artisan('media:move-service-artifacts')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('3 to copy')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('service_artifacts')->allFiles());
        $this->assertSame(['historic_staging'], $this->recordedDisks($run));
    }

    #[Test]
    public function it_copies_verifies_and_repoints_every_artifact_and_keeps_the_source(): void
    {
        $run = $this->routineRun();

        $this->artisan('media:move-service-artifacts', ['--apply' => true])
            ->expectsOutputToContain('3 copied')
            ->assertSuccessful();

        foreach (['service-transcripts/2026-03-22/morning-x.normalized.json', 'service-transcripts/2026-03-22/morning-x.rms.json', 'service-audio/2026-03-22/morning-x.mp3'] as $path) {
            $this->assertSame(
                Storage::disk('historic_staging')->get($path),
                Storage::disk('service_artifacts')->get($path),
            );
        }

        $this->assertSame(['service_artifacts'], $this->recordedDisks($run));
    }

    #[Test]
    public function a_moved_ensemble_bundle_still_replays_from_its_new_disk(): void
    {
        config([
            'media-processing.storage.service_artifact_disk' => 'historic_staging',
            'media-processing.service_structure.detector' => 'mock',
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 100.0, 'text' => 'Welcome and Bible reading.'],
            ['start' => 100.0, 'end' => 500.0, 'text' => 'Turn with me to John.'],
        ], 500.0, ChurchServiceTranscript::SOURCE_MOCK);
        $banked = app(ServiceStructureEnsembleRunner::class)->run($run, [
            'processing_id' => $run->processing_id,
            'transcript' => $transcript->toArray(),
            'audio_timeline' => AudioTimelineFixture::payload([], 500.0),
            'oos_items' => [],
            'validation_context' => ServiceStructureDrawExecutor::contextSnapshot(ValidationContext::for($transcript)),
            'rms_log' => null,
        ]);
        $before = app(ServiceStructureEnsembleReplay::class)->replay($banked['evidence']);

        config(['media-processing.storage.service_artifact_disk' => 'service_artifacts']);
        $this->artisan('media:move-service-artifacts', ['--apply' => true])->assertSuccessful();

        $evidence = $run->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? [];
        $after = app(ServiceStructureEnsembleReplay::class)->replay($evidence);

        $this->assertSame('service_artifacts', $evidence['artifact_disk']);
        $this->assertSame('historic_staging', $evidence['origin_disk']);
        $this->assertSame($before['structure'], $after['structure']);
        $this->assertSame($before['disputes'], $after['disputes']);
    }

    #[Test]
    public function a_partly_moved_ensemble_bundle_keeps_its_old_disk(): void
    {
        config([
            'media-processing.storage.service_artifact_disk' => 'historic_staging',
            'media-processing.service_structure.detector' => 'mock',
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 500.0, 'text' => 'Turn with me to John.'],
        ], 500.0, ChurchServiceTranscript::SOURCE_MOCK);
        $banked = app(ServiceStructureEnsembleRunner::class)->run($run, [
            'processing_id' => $run->processing_id,
            'transcript' => $transcript->toArray(),
            'audio_timeline' => AudioTimelineFixture::payload([], 500.0),
            'oos_items' => [],
            'validation_context' => ServiceStructureDrawExecutor::contextSnapshot(ValidationContext::for($transcript)),
            'rms_log' => null,
        ]);
        Storage::disk('historic_staging')->delete($banked['evidence']['slots'][2]['path']);

        config(['media-processing.storage.service_artifact_disk' => 'service_artifacts']);
        $this->artisan('media:move-service-artifacts', ['--apply' => true])->assertFailed();

        $evidence = $run->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? [];
        $this->assertSame('historic_staging', $evidence['artifact_disk']);
        $this->assertArrayNotHasKey('origin_disk', $evidence);
    }

    /**
     * A historic run wrote its artifacts inside its staging context, under the batch root; the
     * copy must read exactly the file the run reads, not a same-named file at the disk's top.
     */
    #[Test]
    public function a_historic_run_is_read_inside_its_own_staging_context(): void
    {
        $operation = $this->createHistoricImportOperation();
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            $operation->manifest_hashes['video'],
            $operation->plan_hash,
        );
        $path = 'service-transcripts/2021-09-05/evening-y.normalized.json';
        Storage::disk('historic_staging')->put($path, 'stale top-level copy');
        Storage::disk('historic_staging')->put($context->batchRoot.'/'.$path, 'batch copy');
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => [
                'historic_import' => ['staging_context' => $context->toArray()],
                'service_transcript_path' => $path,
                ServiceArtifactStorage::METADATA_KEY => [['kind' => 'normalized', 'disk' => 'historic_staging', 'path' => $path]],
            ],
        ]);

        $this->artisan('media:move-service-artifacts', ['--apply' => true])->assertSuccessful();

        $this->assertSame('batch copy', Storage::disk('service_artifacts')->get($path));
        $this->assertSame(['service_artifacts'], $this->recordedDisks($run));
    }

    #[Test]
    public function a_differing_file_already_at_the_destination_is_never_overwritten(): void
    {
        $run = $this->routineRun();
        Storage::disk('service_artifacts')->put('service-audio/2026-03-22/morning-x.mp3', 'something else');

        $this->artisan('media:move-service-artifacts', ['--apply' => true])
            ->expectsOutputToContain('differs from the source')
            ->assertFailed();

        $this->assertSame('something else', Storage::disk('service_artifacts')->get('service-audio/2026-03-22/morning-x.mp3'));
        $this->assertSame(
            ['historic_staging', 'service_artifacts'],
            $this->recordedDisks($run),
        );
    }

    #[Test]
    public function a_missing_source_is_reported_and_its_record_left_alone(): void
    {
        $run = $this->routineRun();
        Storage::disk('historic_staging')->delete('service-audio/2026-03-22/morning-x.mp3');

        $this->artisan('media:move-service-artifacts', ['--apply' => true])
            ->expectsOutputToContain('source missing')
            ->assertFailed();

        $entries = collect(ServiceArtifactStorage::recordedFor($run->refresh()));
        $this->assertSame('historic_staging', $entries->firstWhere('kind', 'audio')['disk']);
        $this->assertSame('service_artifacts', $entries->firstWhere('kind', 'rms')['disk']);
    }

    #[Test]
    public function a_second_run_finds_nothing_left_to_move(): void
    {
        $this->routineRun();

        $this->artisan('media:move-service-artifacts', ['--apply' => true])->assertSuccessful();

        $this->artisan('media:move-service-artifacts', ['--apply' => true])
            ->expectsOutputToContain('0 copied')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_without_a_service_artifact_disk(): void
    {
        config(['media-processing.storage.service_artifact_disk' => null]);

        $this->artisan('media:move-service-artifacts', ['--apply' => true])
            ->expectsOutputToContain('SERVICE_ARTIFACT_DISK')
            ->assertFailed();
    }

    /**
     * A routine run: artifacts at the staging disk's top level, the transcript path recorded
     * only as a path field and the RMS and audio recorded as artifacts.
     */
    private function routineRun(): MediaProcessingLog
    {
        $files = [
            'service-transcripts/2026-03-22/morning-x.normalized.json' => '{"cues":[]}',
            'service-transcripts/2026-03-22/morning-x.rms.json' => '{"rms":[]}',
            'service-audio/2026-03-22/morning-x.mp3' => 'service audio',
        ];

        foreach ($files as $path => $contents) {
            Storage::disk('historic_staging')->put($path, $contents);
        }

        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => (string) Str::uuid(),
            'rms_log_path' => 'service-transcripts/2026-03-22/morning-x.rms.json',
            'processing_metadata' => [
                'service_transcript_path' => 'service-transcripts/2026-03-22/morning-x.normalized.json',
                ServiceArtifactStorage::METADATA_KEY => [
                    ['kind' => 'rms', 'disk' => 'historic_staging', 'path' => 'service-transcripts/2026-03-22/morning-x.rms.json'],
                    ['kind' => 'audio', 'disk' => 'historic_staging', 'path' => 'service-audio/2026-03-22/morning-x.mp3'],
                ],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function recordedDisks(MediaProcessingLog $run): array
    {
        return collect(ServiceArtifactStorage::recordedFor($run->refresh()))
            ->pluck('disk')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
