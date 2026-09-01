<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Models\HistoricImportAlert;
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
        $runsByItemKey = $this->runsByItemKey($operation, $itemKeys);

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

    /**
     * Historic alerts scoped to this pass's runs, grouped by kind for a
     * count-level summary and listed per identity for the operator to read
     * the reason without hand-diagnosing it. Alerts are the only durable
     * record for a historic run: external notifications are disabled by
     * construction, so this is their first reader.
     *
     * @param  list<string>  $itemKeys
     * @return array{
     *     by_kind: list<array{kind:string, severity:string, count:int}>,
     *     items: list<array{item_key:string, kind:string, severity:string, reason:string, recorded_at:string}>
     * }
     */
    public function alerts(HistoricImportOperation $operation, array $itemKeys): array
    {
        $runsByItemKey = $this->runsByItemKey($operation, $itemKeys);

        $itemKeyByLogId = [];
        foreach ($runsByItemKey as $itemKey => $runs) {
            foreach ($runs as $run) {
                $itemKeyByLogId[$run->id] = $itemKey;
            }
        }

        if ($itemKeyByLogId === []) {
            return ['by_kind' => [], 'items' => []];
        }

        $alerts = HistoricImportAlert::query()
            ->where('historic_import_operation_id', $operation->id)
            ->whereIn('media_processing_log_id', array_keys($itemKeyByLogId))
            ->orderBy('recorded_at')
            ->get();

        $counts = [];
        foreach ($alerts as $alert) {
            $groupKey = $alert->kind.'|'.$alert->severity;
            $counts[$groupKey] ??= ['kind' => $alert->kind, 'severity' => $alert->severity, 'count' => 0];
            $counts[$groupKey]['count']++;
        }
        ksort($counts);

        $items = [];
        foreach ($alerts as $alert) {
            $items[] = [
                'item_key' => $itemKeyByLogId[(int) $alert->media_processing_log_id] ?? '(unknown)',
                'kind' => $alert->kind,
                'severity' => $alert->severity,
                'reason' => $this->alertReason($alert),
                'recorded_at' => $alert->recorded_at->toIso8601String(),
            ];
        }

        return ['by_kind' => array_values($counts), 'items' => $items];
    }

    /**
     * @param  list<string>  $itemKeys
     * @return array<string, list<MediaProcessingLog>>
     */
    private function runsByItemKey(HistoricImportOperation $operation, array $itemKeys): array
    {
        $runsByItemKey = [];

        MediaProcessingLog::query()
            ->where('historic_import_operation_id', $operation->id)
            ->orderBy('id')
            ->get([
                'id',
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

        return array_intersect_key($runsByItemKey, array_flip($itemKeys));
    }

    /**
     * The most diagnostic text an alert carries, for the operator report.
     *
     * Failure alerts hold the real cause in `internal_message` and only a
     * sanitised placeholder in `message`; manual-review alerts hold `reason`.
     * Reading `reason` alone left every failure alert printing a bare
     * `failure`, which is the opposite of what this reader exists for.
     */
    private function alertReason(HistoricImportAlert $alert): string
    {
        $facts = $alert->payload['facts'] ?? null;
        $facts = is_array($facts) ? $facts : [];

        if ($alert->kind === 'excluded_source_audio_silent') {
            return sprintf(
                'source audio is digitally silent (%d frames, all -inf)',
                (int) ($facts['frame_count'] ?? 0),
            );
        }

        foreach (['internal_message', 'reason', 'message'] as $key) {
            $value = $facts[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $alert->kind;
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

        // Reported ahead of the plain status match below: an excluded run's
        // ProcessingStatus is Completed (there was nothing to extract, so it
        // reached the same terminal disposition as any other successful
        // run), and only the exclusion metadata distinguishes it. Without
        // this branch it would report as 'completed' and the operator would
        // never learn the source recording was silent.
        $excludedRuns = collect($runs)->filter(
            static fn (MediaProcessingLog $run): bool => $run->status === ProcessingStatus::Completed
                && $run->isExcludedSilentAudio(),
        );

        if ($excludedRuns->isNotEmpty() && $excludedRuns->count() !== count($runs)) {
            return 'mixed_terminal';
        }

        if ($excludedRuns->isNotEmpty()) {
            return 'excluded';
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
