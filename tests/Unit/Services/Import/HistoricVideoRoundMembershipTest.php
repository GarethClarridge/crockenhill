<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Enums\HistoricImportOperationState;
use App\Enums\ProcessingStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\Import\HistoricVideoRoundMembership;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricVideoRoundMembershipTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_accounts_for_completed_source_consumption_separately_from_service_authority(): void
    {
        $operation = $this->operation();
        $replacement = MediaProcessingLog::factory()->livestream()->failed()->create([
            'extracted_date' => '2024-01-21',
            'extracted_service' => 'morning',
        ]);
        $historic = $this->historicRun($operation, '2024-01-21-morning', ProcessingStatus::Completed);
        $historic->forceFill([
            'superseded_at' => now(),
            'superseded_by_processing_log_id' => $replacement->id,
        ])->save();

        $report = app(HistoricVideoRoundMembership::class)->report($operation, $this->expectation([
            $this->item('2024-01-21-morning'),
        ]));

        self::assertSame('complete', $report['items'][0]['disposition']);
        self::assertSame('operation_source_consumed', $report['items'][0]['basis']);
        self::assertSame($replacement->processing_id, $report['items'][0]['authoritative_processing_id']);
        self::assertSame(['complete' => 1], $report['counts']);
        self::assertSame([], $report['unresolved']);
    }

    #[Test]
    public function it_accounts_for_a_live_completion_that_prevented_operation_dispatch(): void
    {
        $operation = $this->operation();
        $prior = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2023-05-07',
            'extracted_service' => 'morning',
        ]);

        $report = app(HistoricVideoRoundMembership::class)->report($operation, $this->expectation([
            $this->item('2023-05-07-morning'),
        ]));

        self::assertSame('complete', $report['items'][0]['disposition']);
        self::assertSame('preexisting_live_completion', $report['items'][0]['basis']);
        self::assertSame($prior->processing_id, $report['items'][0]['authoritative_processing_id']);
    }

    #[Test]
    public function it_keeps_manual_review_and_missing_identities_unresolved(): void
    {
        $operation = $this->operation();
        $review = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => '2024-05-05',
            'extracted_service' => 'morning',
            'processing_metadata' => [
                'manual_review' => [
                    'reason_code' => 'llm_structure_validation_failed',
                    'reason_message' => 'Detected structure needs review.',
                ],
            ],
        ]);

        $report = app(HistoricVideoRoundMembership::class)->report($operation, $this->expectation([
            $this->item('2024-05-05-morning'),
            $this->item('2025-01-05-morning'),
        ]));

        self::assertSame('unresolved', $report['items'][0]['disposition']);
        self::assertSame('manual_review', $report['items'][0]['basis']);
        self::assertSame($review->processing_id, $report['items'][0]['authoritative_processing_id']);
        self::assertSame('unresolved', $report['items'][1]['disposition']);
        self::assertSame('missing', $report['items'][1]['basis']);
        self::assertSame(['2024-05-05-morning', '2025-01-05-morning'], $report['unresolved']);
    }

    #[Test]
    public function it_includes_manifest_exclusions_and_refuses_wrong_bindings(): void
    {
        $operation = $this->operation();
        $expectation = $this->expectation([], [[
            'item_key' => '2025-06-13-morning',
            'exclusion_reason' => 'Not a church service.',
            'duplicate_of' => null,
        ]]);

        $report = app(HistoricVideoRoundMembership::class)->report($operation, $expectation);

        self::assertSame('excluded', $report['items'][0]['disposition']);
        self::assertSame('manifest_exclusion', $report['items'][0]['basis']);

        $expectation['manifest_hash'] = str_repeat('f', 64);

        $this->expectExceptionMessage('does not match the operation');
        app(HistoricVideoRoundMembership::class)->report($operation, $expectation);
    }

    private function operation(): HistoricImportOperation
    {
        return HistoricImportOperation::query()->create([
            'operation_id' => 'historic-membership-test',
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
    }

    private function historicRun(
        HistoricImportOperation $operation,
        string $itemKey,
        ProcessingStatus $status,
    ): MediaProcessingLog {
        [$date, $service] = [substr($itemKey, 0, 10), str($itemKey)->afterLast('-')->toString()];

        return MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'extracted_date' => $date,
            'extracted_service' => $service,
            'status' => $status,
            'current_step' => 'completed',
            'processing_metadata' => [
                'historic_import' => ['manifest_item_key' => $itemKey],
            ],
        ]);
    }

    /** @param list<array<string, mixed>> $items
     * @param  list<array<string, mixed>>  $exclusions
     * @return array<string, mixed>
     */
    private function expectation(array $items, array $exclusions = []): array
    {
        return [
            'format' => 'crockenhill-historic-video-import-plan',
            'version' => 1,
            'batch_key' => 'historic-video-test',
            'manifest_hash' => str_repeat('b', 64),
            'plan_hash' => str_repeat('c', 64),
            'counts' => [
                'raw' => count($items) + count($exclusions),
                'include' => count($items),
                'exclude' => count($exclusions),
            ],
            'items' => $items,
            'exclusions' => $exclusions,
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $itemKey): array
    {
        return [
            'item_key' => $itemKey,
            'date' => substr($itemKey, 0, 10),
            'service' => str($itemKey)->afterLast('-')->toString(),
        ];
    }
}
