<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Data\HistoricConvergenceOperationPlan;
use App\Services\ChurchService\HistoricConvergenceLedger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricConvergenceLedgerTest extends TestCase
{
    #[Test]
    public function it_appends_private_checkpoints_without_replacing_previous_entries(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crockenhill-ledger-');
        self::assertIsString($path);

        try {
            $ledger = new HistoricConvergenceLedger($path);
            $plan = new HistoricConvergenceOperationPlan(
                operationId: 'operation-1',
                planHash: str_repeat('a', 64),
                contentHash: str_repeat('e', 64),
                batchHash: str_repeat('b', 64),
                mediaBundleHash: str_repeat('c', 64),
                convergenceBundleHash: str_repeat('d', 64),
                processingFingerprint: ['format' => 'test'],
                storageIdentity: ['production' => ['name' => 'local']],
                expiresAt: new DateTimeImmutable('+1 hour'),
                services: [],
                summary: ['service_count' => 0],
            );

            $ledger->recordPrepared($plan);
            $ledger->recordFailed($plan, 'persist_media_graph', 'test failure');

            self::assertSame(['prepared', 'failed'], array_column($ledger->entries(), 'event'));
            self::assertSame('operation-1', $ledger->entries()[0]['operation_id']);
            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function it_rejects_local_database_identity_from_the_ledger(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crockenhill-ledger-');
        self::assertIsString($path);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('database identities');

            (new HistoricConvergenceLedger($path))->append(['service_id' => 42]);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function failed_checkpoints_store_a_hash_and_identity_without_the_raw_exception(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crockenhill-ledger-');
        self::assertIsString($path);
        $reason = 'No query results for model [App\\Models\\MediaProcessingLog] 42';

        try {
            $ledger = new HistoricConvergenceLedger($path);
            $plan = new HistoricConvergenceOperationPlan(
                operationId: 'operation-2',
                planHash: str_repeat('a', 64),
                contentHash: str_repeat('e', 64),
                batchHash: str_repeat('b', 64),
                mediaBundleHash: str_repeat('c', 64),
                convergenceBundleHash: str_repeat('d', 64),
                processingFingerprint: ['format' => 'test'],
                storageIdentity: ['production' => ['name' => 'local']],
                expiresAt: new DateTimeImmutable('+1 hour'),
                services: [],
                summary: ['service_count' => 0],
            );

            $ledger->recordFailed($plan, 'ingest_livestream_revision', $reason, '2026-08-02|morning');
            $entry = $ledger->entries()[0];

            self::assertSame('2026-08-02|morning', $entry['identity']);
            self::assertSame(hash('sha256', $reason), $entry['reason_hash']);
            self::assertArrayNotHasKey('reason', $entry);

            /**
             * The phase is what makes the record explanatory: an operator can
             * see how far the service got without the ledger repeating the local
             * identity the exception quoted.
             */
            self::assertSame('ingest_livestream_revision', $entry['phase']);
            self::assertStringNotContainsString('MediaProcessingLog', json_encode($entry, JSON_THROW_ON_ERROR));
        } finally {
            unlink($path);
        }
    }

    /**
     * §13.4 requires per-service p95 apply time and rollback recovery time, and
     * §15.2 requires G7 to accept numeric values derived from them. The ledger is
     * the operation's own record, so it is where the durations belong — a timing
     * kept apart from the run it describes is not evidence of anything, which is
     * the same reasoning §15.2 applies to the ingress window's delay figure.
     */
    #[Test]
    public function every_event_is_stamped_and_completion_carries_its_measured_duration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crockenhill-ledger-');
        self::assertIsString($path);

        try {
            $ledger = new HistoricConvergenceLedger($path);
            $plan = $this->plan('operation-timed');

            $ledger->recordPrepared($plan, 12.5);
            $ledger->recordStarted($plan, ['identity' => '2026-08-02|morning']);
            $ledger->recordCompleted($plan, ['identity' => '2026-08-02|morning'], 61.25, 2_097_152, 1.5);

            [$prepared, $started, $completed] = $ledger->entries();

            self::assertSame(12.5, $prepared['duration_seconds']);
            self::assertNotEmpty($started['at']);
            self::assertSame(61.25, $completed['duration_seconds']);
            self::assertSame(2_097_152, $completed['asset_bytes']);
            self::assertSame(1.5, $completed['asset_seconds']);

            foreach ([$prepared, $started, $completed] as $entry) {
                self::assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
                    (string) $entry['at'],
                );
            }
        } finally {
            unlink($path);
        }
    }

    /**
     * A rollback is measured too: §15.2's rollback/reopen-ingress reserve is one
     * of the five values G7 records, and the only place it can be observed is a
     * service that actually failed and compensated.
     */
    #[Test]
    public function a_failure_records_how_long_the_attempt_and_its_compensation_took(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crockenhill-ledger-');
        self::assertIsString($path);

        try {
            $ledger = new HistoricConvergenceLedger($path);

            $ledger->recordFailed(
                $this->plan('operation-rolled-back'),
                'persist_media_graph',
                'boom',
                '2026-08-02|morning',
                33.5,
            );

            self::assertSame(33.5, $ledger->entries()[0]['duration_seconds']);
        } finally {
            unlink($path);
        }
    }

    /**
     * Durations are optional so that a caller which has not measured says so,
     * rather than reporting a service that applied instantaneously.
     */
    #[Test]
    public function an_unmeasured_event_records_a_null_duration_not_zero(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crockenhill-ledger-');
        self::assertIsString($path);

        try {
            $ledger = new HistoricConvergenceLedger($path);
            $plan = $this->plan('operation-unmeasured');

            $ledger->recordCompleted($plan, ['identity' => '2026-08-02|morning']);

            $entry = $ledger->entries()[0];

            self::assertNull($entry['duration_seconds']);
            self::assertNull($entry['asset_bytes']);
        } finally {
            unlink($path);
        }
    }

    private function plan(string $operationId): HistoricConvergenceOperationPlan
    {
        return new HistoricConvergenceOperationPlan(
            operationId: $operationId,
            planHash: str_repeat('a', 64),
            contentHash: str_repeat('e', 64),
            batchHash: str_repeat('b', 64),
            mediaBundleHash: str_repeat('c', 64),
            convergenceBundleHash: str_repeat('d', 64),
            processingFingerprint: ['format' => 'test'],
            storageIdentity: ['production' => ['name' => 'local']],
            expiresAt: new DateTimeImmutable('+1 hour'),
            services: [],
            summary: ['service_count' => 0],
        );
    }
}
