<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\HistoricImportCheckpointState;
use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportCheckpoint;
use App\Models\HistoricImportOperation;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricImportCheckpointPlanner
{
    private const MaxItems = 25;

    private const MaxForecastSeconds = 43_200;

    public function __construct(
        private readonly HistoricImportJournal $journal,
    ) {}

    /**
     * @param  list<array{item_key: string, forecast_seconds: int, accepted_cost_minor_units?: int}>  $items
     * @return list<HistoricImportCheckpoint>
     */
    public function plan(HistoricImportOperation $operation, array $items): array
    {
        if ($operation->state !== HistoricImportOperationState::Planned || $operation->checkpoints()->exists()) {
            throw new RuntimeException('Historic import checkpoints can only be planned once for a planned operation.');
        }

        $items = $this->normalizeItems($items);
        $partitions = $this->partitions($items);

        return DB::transaction(function () use ($operation, $partitions): array {
            $checkpoints = [];

            foreach ($partitions as $index => $partition) {
                $ordinal = $index + 1;
                $itemKeys = array_column($partition, 'item_key');
                $membershipHash = CanonicalJson::hash([
                    'contract_version' => 1,
                    'ordinal' => $ordinal,
                    'items' => $partition,
                ]);
                $checkpoint = $operation->checkpoints()->create([
                    'checkpoint_key' => sprintf('checkpoint-%03d-%s', $ordinal, substr($membershipHash, 0, 12)),
                    'ordinal' => $ordinal,
                    'membership_hash' => $membershipHash,
                    'item_keys' => $itemKeys,
                    'forecast_seconds' => array_sum(array_column($partition, 'forecast_seconds')),
                    'accepted_cost_minor_units' => array_sum(array_column($partition, 'accepted_cost_minor_units')),
                    'state' => HistoricImportCheckpointState::Planned,
                ]);
                $this->journal->append($operation, 'checkpoint_planned', [
                    'checkpoint_key' => $checkpoint->checkpoint_key,
                    'membership_hash' => $membershipHash,
                    'item_keys' => $itemKeys,
                    'forecast_seconds' => $checkpoint->forecast_seconds,
                    'accepted_cost_minor_units' => $checkpoint->accepted_cost_minor_units,
                ], $checkpoint);
                $checkpoints[] = $checkpoint;
            }

            return $checkpoints;
        });
    }

    /**
     * @param  list<array{item_key: string, forecast_seconds: int, accepted_cost_minor_units?: int}>  $items
     * @return list<array{item_key: string, forecast_seconds: int, accepted_cost_minor_units: int}>
     */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new RuntimeException('Historic import checkpoint plan is empty.');
        }

        $keys = [];

        $normalized = [];

        foreach ($items as $item) {
            $acceptedCost = $item['accepted_cost_minor_units'] ?? 0;

            if ($item['item_key'] === '' || $item['forecast_seconds'] < 1 || $item['forecast_seconds'] > self::MaxForecastSeconds) {
                throw new RuntimeException('Historic import checkpoint item is invalid or exceeds twelve forecast hours.');
            }

            if ($acceptedCost < 0 || isset($keys[$item['item_key']])) {
                throw new RuntimeException('Historic import checkpoint item keys and cost forecasts must be unambiguous.');
            }

            $keys[$item['item_key']] = true;
            $normalized[] = [
                'item_key' => $item['item_key'],
                'forecast_seconds' => $item['forecast_seconds'],
                'accepted_cost_minor_units' => $acceptedCost,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{item_key: string, forecast_seconds: int, accepted_cost_minor_units: int}>  $items
     * @return list<list<array{item_key: string, forecast_seconds: int, accepted_cost_minor_units: int}>>
     */
    private function partitions(array $items): array
    {
        $partitions = [];
        $current = [];
        $seconds = 0;

        foreach ($items as $item) {
            if ($current !== [] && (count($current) >= self::MaxItems || $seconds + $item['forecast_seconds'] > self::MaxForecastSeconds)) {
                $partitions[] = $current;
                $current = [];
                $seconds = 0;
            }

            $current[] = $item;
            $seconds += $item['forecast_seconds'];
        }

        if ($current !== []) {
            $partitions[] = $current;
        }

        return $partitions;
    }
}
