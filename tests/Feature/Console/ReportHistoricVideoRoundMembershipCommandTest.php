<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportHistoricVideoRoundMembershipCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_writes_the_exact_report_and_fails_while_membership_is_unresolved(): void
    {
        $operation = HistoricImportOperation::query()->create([
            'operation_id' => 'historic-membership-command-test',
            'binding_hash' => str_repeat('a', 64),
            'batch_key' => 'historic-video-test',
            'manifest_hashes' => ['historic_video' => str_repeat('b', 64)],
            'plan_hash' => str_repeat('c', 64),
            'target_fingerprint' => str_repeat('d', 64),
            'runtime_fingerprint' => str_repeat('e', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 100,
            'state' => HistoricImportOperationState::Planned,
            'accepted_deadline' => now()->addDay(),
        ]);
        $directory = storage_path('framework/testing/video-round-membership-'.uniqid());
        $expectationPath = "{$directory}/expectation.json";
        $reportPath = "{$directory}/report.json";
        mkdir($directory, 0700, true);
        file_put_contents($expectationPath, json_encode([
            'format' => 'crockenhill-historic-video-import-plan',
            'version' => 1,
            'batch_key' => $operation->batch_key,
            'manifest_hash' => str_repeat('b', 64),
            'plan_hash' => str_repeat('c', 64),
            'counts' => ['raw' => 1, 'include' => 1, 'exclude' => 0],
            'items' => [[
                'item_key' => '2025-01-05-morning',
                'date' => '2025-01-05',
                'service' => 'morning',
            ]],
            'exclusions' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->artisan('historic-import:report-video-round-membership', [
                'operation' => $operation->operation_id,
                'expectation' => $expectationPath,
                'report' => $reportPath,
            ])
                ->expectsOutputToContain('unresolved: 1')
                ->expectsOutputToContain('Membership remains unresolved: 2025-01-05-morning')
                ->assertFailed();

            $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['2025-01-05-morning'], $report['unresolved']);
            self::assertSame(0600, fileperms($reportPath) & 0777);
        } finally {
            @unlink($reportPath);
            @unlink($expectationPath);
            @rmdir($directory);
        }
    }
}
