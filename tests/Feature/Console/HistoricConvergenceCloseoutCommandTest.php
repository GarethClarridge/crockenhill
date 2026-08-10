<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use App\Services\ChurchService\ChurchServiceConvergenceAuditor;
use App\Services\ChurchService\HistoricConvergenceCloseout;
use App\Services\ChurchService\HistoricConvergenceLedger;
use App\Services\Import\HistoricImportTargetFingerprint;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricConvergenceCloseoutCommandTest extends TestCase
{
    use RefreshDatabase;

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
    public function operation_closeout_requires_both_bundles_and_a_report(): void
    {
        $bundle = $this->jsonFile(['bundle_hash' => str_repeat('b', 64)]);

        $this->artisan('service-tracking:audit-convergence', [
            'bundle' => $bundle,
            '--operation-id' => 'operation-one',
        ])
            ->expectsOutput('Operation closeout requires exact Bundle A and an immutable private report path.')
            ->assertFailed();
    }

    #[Test]
    public function a_green_closeout_is_written_only_after_its_immutable_report_exists(): void
    {
        [$media, $convergence] = $this->bundles();
        $ledger = $this->ledgerForAppliedOperation('operation-one', $media, $convergence);
        $this->bindCloseout($ledger);
        $this->fakePassingAudit();
        $report = storage_path('app/private/historic-closeout-'.uniqid().'.json');
        $this->paths[] = $report;

        $this->artisan('service-tracking:audit-convergence', [
            'bundle' => $this->jsonFile($convergence),
            '--media-bundle' => $this->jsonFile($media),
            '--operation-id' => 'operation-one',
            '--report' => $report,
        ])->assertSuccessful();

        $event = collect($ledger->entries('operation-one'))->last(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_audit_passed',
        );

        $this->assertIsArray($event);
        $this->assertFileExists($report);
        $this->assertSame(hash_file('sha256', $report), $event['report_digest']);
        $this->assertSame(basename($report), $event['report_locator']);

        file_put_contents($report, 'tampered');

        $this->artisan('service-tracking:audit-convergence', [
            'bundle' => $this->jsonFile($convergence),
            '--media-bundle' => $this->jsonFile($media),
            '--operation-id' => 'operation-one',
            '--report' => $report,
            '--verify-closeout' => true,
        ])
            ->expectsOutput('Recorded exact closeout report is missing or differs from its durable digest.')
            ->assertFailed();
    }

    #[Test]
    public function a_mismatched_or_incomplete_operation_writes_no_passed_event(): void
    {
        [$media, $convergence] = $this->bundles();
        $ledger = new HistoricConvergenceLedger(sys_get_temp_dir().'/historic-closeout-ledger-'.uniqid().'.jsonl');
        $this->paths[] = $this->ledgerPath($ledger);
        $this->bindCloseout($ledger);
        $this->fakePassingAudit();
        $report = storage_path('app/private/historic-closeout-'.uniqid().'.json');
        $this->paths[] = $report;

        $this->artisan('service-tracking:audit-convergence', [
            'bundle' => $this->jsonFile($convergence),
            '--media-bundle' => $this->jsonFile($media),
            '--operation-id' => 'unrelated-operation',
            '--report' => $report,
        ])
            ->expectsOutput('Exact closeout requires the matching closeout-ready durable operation.')
            ->assertFailed();

        $this->assertEmpty($ledger->entries('unrelated-operation'));
        $this->assertFileDoesNotExist($report);
    }

    #[Test]
    public function report_commit_failure_cannot_leave_a_passed_closeout_event(): void
    {
        [$media, $convergence] = $this->bundles();
        $ledger = $this->ledgerForAppliedOperation('operation-one', $media, $convergence);
        $this->bindCloseout($ledger);
        $this->fakePassingAudit();
        $report = storage_path('app/private/historic-closeout-'.uniqid().'.json');
        file_put_contents($report, 'pre-existing evidence');
        $this->paths[] = $report;

        $this->artisan('service-tracking:audit-convergence', [
            'bundle' => $this->jsonFile($convergence),
            '--media-bundle' => $this->jsonFile($media),
            '--operation-id' => 'operation-one',
            '--report' => $report,
        ])
            ->expectsOutput('The operation closeout report already exists and is immutable.')
            ->assertFailed();

        $this->assertFalse(collect($ledger->entries('operation-one'))->contains(
            static fn (array $entry): bool => ($entry['event'] ?? null) === 'exact_audit_passed',
        ));
        $this->assertSame('pre-existing evidence', file_get_contents($report));
    }

    #[Test]
    public function a_later_failed_audit_cannot_complete_the_operation_from_an_old_green_report(): void
    {
        [$media, $convergence] = $this->bundles();
        $ledger = $this->ledgerForAppliedOperation('operation-one', $media, $convergence);
        $this->bindCloseout($ledger);
        $this->fakePassingAudit();
        $report = storage_path('app/private/historic-closeout-'.uniqid().'.json');
        $this->paths[] = $report;
        $arguments = [
            'bundle' => $this->jsonFile($convergence),
            '--media-bundle' => $this->jsonFile($media),
            '--operation-id' => 'operation-one',
            '--report' => $report,
        ];

        $this->artisan('service-tracking:audit-convergence', $arguments)->assertSuccessful();
        $this->fakeFailingAudit();

        $this->artisan('service-tracking:audit-convergence', [
            ...$arguments,
            '--verify-closeout' => true,
        ])
            ->expectsOutput('Current exact convergence audit failed; operation closeout remains incomplete.')
            ->assertFailed();

        $this->assertDatabaseHas('historic_import_operations', [
            'operation_id' => 'operation-one',
            'state' => HistoricImportOperationState::CloseoutRequired->value,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function bundles(): array
    {
        $fingerprint = ['provider' => 'definitive', 'version' => 1];
        $media = [
            'batch_hash' => str_repeat('a', 64),
            'bundle_hash' => str_repeat('b', 64),
            'processing_fingerprint' => $fingerprint,
        ];
        $convergence = [
            'batch_hash' => $media['batch_hash'],
            'bundle_hash' => str_repeat('c', 64),
            'media_bundle_hash' => $media['bundle_hash'],
            'processing_fingerprint' => $fingerprint,
        ];

        return [$media, $convergence];
    }

    /** @param array<string, mixed> $media @param array<string, mixed> $convergence */
    private function ledgerForAppliedOperation(
        string $operationId,
        array $media,
        array $convergence,
    ): HistoricConvergenceLedger {
        $path = sys_get_temp_dir().'/historic-closeout-ledger-'.uniqid().'.jsonl';
        $this->paths[] = $path;
        $ledger = new HistoricConvergenceLedger($path);
        HistoricImportOperation::query()->create([
            'operation_id' => $operationId,
            'binding_hash' => hash('sha256', $operationId.'-binding'),
            'batch_key' => 'closeout-test',
            'manifest_hashes' => ['media' => $media['bundle_hash'], 'convergence' => $convergence['bundle_hash']],
            'plan_hash' => str_repeat('d', 64),
            'target_fingerprint' => app(HistoricImportTargetFingerprint::class)->hash(),
            'runtime_fingerprint' => str_repeat('f', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 1_000,
            'state' => HistoricImportOperationState::CloseoutRequired,
            'accepted_deadline' => now()->addHour(),
        ]);
        $ledger->append([
            'event' => 'prepared',
            'operation_id' => $operationId,
            'plan_hash' => str_repeat('d', 64),
            'content_hash' => str_repeat('e', 64),
            'batch_hash' => $media['batch_hash'],
            'media_bundle_hash' => $media['bundle_hash'],
            'convergence_bundle_hash' => $convergence['bundle_hash'],
            'processing_fingerprint_hash' => CanonicalJson::hash($media['processing_fingerprint']),
            'target_fingerprint' => app(HistoricImportTargetFingerprint::class)->hash(),
            'summary' => ['services' => [['identity' => '2020-01-01|morning']]],
        ]);
        $ledger->append([
            'event' => 'service_completed',
            'operation_id' => $operationId,
            'identity' => '2020-01-01|morning',
        ]);

        return $ledger;
    }

    private function bindCloseout(HistoricConvergenceLedger $ledger): void
    {
        $this->app->instance(HistoricConvergenceLedger::class, $ledger);
        $this->app->instance(HistoricConvergenceCloseout::class, new HistoricConvergenceCloseout(
            $ledger,
            app(HistoricImportTargetFingerprint::class),
        ));
    }

    private function fakePassingAudit(): void
    {
        $auditor = $this->createMock(ChurchServiceConvergenceAuditor::class);
        $auditor->method('audit')->willReturn([
            'format' => 'crockenhill-service-convergence-audit',
            'version' => 1,
            'bundle_hash' => str_repeat('c', 64),
            'passed' => true,
            'totals' => ['services' => 1, 'passed' => 1, 'failed' => 0],
            'services' => [],
        ]);
        $this->app->instance(ChurchServiceConvergenceAuditor::class, $auditor);
    }

    private function fakeFailingAudit(): void
    {
        $auditor = $this->createMock(ChurchServiceConvergenceAuditor::class);
        $auditor->method('audit')->willReturn([
            'format' => 'crockenhill-service-convergence-audit',
            'version' => 1,
            'bundle_hash' => str_repeat('c', 64),
            'passed' => false,
            'totals' => ['services' => 1, 'passed' => 0, 'failed' => 1],
            'services' => [['identity' => '2020-01-01|morning', 'passed' => false]],
        ]);
        $this->app->instance(ChurchServiceConvergenceAuditor::class, $auditor);
    }

    /** @param array<string, mixed> $contents */
    private function jsonFile(array $contents): string
    {
        $path = sys_get_temp_dir().'/historic-closeout-bundle-'.uniqid().'.json';
        file_put_contents($path, json_encode($contents, JSON_THROW_ON_ERROR));
        $this->paths[] = $path;

        return $path;
    }

    private function ledgerPath(HistoricConvergenceLedger $ledger): string
    {
        $reflection = new \ReflectionProperty($ledger, 'path');

        return (string) $reflection->getValue($ledger);
    }
}
