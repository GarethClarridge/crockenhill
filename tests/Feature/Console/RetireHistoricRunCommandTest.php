<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Models\HistoricImportAlert;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RetireHistoricRunCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private HistoricImportOperation $operation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_quarantine');

        $this->operation = $this->createHistoricImportOperation();
    }

    /**
     * A completed historic run holding a quarantined sermon with its assets on
     * disk — the shape `2024-07-28-morning` actually has.
     *
     * @return array{0: MediaProcessingLog, 1: Sermon}
     */
    private function completedRunWithSermon(string $itemKey = '2024-07-28-morning'): array
    {
        $sermon = Sermon::factory()->create([
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'video_file_path' => 'sermons/890/video.mp4',
            'audio_file_path' => 'sermons/audio/run_sermon.mp3',
            'transcript_file_path' => 'transcripts/sermon_890.md',
            'thumbnail_file_path' => null,
        ]);

        Storage::disk('historic_quarantine')->put('sermons/890/video.mp4', 'video-bytes');
        Storage::disk('historic_quarantine')->put('sermons/audio/run_sermon.mp3', 'audio-bytes');
        Storage::disk('historic_quarantine')->put('transcripts/sermon_890.md', 'transcript-bytes');

        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'sermon_id' => $sermon->id,
            'extracted_date' => '2024-07-28',
            'extracted_service' => 'morning',
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $this->operation->operation_id,
                    'manifest_item_key' => $itemKey,
                ],
            ],
        ]);

        return [$run, $sermon];
    }

    /** A run held at manual review with no sermon — the shape `2023-07-16-morning` has. */
    private function heldRunWithoutSermon(string $itemKey = '2023-07-16-morning'): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'sermon_id' => null,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $this->operation->operation_id,
                    'manifest_item_key' => $itemKey,
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function applyArgs(MediaProcessingLog $run, string $note = 'Replaced by a longer source from the church PC.'): array
    {
        return [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => $note,
            '--apply' => true,
            '--yes' => true,
        ];
    }

    #[Test]
    public function it_writes_nothing_and_moves_no_file_without_apply(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();

        $this->artisan('historic-import:retire-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'A better source exists.',
        ])->expectsOutputToContain('DRY RUN')->assertSuccessful();

        $this->assertFalse($run->fresh()?->isRetired());
        $this->assertNotNull($sermon->fresh());
        Storage::disk('historic_quarantine')->assertExists('sermons/890/video.mp4');
        $this->assertSame(0, HistoricImportAlert::query()->count());
    }

    /**
     * The dry run's byte total is what an operator reads before approving a
     * retirement, so a unit error there misreports the blast radius.
     */
    #[Test]
    public function it_reports_the_asset_total_in_the_right_unit(): void
    {
        $sermon = Sermon::factory()->create([
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'video_file_path' => 'sermons/900/video.mp4',
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
        ]);

        // 2 MiB exactly, so the expected rendering is unambiguous.
        Storage::disk('historic_quarantine')->put('sermons/900/video.mp4', str_repeat('x', 2 * 1024 * 1024));

        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'sermon_id' => $sermon->id,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $this->operation->operation_id,
                    'manifest_item_key' => '2024-07-28-morning',
                ],
            ],
        ]);

        $this->artisan('historic-import:retire-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'Checking the reported total.',
        ])->expectsOutputToContain('2 MiB')->assertSuccessful();
    }

    #[Test]
    public function it_refuses_apply_without_confirmation(): void
    {
        [$run] = $this->completedRunWithSermon();

        $this->artisan('historic-import:retire-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'A better source exists.',
            '--apply' => true,
        ])->assertFailed();

        $this->assertFalse($run->fresh()?->isRetired());
    }

    #[Test]
    public function it_withdraws_the_sermon_and_relocates_its_assets(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();
        $prefix = 'superseded/'.$this->operation->operation_id.'/'.$sermon->id;

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertSuccessful();

        $this->assertNull(Sermon::query()->find($sermon->id));
        $this->assertTrue($run->fresh()?->isRetired());

        $disk = Storage::disk('historic_quarantine');
        $disk->assertMissing('sermons/890/video.mp4');
        $disk->assertMissing('sermons/audio/run_sermon.mp3');
        $disk->assertMissing('transcripts/sermon_890.md');
        $disk->assertExists($prefix.'/video-video.mp4');
        $disk->assertExists($prefix.'/audio-run_sermon.mp3');
        $disk->assertExists($prefix.'/transcript-sermon_890.md');

        // The bytes are preserved, not just the paths.
        $this->assertSame('video-bytes', $disk->get($prefix.'/video-video.mp4'));
    }

    #[Test]
    public function it_records_an_inventory_that_survives_the_deleted_sermon(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();
        $note = 'The church PC holds a longer recording of this service.';

        $this->artisan('historic-import:retire-run', $this->applyArgs($run, $note))->assertSuccessful();

        $record = $run->fresh()?->retirementRecord();

        $this->assertIsArray($record);
        $this->assertSame($note, $record['note'] ?? null);
        $this->assertSame('operator', $record['recorded_by'] ?? null);
        $this->assertSame('2024-07-28-morning', $record['manifest_item_key'] ?? null);
        $this->assertSame('completed', $record['status_when_retired'] ?? null);
        $this->assertSame($sermon->id, $record['sermon']['id'] ?? null);
        $this->assertSame($sermon->slug, $record['sermon']['slug'] ?? null);
        $this->assertCount(3, $record['assets'] ?? []);

        $video = collect($record['assets'])->firstWhere('role', 'video');
        $this->assertSame('sermons/890/video.mp4', $video['from'] ?? null);
        $this->assertSame(hash('sha256', 'video-bytes'), $video['sha256'] ?? null);

        // The disk explains itself too, for a restore of one store without the other.
        Storage::disk('historic_quarantine')
            ->assertExists('superseded/'.$this->operation->operation_id.'/'.$sermon->id.'/retirement.json');
    }

    #[Test]
    public function it_records_an_alert_so_the_pass_report_can_read_the_reason(): void
    {
        [$run] = $this->completedRunWithSermon();
        $note = 'Superseded by the church PC copy.';

        $this->artisan('historic-import:retire-run', $this->applyArgs($run, $note))->assertSuccessful();

        $alert = HistoricImportAlert::query()->sole();
        $this->assertSame('retired_superseded_source', $alert->kind);
        $this->assertSame($note, $alert->payload['facts']['reason'] ?? null);
    }

    #[Test]
    public function a_retired_run_stops_blocking_its_date(): void
    {
        [$run] = $this->completedRunWithSermon();

        $blocked = MediaProcessingLog::query()
            ->where('extracted_date', '2024-07-28')
            ->where('extracted_service', 'morning')
            ->notSuperseded()
            ->where('status', ProcessingStatus::Completed->value)
            ->exists();
        $this->assertTrue($blocked, 'The completed run should block its date before retirement.');

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertSuccessful();

        $stillBlocked = MediaProcessingLog::query()
            ->where('extracted_date', '2024-07-28')
            ->where('extracted_service', 'morning')
            ->notSuperseded()
            ->where('status', ProcessingStatus::Completed->value)
            ->exists();
        $this->assertFalse($stillBlocked, 'A retired run must not block its own re-import.');
    }

    #[Test]
    public function a_retired_run_reports_as_retired_rather_than_completed(): void
    {
        [$run] = $this->completedRunWithSermon();

        $status = app(HistoricVideoPassStatus::class);
        $this->assertSame('completed', $status->report($this->operation, ['2024-07-28-morning'])[0]['disposition']);

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertSuccessful();

        $this->assertSame('retired', $status->report($this->operation, ['2024-07-28-morning'])[0]['disposition']);
    }

    #[Test]
    public function a_reimport_after_retirement_reports_the_new_run_not_a_mixed_terminal(): void
    {
        [$run] = $this->completedRunWithSermon();

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertSuccessful();

        MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'extracted_date' => '2024-07-28',
            'extracted_service' => 'morning',
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $this->operation->operation_id,
                    'manifest_item_key' => '2024-07-28-morning',
                ],
            ],
        ]);

        $status = app(HistoricVideoPassStatus::class);
        $this->assertSame('completed', $status->report($this->operation, ['2024-07-28-morning'])[0]['disposition']);
    }

    #[Test]
    public function it_retires_a_run_that_never_produced_a_sermon(): void
    {
        $run = $this->heldRunWithoutSermon();

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))
            ->expectsOutputToContain('sermons withdrawn: 0')
            ->assertSuccessful();

        $this->assertTrue($run->fresh()?->isRetired());

        $status = app(HistoricVideoPassStatus::class);
        $this->assertSame('retired', $status->report($this->operation, ['2023-07-16-morning'])[0]['disposition']);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        [$run] = $this->completedRunWithSermon();

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertSuccessful();
        $this->artisan('historic-import:retire-run', $this->applyArgs($run))
            ->expectsOutputToContain('already retired: 1')
            ->assertSuccessful();

        $this->assertSame(1, HistoricImportAlert::query()->count());
    }

    #[Test]
    public function it_refuses_a_published_sermon(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();
        $sermon->forceFill(['publication_state' => SermonPublicationState::Published])->save();

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertFailed();

        $this->assertFalse($run->fresh()?->isRetired());
        $this->assertNotNull($sermon->fresh());
        Storage::disk('historic_quarantine')->assertExists('sermons/890/video.mp4');
    }

    #[Test]
    public function it_refuses_a_sermon_a_service_section_has_published(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'published_sermon_id' => $sermon->id,
            'published_at' => now(),
        ]);

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertFailed();

        $this->assertFalse($run->fresh()?->isRetired());
        $this->assertNotNull($sermon->fresh());
        Storage::disk('historic_quarantine')->assertExists('sermons/890/video.mp4');
    }

    #[Test]
    public function it_refuses_a_run_still_in_flight(): void
    {
        [$run] = $this->completedRunWithSermon();
        $run->forceFill(['status' => ProcessingStatus::Processing])->save();

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))
            ->expectsOutputToContain('still in flight')
            ->assertFailed();

        $this->assertFalse($run->fresh()?->isRetired());
    }

    #[Test]
    public function it_refuses_a_run_belonging_to_another_operation(): void
    {
        $other = $this->createHistoricImportOperation(str_repeat('d', 64));
        [$run] = $this->completedRunWithSermon();

        $this->artisan('historic-import:retire-run', [
            '--operation' => $other->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'Wrong operation.',
            '--apply' => true,
            '--yes' => true,
        ])->assertFailed();

        $this->assertFalse($run->fresh()?->isRetired());
    }

    #[Test]
    public function it_refuses_a_retirement_with_no_note(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();

        $this->artisan('historic-import:retire-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])->assertFailed();

        $this->assertFalse($run->fresh()?->isRetired());
        $this->assertNotNull($sermon->fresh());
        Storage::disk('historic_quarantine')->assertExists('sermons/890/video.mp4');
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_superseded_asset(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();
        $prefix = 'superseded/'.$this->operation->operation_id.'/'.$sermon->id;
        Storage::disk('historic_quarantine')->put($prefix.'/video-video.mp4', 'an-earlier-retirement');

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertFailed();

        // Nothing moved, nothing deleted: the sermon is exactly as it was.
        $this->assertNotNull($sermon->fresh());
        $this->assertFalse($run->fresh()?->isRetired());
        Storage::disk('historic_quarantine')->assertExists('sermons/890/video.mp4');
        Storage::disk('historic_quarantine')->assertExists('sermons/audio/run_sermon.mp3');
        $this->assertSame(
            'an-earlier-retirement',
            Storage::disk('historic_quarantine')->get($prefix.'/video-video.mp4'),
        );
    }

    #[Test]
    public function it_skips_an_asset_column_whose_file_is_already_gone(): void
    {
        [$run, $sermon] = $this->completedRunWithSermon();
        Storage::disk('historic_quarantine')->delete('sermons/audio/run_sermon.mp3');

        $this->artisan('historic-import:retire-run', $this->applyArgs($run))->assertSuccessful();

        $this->assertNull(Sermon::query()->find($sermon->id));
        $this->assertCount(2, $run->fresh()?->retirementRecord()['assets'] ?? []);
    }
}
