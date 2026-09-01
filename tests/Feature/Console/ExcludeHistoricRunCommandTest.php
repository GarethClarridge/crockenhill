<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Models\HistoricImportAlert;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class ExcludeHistoricRunCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private HistoricImportOperation $operation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operation = $this->createHistoricImportOperation();
    }

    private function heldRun(string $itemKey = '2023-07-16-morning'): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $this->operation->id,
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $this->operation->operation_id,
                    'manifest_item_key' => $itemKey,
                ],
            ],
        ]);
    }

    #[Test]
    public function it_writes_nothing_without_apply(): void
    {
        $run = $this->heldRun();

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'The recording holds the children\'s talk only.',
        ])->expectsOutputToContain('DRY RUN')->assertSuccessful();

        $this->assertFalse($run->fresh()?->isExcluded());
        $this->assertSame(0, HistoricImportAlert::query()->count());
    }

    #[Test]
    public function it_refuses_apply_without_confirmation(): void
    {
        $run = $this->heldRun();

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'No sermon here.',
            '--apply' => true,
        ])->assertFailed();

        $this->assertFalse($run->fresh()?->isExcluded());
    }

    #[Test]
    public function it_records_the_exclusion_with_its_note_and_an_alert(): void
    {
        $run = $this->heldRun();
        $note = 'The recording is the children\'s talk in full; the sermon is in no held recording.';

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => $note,
            '--apply' => true,
            '--yes' => true,
        ])->assertSuccessful();

        $fresh = $run->fresh();

        $this->assertTrue($fresh?->isExcluded());
        $this->assertSame(MediaProcessingLog::EXCLUSION_REASON_NO_SERMON_IN_SOURCE, $fresh?->exclusionReason());
        $this->assertFalse($fresh?->isExcludedSilentAudio());
        $this->assertSame($note, $fresh?->exclusionEvidence()['note'] ?? null);

        // The run keeps its own history; only the disposition changes.
        $this->assertSame(ProcessingStatus::Failed, $fresh?->status);
        $this->assertSame('manual_review_required', $fresh?->current_step);
        $this->assertSame('failed', $fresh?->exclusionEvidence()['status_when_excluded'] ?? null);

        $alert = HistoricImportAlert::query()->sole();
        $this->assertSame('excluded_no_sermon_in_source', $alert->kind);
        $this->assertSame($note, $alert->payload['facts']['reason'] ?? null);
    }

    #[Test]
    public function an_excluded_run_reports_as_excluded_rather_than_awaiting_review(): void
    {
        $run = $this->heldRun();

        $status = app(HistoricVideoPassStatus::class);
        $before = $status->report($this->operation, ['2023-07-16-morning']);
        $this->assertSame('manual_review', $before[0]['disposition']);

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'No sermon in the source.',
            '--apply' => true,
            '--yes' => true,
        ])->assertSuccessful();

        $after = $status->report($this->operation, ['2023-07-16-morning']);
        $this->assertSame('excluded', $after[0]['disposition']);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $run = $this->heldRun();
        $args = [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'No sermon in the source.',
            '--apply' => true,
            '--yes' => true,
        ];

        $this->artisan('historic-import:exclude-run', $args)->assertSuccessful();
        $this->artisan('historic-import:exclude-run', $args)
            ->expectsOutputToContain('already excluded: 1')
            ->assertSuccessful();

        $this->assertSame(1, HistoricImportAlert::query()->count());
    }

    #[Test]
    public function it_refuses_a_reason_the_pipeline_owns(): void
    {
        $run = $this->heldRun();

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--reason' => MediaProcessingLog::EXCLUSION_REASON_SOURCE_AUDIO_SILENT,
            '--note' => 'Trying to hand-write a detection the pipeline owns.',
            '--apply' => true,
            '--yes' => true,
        ])->assertFailed();

        $this->assertFalse($run->fresh()?->isExcluded());
    }

    #[Test]
    public function it_refuses_a_run_belonging_to_another_operation(): void
    {
        $other = $this->createHistoricImportOperation(str_repeat('d', 64));
        $run = $this->heldRun();

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $other->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'Wrong operation.',
            '--apply' => true,
            '--yes' => true,
        ])->assertFailed();

        $this->assertFalse($run->fresh()?->isExcluded());
    }

    #[Test]
    public function it_refuses_an_exclusion_with_no_note(): void
    {
        $run = $this->heldRun();

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])->assertFailed();

        $this->assertFalse($run->fresh()?->isExcluded());
    }

    #[Test]
    public function it_will_not_overwrite_a_silent_source_exclusion(): void
    {
        $run = $this->heldRun();
        $run->putSilentAudioExclusion(['frame_count' => 22857]);

        $this->artisan('historic-import:exclude-run', [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => [$run->processing_id],
            '--note' => 'Trying to relabel a silent source.',
            '--apply' => true,
            '--yes' => true,
        ])->assertFailed();

        $this->assertTrue($run->fresh()?->isExcludedSilentAudio());
    }
}
