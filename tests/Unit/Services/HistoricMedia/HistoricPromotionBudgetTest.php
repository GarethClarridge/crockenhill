<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricPromotionBudget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * §13.4 requires the production-shaped rehearsal to benchmark deterministic
 * promotion separately from the media pass: per-service p95 apply time,
 * asset-copy throughput, preflight/audit time and rollback recovery. §15.2 then
 * requires G7 to record numeric values derived from them. Local Whisper and AI
 * throughput are explicitly not a proxy for any of it.
 */
class HistoricPromotionBudgetTest extends TestCase
{
    private HistoricPromotionBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->budget = new HistoricPromotionBudget;
    }

    #[Test]
    public function it_takes_percentiles_by_nearest_rank_so_a_sample_is_never_interpolated_away(): void
    {
        $percentiles = $this->budget->percentiles([10.0, 20.0, 30.0, 40.0, 100.0]);

        $this->assertSame(5, $percentiles['count']);
        $this->assertSame(30.0, $percentiles['p50']);
        $this->assertSame(100.0, $percentiles['p95']);
        $this->assertSame(100.0, $percentiles['max']);
    }

    #[Test]
    public function a_single_sample_is_its_own_p50_and_p95(): void
    {
        $percentiles = $this->budget->percentiles([42.5]);

        $this->assertSame(42.5, $percentiles['p50']);
        $this->assertSame(42.5, $percentiles['p95']);
        $this->assertSame(1, $percentiles['count']);
    }

    #[Test]
    public function an_empty_sample_set_reports_null_rather_than_zero(): void
    {
        $percentiles = $this->budget->percentiles([]);

        $this->assertNull($percentiles['p50']);
        $this->assertNull($percentiles['p95']);
        $this->assertNull($percentiles['max']);
        $this->assertSame(0, $percentiles['count']);
    }

    /**
     * §15.2's five recorded values, derived from measurement rather than assumed.
     * The 60-minute cap is the plan's default; it is an input here, never an
     * output, because only the maintainer may accept it.
     */
    #[Test]
    public function it_derives_the_section_15_2_window_values_from_measured_samples(): void
    {
        $report = $this->budget->report(
            applySeconds: [60.0, 90.0, 120.0],
            preflightSeconds: [300.0],
            closeoutSeconds: [240.0],
            rollbackSeconds: [45.0],
            assetBytes: [1_048_576, 2_097_152],
            assetSeconds: [1.0, 1.0],
            acceptedCapMinutes: 60,
        );

        $this->assertSame(120.0, $report['per_service_apply_seconds']['p95']);
        $this->assertSame(5.0, $report['preflight_reserve_minutes']);
        $this->assertSame(4.0, $report['closeout_reserve_minutes']);
        $this->assertSame(0.75, $report['rollback_reserve_minutes']);
        $this->assertSame(60, $report['maximum_import_ingress_blocked_minutes']);
    }

    /**
     * The applying budget is the accepted cap less everything that is not
     * applying: preflight before the first service, and the closeout plus
     * rollback reserve that must still fit after the last one.
     */
    #[Test]
    public function services_per_window_is_the_applying_budget_over_the_p95_apply_time(): void
    {
        $report = $this->budget->report(
            applySeconds: [60.0],
            preflightSeconds: [300.0],
            closeoutSeconds: [240.0],
            rollbackSeconds: [60.0],
            assetBytes: [],
            assetSeconds: [],
            acceptedCapMinutes: 60,
        );

        // 60 - 5 preflight - 4 closeout - 1 rollback = 50 applying minutes,
        // at 1 minute per service.
        $this->assertSame(50.0, $report['applying_budget_minutes']);
        $this->assertSame(50, $report['services_per_window']);
    }

    /**
     * §15.2: the command stops admitting new services when remaining time equals
     * the greater of 15 minutes or the accepted p95 closeout/resume duration.
     */
    #[Test]
    public function the_admission_floor_is_the_greater_of_fifteen_minutes_and_the_closeout_p95(): void
    {
        $shortCloseout = $this->budget->report(
            applySeconds: [60.0],
            preflightSeconds: [60.0],
            closeoutSeconds: [120.0],
            rollbackSeconds: [60.0],
            assetBytes: [],
            assetSeconds: [],
            acceptedCapMinutes: 60,
        );

        $this->assertSame(15.0, $shortCloseout['admission_floor_minutes']);

        $longCloseout = $this->budget->report(
            applySeconds: [60.0],
            preflightSeconds: [60.0],
            closeoutSeconds: [1500.0],
            rollbackSeconds: [60.0],
            assetBytes: [],
            assetSeconds: [],
            acceptedCapMinutes: 60,
        );

        $this->assertSame(25.0, $longCloseout['admission_floor_minutes']);
    }

    #[Test]
    public function asset_copy_throughput_is_bytes_actually_written_over_seconds_spent(): void
    {
        $report = $this->budget->report(
            applySeconds: [60.0],
            preflightSeconds: [60.0],
            closeoutSeconds: [60.0],
            rollbackSeconds: [60.0],
            assetBytes: [10_485_760, 10_485_760],
            assetSeconds: [1.0, 3.0],
            acceptedCapMinutes: 60,
        );

        // 20 MiB over 4 seconds.
        $this->assertSame(5.0, $report['asset_copy_mib_per_second']);
    }

    #[Test]
    public function throughput_is_null_when_no_assets_were_copied_rather_than_infinite(): void
    {
        $report = $this->budget->report(
            applySeconds: [60.0],
            preflightSeconds: [60.0],
            closeoutSeconds: [60.0],
            rollbackSeconds: [60.0],
            assetBytes: [],
            assetSeconds: [],
            acceptedCapMinutes: 60,
        );

        $this->assertNull($report['asset_copy_mib_per_second']);
    }

    /**
     * A budget with no apply samples must not silently report that the window
     * fits an unlimited number of services. G7 accepts numbers; "not measured"
     * has to survive to the report as itself.
     */
    #[Test]
    public function an_unmeasured_budget_reports_not_measured_and_is_not_acceptable(): void
    {
        $report = $this->budget->report(
            applySeconds: [],
            preflightSeconds: [],
            closeoutSeconds: [],
            rollbackSeconds: [],
            assetBytes: [],
            assetSeconds: [],
            acceptedCapMinutes: 60,
        );

        $this->assertNull($report['per_service_apply_seconds']['p95']);
        $this->assertNull($report['services_per_window']);
        $this->assertFalse($report['acceptable']);
        $this->assertContains('per-service apply time', $report['missing_measurements']);
    }

    /**
     * The overrun case §15.2 names: a single service whose p95 apply plus
     * recovery reserve cannot fit the accepted cap at all. The report must say
     * so rather than emitting a services_per_window of zero and looking merely
     * tight.
     */
    #[Test]
    public function a_window_too_short_for_one_service_is_reported_as_unacceptable(): void
    {
        $report = $this->budget->report(
            applySeconds: [3_600.0],
            preflightSeconds: [300.0],
            closeoutSeconds: [300.0],
            rollbackSeconds: [300.0],
            assetBytes: [],
            assetSeconds: [],
            acceptedCapMinutes: 60,
        );

        $this->assertSame(0, $report['services_per_window']);
        $this->assertFalse($report['acceptable']);
        $this->assertContains(
            'The accepted window cannot fit one service at the measured p95 apply time.',
            $report['warnings'],
        );
    }

    #[Test]
    public function a_measured_and_sufficient_budget_is_acceptable(): void
    {
        $report = $this->budget->report(
            applySeconds: [60.0, 65.0, 70.0],
            preflightSeconds: [120.0],
            closeoutSeconds: [180.0],
            rollbackSeconds: [30.0],
            assetBytes: [1_048_576],
            assetSeconds: [0.5],
            acceptedCapMinutes: 60,
        );

        $this->assertTrue($report['acceptable']);
        $this->assertSame([], $report['missing_measurements']);
        $this->assertSame([], $report['warnings']);
    }
}
