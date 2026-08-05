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
}
