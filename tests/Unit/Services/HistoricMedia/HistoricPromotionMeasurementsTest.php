<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricPromotionMeasurements;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HistoricPromotionMeasurementsTest extends TestCase
{
    private HistoricPromotionMeasurements $measurements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->measurements = new HistoricPromotionMeasurements;
    }

    #[Test]
    public function it_sorts_each_event_into_the_phase_it_measures(): void
    {
        $samples = $this->measurements->fromLedgerEntries([
            ['event' => 'prepared', 'operation_id' => 'op-1', 'duration_seconds' => 300.0],
            ['event' => 'service_started', 'operation_id' => 'op-1'],
            ['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 61.0],
            ['event' => 'failed', 'operation_id' => 'op-1', 'duration_seconds' => 12.0],
            ['event' => 'closeout', 'operation_id' => 'op-1', 'duration_seconds' => 240.0],
        ]);

        $this->assertSame([300.0], $samples['preflight_seconds']);
        $this->assertSame([61.0], $samples['apply_seconds']);
        $this->assertSame([12.0], $samples['rollback_seconds']);
        $this->assertSame([240.0], $samples['closeout_seconds']);
        $this->assertSame(['op-1'], $samples['operations']);
        $this->assertSame(1, $samples['services_applied']);
        $this->assertSame(1, $samples['services_failed']);
    }

    /**
     * An event with no duration is a run from before the ledger measured itself.
     * It must contribute no sample rather than a zero, which would drag a p95
     * down and make a window look roomier than it is.
     */
    #[Test]
    public function an_event_without_a_duration_contributes_no_sample(): void
    {
        $samples = $this->measurements->fromLedgerEntries([
            ['event' => 'service_completed', 'operation_id' => 'op-1'],
            ['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => null],
            ['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 61.0],
        ]);

        $this->assertSame([61.0], $samples['apply_seconds']);
        $this->assertSame(3, $samples['services_applied']);
    }

    #[Test]
    public function a_throughput_sample_needs_both_bytes_and_elapsed_time(): void
    {
        $samples = $this->measurements->fromLedgerEntries([
            ['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 1.0, 'asset_bytes' => 1024, 'asset_seconds' => null],
            ['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 1.0, 'asset_bytes' => 0, 'asset_seconds' => 2.0],
            ['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 1.0, 'asset_bytes' => 2048, 'asset_seconds' => 2.0],
        ]);

        $this->assertSame([2048], $samples['asset_bytes']);
        $this->assertSame([2.0], $samples['asset_seconds']);
    }

    /**
     * An already_present rerun copies nothing and records zero bytes. Counting
     * it would report a service that moved no data as though it had, and every
     * no-op rerun would inflate the measured throughput.
     */
    #[Test]
    public function a_no_op_service_contributes_an_apply_sample_but_no_throughput_sample(): void
    {
        $samples = $this->measurements->fromLedgerEntries([
            [
                'event' => 'service_completed',
                'operation_id' => 'op-1',
                'duration_seconds' => 4.0,
                'classification' => 'already_present',
                'asset_bytes' => 0,
                'asset_seconds' => null,
            ],
        ]);

        $this->assertSame([4.0], $samples['apply_seconds']);
        $this->assertSame([], $samples['asset_bytes']);
    }

    #[Test]
    public function it_records_every_operation_the_entries_span(): void
    {
        $samples = $this->measurements->fromLedgerEntries([
            ['event' => 'prepared', 'operation_id' => 'op-1', 'duration_seconds' => 1.0],
            ['event' => 'prepared', 'operation_id' => 'op-2', 'duration_seconds' => 2.0],
            ['event' => 'prepared', 'operation_id' => 'op-1', 'duration_seconds' => 3.0],
        ]);

        $this->assertSame(['op-1', 'op-2'], $samples['operations']);
        $this->assertSame([1.0, 2.0, 3.0], $samples['preflight_seconds']);
    }

    #[Test]
    public function an_empty_ledger_yields_empty_samples(): void
    {
        $samples = $this->measurements->fromLedgerEntries([]);

        $this->assertSame([], $samples['apply_seconds']);
        $this->assertSame(0, $samples['services_applied']);
        $this->assertSame(0, $samples['services_failed']);
    }
}
