<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ChurchService\HistoricConvergenceLedger;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricPromotionBudgetCommandTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'crockenhill-budget-ledger-');
        self::assertIsString($path);
        $this->ledgerPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_reports_the_budget_measured_by_a_complete_rehearsal(): void
    {
        $ledger = new HistoricConvergenceLedger($this->ledgerPath);
        $ledger->append(['event' => 'prepared', 'operation_id' => 'op-1', 'duration_seconds' => 120.0]);

        foreach ([60.0, 65.0, 70.0] as $seconds) {
            $ledger->append([
                'event' => 'service_completed',
                'operation_id' => 'op-1',
                'duration_seconds' => $seconds,
                'asset_bytes' => 10_485_760,
                'asset_seconds' => 2.0,
            ]);
        }

        $ledger->append(['event' => 'failed', 'operation_id' => 'op-1', 'duration_seconds' => 30.0]);
        $this->appendCloseout($ledger, 'op-1', 60.0, 120.0);

        $report = $this->jsonReport();

        $this->assertSame(70.0, $report['per_service_apply_seconds']['p95']);
        $this->assertSame(2.0, $report['preflight_reserve_minutes']);
        $this->assertSame(3.0, $report['closeout_reserve_minutes']);
        $this->assertSame(0.5, $report['rollback_reserve_minutes']);
        $this->assertSame(3, $report['services_applied']);
        $this->assertSame(1, $report['services_failed']);
        $this->assertSame(['op-1'], $report['operations']);
        $this->assertTrue($report['acceptable']);
    }

    #[Test]
    public function it_succeeds_only_when_every_phase_has_been_measured(): void
    {
        $ledger = new HistoricConvergenceLedger($this->ledgerPath);
        $ledger->append(['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 60.0]);

        $this->artisan("service-tracking:promotion-budget --ledger={$this->ledgerPath}")
            ->expectsOutputToContain('Not measured: preflight time.')
            ->assertFailed();
    }

    /**
     * An empty ledger is the state before any rehearsal has run. It must fail
     * rather than report a budget of zero measured minutes as though it fitted.
     */
    #[Test]
    public function an_empty_ledger_fails_instead_of_reporting_an_empty_budget_as_acceptable(): void
    {
        $this->artisan("service-tracking:promotion-budget --ledger={$this->ledgerPath}")
            ->assertFailed();

        $report = $this->jsonReport();

        $this->assertFalse($report['acceptable']);
        $this->assertNull($report['services_per_window']);
        $this->assertCount(4, $report['missing_measurements']);
    }

    #[Test]
    public function it_restricts_the_budget_to_one_operation(): void
    {
        $ledger = new HistoricConvergenceLedger($this->ledgerPath);
        $ledger->append(['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 60.0]);
        $ledger->append(['event' => 'service_completed', 'operation_id' => 'op-2', 'duration_seconds' => 600.0]);

        $report = $this->jsonReport('--operation-id=op-1');

        $this->assertSame(['op-1'], $report['operations']);
        $this->assertSame(60.0, $report['per_service_apply_seconds']['p95']);
    }

    #[Test]
    public function the_accepted_cap_is_an_input_the_operator_supplies(): void
    {
        $this->seedCompleteLedger();

        $report = $this->jsonReport('--cap-minutes=30');

        $this->assertSame(30, $report['accepted_cap_minutes']);
        $this->assertSame(30, $report['maximum_import_ingress_blocked_minutes']);
    }

    #[Test]
    public function it_refuses_a_cap_that_is_not_a_positive_integer(): void
    {
        $this->artisan("service-tracking:promotion-budget --ledger={$this->ledgerPath} --cap-minutes=0")
            ->expectsOutputToContain('--cap-minutes must be a positive integer.')
            ->assertFailed();
    }

    /**
     * §15.2's overrun case, reported as unacceptable rather than as a window
     * that merely fits nothing.
     */
    #[Test]
    public function a_window_too_short_for_one_service_fails(): void
    {
        $ledger = new HistoricConvergenceLedger($this->ledgerPath);
        $ledger->append(['event' => 'prepared', 'operation_id' => 'op-1', 'duration_seconds' => 60.0]);
        $ledger->append(['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 5_400.0]);
        $ledger->append(['event' => 'failed', 'operation_id' => 'op-1', 'duration_seconds' => 60.0]);
        $this->appendCloseout($ledger, 'op-1', 20.0, 40.0);

        $this->artisan("service-tracking:promotion-budget --ledger={$this->ledgerPath}")
            ->expectsOutputToContain('cannot fit one service')
            ->assertFailed();
    }

    private function seedCompleteLedger(): void
    {
        $ledger = new HistoricConvergenceLedger($this->ledgerPath);
        $ledger->append(['event' => 'prepared', 'operation_id' => 'op-1', 'duration_seconds' => 60.0]);
        $ledger->append(['event' => 'service_completed', 'operation_id' => 'op-1', 'duration_seconds' => 60.0]);
        $ledger->append(['event' => 'failed', 'operation_id' => 'op-1', 'duration_seconds' => 10.0]);
        $this->appendCloseout($ledger, 'op-1', 20.0, 40.0);
    }

    private function appendCloseout(
        HistoricConvergenceLedger $ledger,
        string $operationId,
        float $auditSeconds,
        float $rerunSeconds,
    ): void {
        $ledger->append([
            'event' => 'exact_audit_passed',
            'operation_id' => $operationId,
            'duration_seconds' => $auditSeconds,
            'passed' => true,
        ]);
        $ledger->append([
            'event' => 'exact_noop_rerun',
            'operation_id' => $operationId,
            'duration_seconds' => $rerunSeconds,
            'passed' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function jsonReport(string $options = ''): array
    {
        Artisan::call(trim("service-tracking:promotion-budget --ledger={$this->ledgerPath} --json {$options}"));
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
