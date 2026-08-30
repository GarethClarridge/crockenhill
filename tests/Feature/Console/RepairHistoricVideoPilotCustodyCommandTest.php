<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\SermonSourceType;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RepairHistoricVideoPilotCustodyCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');

        config([
            'filesystems.disks.historic_staging.root' => Storage::disk('historic_staging')->path(''),
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
            'thumbnail-generation.storage.disk' => 'historic_staging',
        ]);
    }

    #[Test]
    public function it_previews_exact_completed_runs_without_writing_or_copying(): void
    {
        [$operation, $log, $sermon] = $this->historicPilotRun();

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain($log->processing_id)
            ->assertSuccessful();

        $sermon->refresh();
        self::assertSame(SermonPublicationState::Published, $sermon->publication_state);
        self::assertNull($sermon->asset_disk);
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $sermon->video_file_path));
        Storage::disk('historic_quarantine')->assertMissing($sermon->video_file_path);
    }

    #[Test]
    public function it_requires_an_explicit_confirmation_for_apply(): void
    {
        [$operation, $log, $sermon] = $this->historicPilotRun();

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('--apply requires --yes')
            ->assertFailed();

        $sermon->refresh();
        self::assertSame(SermonPublicationState::Published, $sermon->publication_state);
        self::assertNull($sermon->asset_disk);
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $sermon->video_file_path));
    }

    #[Test]
    public function it_quarantines_before_promoting_verified_assets_and_leaves_no_staging_copy(): void
    {
        [$operation, $log, $sermon] = $this->historicPilotRun();

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Promoted')
            ->assertSuccessful();

        $sermon->refresh();
        self::assertSame(SermonPublicationState::Quarantined, $sermon->publication_state);
        self::assertSame('historic_quarantine', $sermon->asset_disk);
        self::assertSame($operation->id, $sermon->historic_import_operation_id);
        Storage::disk('historic_staging')->assertMissing($this->stagedPath($log, $sermon->video_file_path));
        Storage::disk('historic_quarantine')->assertExists($sermon->video_file_path);
        self::assertSame('pilot video', Storage::disk('historic_quarantine')->get($sermon->video_file_path));
    }

    #[Test]
    public function it_is_an_exact_no_op_when_the_same_run_is_already_owned_by_quarantine(): void
    {
        [$operation, $log, $sermon] = $this->historicPilotRun();

        $arguments = [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ];

        $this->artisan('historic-import:repair-video-pilot-custody', $arguments)->assertSuccessful();

        $before = $sermon->fresh()->updated_at;
        $this->artisan('historic-import:repair-video-pilot-custody', $arguments)
            ->expectsOutputToContain('already repaired')
            ->assertSuccessful();

        $sermon->refresh();
        self::assertSame(SermonPublicationState::Quarantined, $sermon->publication_state);
        self::assertSame('historic_quarantine', $sermon->asset_disk);
        self::assertSame($before?->toISOString(), $sermon->updated_at?->toISOString());
        Storage::disk('historic_quarantine')->assertExists($sermon->video_file_path);
    }

    #[Test]
    public function it_rejects_foreign_or_curated_ownership_mismatches_before_any_write(): void
    {
        $operation = $this->createHistoricImportOperation();
        $foreignOperation = $this->createHistoricImportOperation();
        [, $foreignLog, $foreignSermon] = $this->historicPilotRun($foreignOperation);
        [, $curatedLog, $curatedSermon] = $this->historicPilotRun($operation, [
            'source_type' => SermonSourceType::Manual,
        ]);

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$foreignLog->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('does not belong to the named historic operation')
            ->assertFailed();

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$curatedLog->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('curated or manually owned')
            ->assertFailed();

        $foreignSermon->refresh();
        $curatedSermon->refresh();
        self::assertSame(SermonPublicationState::Published, $foreignSermon->publication_state);
        self::assertSame(SermonPublicationState::Published, $curatedSermon->publication_state);
        self::assertNull($foreignSermon->asset_disk);
        self::assertNull($curatedSermon->asset_disk);
    }

    #[Test]
    public function it_rejects_non_completed_runs_and_missing_staged_assets(): void
    {
        [$operation, $log, $sermon] = $this->historicPilotRun();
        $log->forceFill(['status' => ProcessingStatus::Processing])->save();

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('must be completed')
            ->assertFailed();

        $log->forceFill(['status' => ProcessingStatus::Completed])->save();
        Storage::disk('historic_staging')->delete($this->stagedPath($log, $sermon->video_file_path));

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('missing from historic staging')
            ->assertFailed();

        $sermon->refresh();
        self::assertSame(SermonPublicationState::Published, $sermon->publication_state);
        self::assertNull($sermon->asset_disk);
    }

    #[Test]
    public function it_never_overwrites_a_foreign_quarantine_object_and_remains_fail_closed(): void
    {
        [$operation, $log, $sermon] = $this->historicPilotRun();
        Storage::disk('historic_quarantine')->put($sermon->video_file_path, 'foreign bytes');

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('differs')
            ->assertFailed();

        $sermon->refresh();
        self::assertSame(SermonPublicationState::Quarantined, $sermon->publication_state);
        self::assertNull($sermon->asset_disk);
        self::assertSame('foreign bytes', Storage::disk('historic_quarantine')->get($sermon->video_file_path));
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $sermon->video_file_path));
    }

    #[Test]
    public function it_rejects_a_staging_context_not_bound_to_the_named_operation(): void
    {
        [$operation, $log, $sermon] = $this->historicPilotRun();
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $metadata['historic_import']['staging_context']['plan_hash'] = str_repeat('f', 64);
        $log->forceFill(['processing_metadata' => $metadata])->save();

        $this->artisan('historic-import:repair-video-pilot-custody', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('does not match the named operation manifest and plan')
            ->assertFailed();

        $sermon->refresh();
        self::assertSame(SermonPublicationState::Published, $sermon->publication_state);
        self::assertNull($sermon->asset_disk);
        Storage::disk('historic_staging')->assertExists($this->stagedPath($log, $sermon->video_file_path));
    }

    /**
     * @param  array<string, mixed>  $sermonAttributes
     * @return array{0: HistoricImportOperation, 1: MediaProcessingLog, 2: Sermon}
     */
    private function historicPilotRun(
        ?HistoricImportOperation $operation = null,
        array $sermonAttributes = [],
    ): array {
        $operation ??= $this->createHistoricImportOperation();
        $processingId = (string) Str::uuid();
        $videoPath = 'sermons/'.random_int(100, 999).'/video.mp4';
        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            $operation->manifest_hashes['video'],
            $operation->plan_hash,
        );

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId,
            'historic_import_operation_id' => $operation->id,
            'sermon_id' => null,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'historic-video-job-'.Str::random(8),
                    'manifest_item_key' => '2020-01-01-morning-'.Str::random(4),
                    'operation_id' => $operation->operation_id,
                    'staging_context' => $stagingContext->toArray(),
                ],
            ],
        ]);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
            'historic_import_operation_id' => null,
            'livestream_processing_id' => $processingId,
            'video_file_path' => $videoPath,
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
            'thumbnail_metadata' => null,
            ...$sermonAttributes,
        ]);
        $log->forceFill(['sermon_id' => $sermon->id])->save();

        Storage::disk('historic_staging')->put("{$stagingContext->batchRoot}/{$videoPath}", 'pilot video');

        return [$operation, $log, $sermon];
    }

    private function stagedPath(MediaProcessingLog $log, string $path): string
    {
        $context = $log->historicStagingContext();

        self::assertNotNull($context);

        return "{$context->batchRoot}/{$path}";
    }
}
