<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportCheckpoint;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportUsageEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricImportCostLedger
{
    public function __construct(
        private readonly HistoricImportJournal $journal,
    ) {}

    public function record(
        HistoricImportCheckpoint $checkpoint,
        string $requestKey,
        string $itemKey,
        string $provider,
        string $model,
        int $costMinorUnits,
        int $calls = 1,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $audioSeconds = 0,
        string $currency = 'GBP',
    ): HistoricImportUsageEntry {
        if ($requestKey === '' || $provider === '' || $model === '' || $costMinorUnits < 0
            || min($calls, $inputTokens, $outputTokens, $audioSeconds) < 0
            || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new RuntimeException('Historic import usage entry is invalid.');
        }

        return DB::transaction(function () use (
            $checkpoint,
            $requestKey,
            $itemKey,
            $provider,
            $model,
            $costMinorUnits,
            $calls,
            $inputTokens,
            $outputTokens,
            $audioSeconds,
            $currency,
        ): HistoricImportUsageEntry {
            $checkpoint = HistoricImportCheckpoint::query()->whereKey($checkpoint->id)->lockForUpdate()->firstOrFail();
            $operation = HistoricImportOperation::query()
                ->whereKey($checkpoint->historic_import_operation_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($itemKey, $checkpoint->item_keys, true)) {
                throw new RuntimeException('Historic import usage belongs to an item outside checkpoint membership.');
            }

            $existing = $operation->usageEntries()->where('request_key', $requestKey)->first();

            if ($existing instanceof HistoricImportUsageEntry) {
                $this->assertIdempotent($existing, $checkpoint, $itemKey, $provider, $model, $costMinorUnits, $currency);

                return $existing;
            }

            $checkpointTotal = (int) $checkpoint->usageEntries()->sum('cost_minor_units');
            $operationTotal = (int) $operation->usageEntries()->sum('cost_minor_units');

            if ($checkpointTotal + $costMinorUnits > $checkpoint->accepted_cost_minor_units) {
                throw new RuntimeException('Historic import checkpoint cost threshold would be exceeded.');
            }

            if ($operationTotal + $costMinorUnits > $operation->max_cost_minor_units) {
                throw new RuntimeException('Historic import operation cost threshold would be exceeded.');
            }

            $entry = HistoricImportUsageEntry::query()->create([
                'historic_import_operation_id' => $operation->id,
                'historic_import_checkpoint_id' => $checkpoint->id,
                'request_key' => $requestKey,
                'item_key' => $itemKey,
                'provider' => $provider,
                'model' => $model,
                'calls' => $calls,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'audio_seconds' => $audioSeconds,
                'cost_minor_units' => $costMinorUnits,
                'currency' => $currency,
                'recorded_at' => now(),
            ]);

            $this->journal->append($operation, 'usage_recorded', [
                'request_key' => $requestKey,
                'item_key' => $itemKey,
                'provider' => $provider,
                'model' => $model,
                'calls' => $calls,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'audio_seconds' => $audioSeconds,
                'cost_minor_units' => $costMinorUnits,
                'currency' => $currency,
                'checkpoint_total_minor_units' => $checkpointTotal + $costMinorUnits,
                'operation_total_minor_units' => $operationTotal + $costMinorUnits,
            ], $checkpoint);

            return $entry;
        });
    }

    private function assertIdempotent(
        HistoricImportUsageEntry $existing,
        HistoricImportCheckpoint $checkpoint,
        string $itemKey,
        string $provider,
        string $model,
        int $costMinorUnits,
        string $currency,
    ): void {
        if ($existing->historic_import_checkpoint_id !== $checkpoint->id
            || $existing->item_key !== $itemKey
            || $existing->provider !== $provider
            || $existing->model !== $model
            || $existing->cost_minor_units !== $costMinorUnits
            || $existing->currency !== $currency) {
            throw new RuntimeException('Historic import request key was reused with different usage facts.');
        }
    }
}
