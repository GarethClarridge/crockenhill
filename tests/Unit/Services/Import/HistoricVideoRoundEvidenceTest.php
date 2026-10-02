<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Enums\HistoricImportOperationState;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\HistoricImportNestedJob;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Import\HistoricVideoRoundCloseout;
use App\Services\Import\HistoricVideoRoundEvidence;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricVideoRoundEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function the_round_closeout_state_is_a_terminal_work_barrier_before_completion(): void
    {
        $this->assertTrue(HistoricImportOperationState::Planned->canTransitionTo(
            HistoricImportOperationState::RoundCloseoutRequired,
        ));
        $this->assertTrue(HistoricImportOperationState::RoundCloseoutRequired->canTransitionTo(
            HistoricImportOperationState::Complete,
        ));
        $this->assertFalse(HistoricImportOperationState::RoundCloseoutRequired->canTransitionTo(
            HistoricImportOperationState::Running,
        ));
    }

    #[Test]
    public function it_verifies_a_signed_operation_bound_cover_and_every_report_digest(): void
    {
        config(['media-processing.historic_import.evidence_signing_key' => 'round-key']);
        $operation = $this->operation();
        $cover = $this->cover($operation);

        $verified = app(HistoricVideoRoundEvidence::class)->verify($cover, $operation);

        $this->assertSame('round-4', $verified['round']);
        $this->assertCount(7, $verified['reports']);
    }

    #[Test]
    public function it_refuses_a_report_that_changed_after_review(): void
    {
        config(['media-processing.historic_import.evidence_signing_key' => 'round-key']);
        $operation = $this->operation();
        $cover = $this->cover($operation);
        file_put_contents($cover['reports']['video_status']['path'], 'changed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('video_status report digest does not match');

        app(HistoricVideoRoundEvidence::class)->verify($cover, $operation);
    }

    #[Test]
    public function it_refuses_cover_membership_that_does_not_exactly_match_the_manifest_expectation(): void
    {
        config(['media-processing.historic_import.evidence_signing_key' => 'round-key']);
        $operation = $this->operation();
        $cover = $this->cover($operation);
        array_pop($cover['items']);
        $cover['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($cover, ['signature' => true])),
            'round-key',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exactly match');

        app(HistoricVideoRoundEvidence::class)->verify($cover, $operation);
    }

    #[Test]
    public function it_atomically_completes_the_legacy_operation_and_is_idempotent_for_the_same_cover(): void
    {
        config(['media-processing.historic_import.evidence_signing_key' => 'round-key']);
        $operation = $this->persistedOperation();
        $cover = $this->cover($operation);

        $completed = app(HistoricVideoRoundCloseout::class)->complete($operation, $cover);
        $repeated = app(HistoricVideoRoundCloseout::class)->complete($completed, $cover);

        $this->assertSame(HistoricImportOperationState::Complete, $completed->state);
        $this->assertSame(HistoricImportOperationState::Complete, $repeated->state);
        $this->assertDatabaseCount('historic_import_journal_entries', 2);
        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'video_round_closeout_complete',
        ]);
    }

    #[Test]
    public function it_refuses_digest_drift_after_completion(): void
    {
        config(['media-processing.historic_import.evidence_signing_key' => 'round-key']);
        $operation = $this->persistedOperation();
        $cover = $this->cover($operation);
        app(HistoricVideoRoundCloseout::class)->complete($operation, $cover);
        $cover['reviewed_at'] = '2026-09-19T13:00:00Z';
        $cover['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($cover, ['signature' => true])),
            'round-key',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('digest has drifted');

        app(HistoricVideoRoundCloseout::class)->complete($operation->fresh(), $cover);
    }

    #[Test]
    public function it_refuses_a_settled_nested_failure_without_durable_containment(): void
    {
        config(['media-processing.historic_import.evidence_signing_key' => 'round-key']);
        $operation = $this->persistedOperation();
        $cover = $this->coverWithSettledNestedFailure($operation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('neither superseded nor durably contained');

        app(HistoricVideoRoundEvidence::class)->verify($cover, $operation);
    }

    #[Test]
    public function it_accepts_a_settled_nested_failure_that_is_explicitly_retained_as_a_contained_residue(): void
    {
        config(['media-processing.historic_import.evidence_signing_key' => 'round-key']);
        $operation = $this->persistedOperation();
        $cover = $this->coverWithSettledNestedFailure($operation);
        $run = MediaProcessingLog::query()->where('processing_id', 'failed-then-completed')->sole();
        Storage::fake('historic_quarantine');
        Storage::disk('historic_quarantine')->put('section-publications/1/video.mp4', 'video');
        ServiceSection::factory()->create([
            'id' => 1,
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
            'asset_disk' => 'historic_quarantine',
            'extracted_video_path' => 'section-publications/1/video.mp4',
        ]);
        $nestedJob = HistoricImportNestedJob::query()->where('job_key', 'auto-publish-section-1')->sole();

        $this->assertSame($run->id, $nestedJob->processingLog?->id);
        $this->assertSame(ProcessingStatus::Completed, $run->status);
        $this->assertTrue($run->completed_at?->gte($nestedJob->settled_at) ?? false);

        $verified = app(HistoricVideoRoundEvidence::class)->verify($cover, $operation);

        $this->assertSame('failed-then-completed', data_get($verified, 'reports.operation_ledger.path') !== null
            ? $run->processing_id
            : null);
    }

    private function operation(): HistoricImportOperation
    {
        return new HistoricImportOperation([
            'operation_id' => 'historic-operation-4',
            'binding_hash' => str_repeat('a', 64),
            'batch_key' => 'historic-video-full-corpus',
            'manifest_hashes' => ['historic_video' => str_repeat('b', 64)],
            'plan_hash' => str_repeat('c', 64),
            'target_fingerprint' => str_repeat('d', 64),
            'runtime_fingerprint' => str_repeat('e', 64),
        ]);
    }

    private function persistedOperation(): HistoricImportOperation
    {
        $operation = $this->operation();

        return HistoricImportOperation::query()->create([
            'operation_id' => $operation->operation_id,
            'binding_hash' => $operation->binding_hash,
            'batch_key' => $operation->batch_key,
            'manifest_hashes' => $operation->manifest_hashes,
            'plan_hash' => $operation->plan_hash,
            'target_fingerprint' => $operation->target_fingerprint,
            'runtime_fingerprint' => $operation->runtime_fingerprint,
            'notification_mode' => 'suppress_external',
            'max_cost_minor_units' => 100,
            'state' => HistoricImportOperationState::Planned,
            'accepted_deadline' => now()->addDay(),
        ]);
    }

    /** @return array<string, mixed> */
    private function cover(HistoricImportOperation $operation): array
    {
        $reports = [];

        foreach (HistoricVideoRoundEvidence::ReportKeys as $key) {
            $path = tempnam(sys_get_temp_dir(), 'historic-video-round-');
            self::assertIsString($path);
            file_put_contents($path, $key);
            $this->paths[] = $path;
            $reports[$key] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        }

        file_put_contents($reports['manifest_expectation']['path'], json_encode([
            'manifest_hash' => $operation->manifest_hashes['historic_video'],
            'plan_hash' => $operation->plan_hash,
            'items' => [['item_key' => '2024-08-11-morning']],
            'exclusions' => [['item_key' => '2024-08-08-morning', 'exclusion_reason' => 'no_sermon_in_source']],
        ], JSON_THROW_ON_ERROR));
        $reports['manifest_expectation']['sha256'] = hash_file('sha256', $reports['manifest_expectation']['path']);
        $this->writeReport($reports, 'video_status', [
            ...$this->binding($operation),
            'open_runs' => 0,
            'historic_queue_depth' => 0,
        ]);
        $this->writeReport($reports, 'membership_census', [
            ...$this->binding($operation),
            'counts' => ['complete' => 0, 'excluded' => 1, 'unresolved' => 1],
            'items' => [
                ['item_key' => '2024-08-08-morning', 'disposition' => 'excluded', 'reason' => 'no_sermon_in_source'],
                ['item_key' => '2024-08-11-morning', 'disposition' => 'unresolved'],
            ],
        ]);
        $this->writeReport($reports, 'asset_audit', ['missing_asset_references' => 0]);
        $this->writeReport($reports, 'scripture_settlement', ['public_exposure' => 0]);
        $this->writeReport($reports, 'operation_ledger', [
            'live_jobs' => 0,
            'external_notifications_sent' => 0,
            'nested_jobs' => ['unsettled' => 0, 'failed_settled' => 0],
            'failed_nested_jobs' => [],
        ]);
        $this->writeReport($reports, 'cost_duration', [
            'all_runs' => ['runs_missing_timing_count' => 0, 'retried_run_count' => 0],
        ]);

        $holdPath = tempnam(sys_get_temp_dir(), 'historic-video-holds-');
        self::assertIsString($holdPath);
        file_put_contents($holdPath, json_encode([
            ...$this->binding($operation),
            'items' => [[
                'item_key' => '2024-08-11-morning',
                'disposition' => 'accepted_hold',
                'reason' => 'source boundary remains unobservable',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->paths[] = $holdPath;

        $cover = [
            'format' => HistoricVideoRoundEvidence::Format,
            'version' => HistoricVideoRoundEvidence::Version,
            'round' => 'round-4',
            'operation_id' => $operation->operation_id,
            'binding_hash' => $operation->binding_hash,
            'batch_key' => $operation->batch_key,
            'commit' => str_repeat('f', 40),
            'target_fingerprint' => $operation->target_fingerprint,
            'runtime_fingerprint' => $operation->runtime_fingerprint,
            'manifest_hash' => $operation->manifest_hashes['historic_video'],
            'plan_hash' => $operation->plan_hash,
            'backup_receipt' => 'backup-2026-09-19',
            'reviewed_by' => 'maintainer',
            'reviewed_at' => '2026-09-19T12:00:00Z',
            'reports' => $reports,
            'items' => [
                ['item_key' => '2024-08-08-morning', 'disposition' => 'excluded', 'reason' => 'no_sermon_in_source'],
                [
                    'item_key' => '2024-08-11-morning',
                    'disposition' => 'accepted_hold',
                    'reason' => 'source boundary remains unobservable',
                    'evidence_reference' => ['path' => $holdPath, 'sha256' => hash_file('sha256', $holdPath)],
                ],
            ],
            'residues' => [
                [
                    'name' => 'accepted_holds',
                    'count' => 1,
                    'source_report' => 'membership_census',
                    'explanation' => 'Enumerated in items.',
                ],
            ],
        ];
        $cover['signature'] = [
            'algorithm' => 'hmac-sha256',
            'key_id' => 'test-key',
            'digest' => hash_hmac('sha256', CanonicalJson::encode($cover), 'round-key'),
        ];

        return $cover;
    }

    /** @return array<string, mixed> */
    private function coverWithSettledNestedFailure(HistoricImportOperation $operation): array
    {
        $run = MediaProcessingLog::factory()->create([
            'historic_import_operation_id' => $operation->id,
            'processing_id' => 'failed-then-completed',
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::Completed,
            'completed_at' => '2026-09-19 12:01:00',
        ]);
        HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $run->id,
            'job_key' => 'auto-publish-section-1',
            'job_type' => 'test-job',
            'state' => 'failed',
            'attempts' => 3,
            'error_fingerprint' => str_repeat('a', 64),
            'dispatched_at' => '2026-09-19 11:59:00',
            'settled_at' => '2026-09-19 12:00:00',
        ]);
        $cover = $this->cover($operation);
        $this->writeReport($cover['reports'], 'operation_ledger', [
            'live_jobs' => 0,
            'external_notifications_sent' => 0,
            'nested_jobs' => ['unsettled' => 0, 'failed_settled' => 1],
            'failed_nested_jobs' => [[
                'processing_id' => $run->processing_id,
                'job_key' => 'auto-publish-section-1',
                'attempts' => 3,
                'settled_at' => '2026-09-19T12:00:00+00:00',
                'resolution' => 'contained_not_superseded',
                'explanation' => 'The failed publication is retained as a contained residue.',
            ]],
        ]);
        $cover['residues'][] = [
            'name' => 'failed_nested_jobs',
            'count' => 1,
            'source_report' => 'operation_ledger',
            'explanation' => 'The failed job is settled and explained.',
        ];
        $cover['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($cover, ['signature' => true])),
            'round-key',
        );

        return $cover;
    }

    /** @param array<string, array<string, string>> $reports
     * @param  array<string, mixed>  $payload
     */
    private function writeReport(array &$reports, string $key, array $payload): void
    {
        file_put_contents($reports[$key]['path'], json_encode($payload, JSON_THROW_ON_ERROR));
        $reports[$key]['sha256'] = hash_file('sha256', $reports[$key]['path']);
    }

    /** @return array<string, string> */
    private function binding(HistoricImportOperation $operation): array
    {
        return [
            'operation_id' => $operation->operation_id,
            'manifest_hash' => $operation->manifest_hashes['historic_video'],
            'plan_hash' => $operation->plan_hash,
        ];
    }
}
