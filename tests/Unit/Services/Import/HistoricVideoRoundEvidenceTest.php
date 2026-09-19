<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricVideoRoundEvidence;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricVideoRoundEvidenceTest extends TestCase
{
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
                ['item_key' => '2024-08-11-morning', 'disposition' => 'accepted_hold', 'reason' => 'source boundary remains unobservable'],
            ],
            'residues' => [
                ['name' => 'accepted_holds', 'count' => 1, 'explanation' => 'Enumerated in items.'],
            ],
        ];
        $cover['signature'] = [
            'algorithm' => 'hmac-sha256',
            'key_id' => 'test-key',
            'digest' => hash_hmac('sha256', CanonicalJson::encode($cover), 'round-key'),
        ];

        return $cover;
    }
}
