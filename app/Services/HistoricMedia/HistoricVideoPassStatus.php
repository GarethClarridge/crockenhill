<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;

class HistoricVideoPassStatus
{
    /**
     * @param  list<string>  $itemKeys
     * @return list<array{
     *     item_key:string,
     *     disposition:string,
     *     processing_ids:list<string>,
     *     stages:list<string>
     * }>
     */
    public function report(HistoricImportOperation $operation, array $itemKeys): array
    {
        $runsByItemKey = [];

        MediaProcessingLog::query()
            ->where('historic_import_operation_id', $operation->id)
            ->orderBy('id')
            ->get([
                'processing_id',
                'status',
                'current_step',
                'processing_metadata',
            ])
            ->each(function (MediaProcessingLog $run) use (&$runsByItemKey): void {
                $itemKey = $this->manifestItemKey($run);

                if ($itemKey !== null) {
                    $runsByItemKey[$itemKey][] = $run;
                }
            });

        $status = [];

        foreach ($itemKeys as $itemKey) {
            /** @var list<MediaProcessingLog> $runs */
            $runs = $runsByItemKey[$itemKey] ?? [];
            $status[] = [
                'item_key' => $itemKey,
                'disposition' => $this->disposition($runs),
                'processing_ids' => array_map(
                    static fn (MediaProcessingLog $run): string => $run->processing_id,
                    $runs,
                ),
                'stages' => $this->stages($runs),
            ];
        }

        return $status;
    }

    private function manifestItemKey(MediaProcessingLog $run): ?string
    {
        $itemKey = data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key');

        return is_string($itemKey) && $itemKey !== '' ? $itemKey : null;
    }

    /** @param list<MediaProcessingLog> $runs
     * @return list<string>
     */
    private function stages(array $runs): array
    {
        $stages = array_filter(
            array_map(static fn (MediaProcessingLog $run): ?string => $run->current_step, $runs),
            static fn (?string $step): bool => is_string($step) && $step !== '',
        );

        return array_values(array_unique($stages));
    }

    /** @param list<MediaProcessingLog> $runs */
    private function disposition(array $runs): string
    {
        if ($runs === []) {
            return 'not_dispatched';
        }

        if (collect($runs)->contains(
            static fn (MediaProcessingLog $run): bool => in_array($run->status, [
                ProcessingStatus::Pending,
                ProcessingStatus::Started,
                ProcessingStatus::Processing,
            ], true),
        )) {
            return 'in_progress';
        }

        $manualReviewRuns = collect($runs)->filter(
            static fn (MediaProcessingLog $run): bool => $run->status === ProcessingStatus::Failed
                && $run->current_step === 'manual_review_required',
        );

        if ($manualReviewRuns->isNotEmpty() && $manualReviewRuns->count() !== count($runs)) {
            return 'mixed_terminal';
        }

        if ($manualReviewRuns->isNotEmpty()) {
            return 'manual_review';
        }

        $statuses = collect($runs)->pluck('status')->unique();

        if ($statuses->count() > 1) {
            return 'mixed_terminal';
        }

        return match ($statuses->sole()) {
            ProcessingStatus::Completed => 'completed',
            ProcessingStatus::Skipped => 'skipped',
            ProcessingStatus::Cancelled => 'cancelled',
            ProcessingStatus::Failed => 'failed',
            default => 'in_progress',
        };
    }
}
