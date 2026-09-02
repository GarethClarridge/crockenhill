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
            /*
             * Every column `disposition()` reads must be listed here. An omitted one is not a
             * missing value but an absent attribute, so `isDegradedCompletion()` returns null
             * against its `bool` return type and the report dies rather than misreporting — which
             * is the right failure, but only because the accessor is strictly typed.
             */
            ->get([
                'id',
                'processing_id',
                'status',
                'current_step',
                'is_degraded_completion',
                'superseded_at',
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

        // A retired run's result is withdrawn, so it no longer speaks for the
        // identity. Reading it would report the identity as completed when the
        // sermon it completed has been deleted. Once every run is retired the
        // identity is waiting to be dispatched again from its replaced source;
        // where a later run exists, that run alone gives the disposition, so a
        // retire-then-reimport reads `completed` rather than `mixed_terminal`.
        $liveRuns = array_values(array_filter(
            $runs,
            static fn (MediaProcessingLog $run): bool => ! $run->isRetired(),
        ));

        if ($liveRuns === []) {
            return 'retired';
        }

        $runs = $liveRuns;

        if (collect($runs)->contains(
            static fn (MediaProcessingLog $run): bool => in_array($run->status, [
                ProcessingStatus::Pending,
                ProcessingStatus::Started,
                ProcessingStatus::Processing,
            ], true),
        )) {
            return 'in_progress';
        }

        // Tested ahead of every other terminal reading, including manual review.
        // An exclusion is a decision about the recording that supersedes whatever
        // state the run reached on its way there: a silent source reaches cleanup
        // and completes, while a run an operator excluded after review is still
        // recorded as failed at `manual_review_required`. Reading the run's own
        // status would report the first as 'completed' and the second as a review
        // still waiting for someone, and in neither case would the operator learn
        // the item had been excluded or why.
        $excludedRuns = collect($runs)->filter(
            static fn (MediaProcessingLog $run): bool => $run->isExcluded(),
        );

        if ($excludedRuns->isNotEmpty() && $excludedRuns->count() !== count($runs)) {
            return 'mixed_terminal';
        }

        if ($excludedRuns->isNotEmpty()) {
            return 'excluded';
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

        /*
         * A degraded completion is a distinct disposition, not a completion with a footnote. The
         * run finished, but `ProcessTranscriptWithAI` substituted `createFallbackAnalysis()` — no
         * scripture reference, no summary, placeholder points, a filename for a title — so the
         * sermon it banked contains the absence of analysis. Reported as 'completed' it is worse
         * than a failure, because a failure is retryable and this reads as done: the 2026-09-02
         * pass reported six of them as successes and nothing in any report said otherwise. Phase 8's
         * exit gate requires it not to count as completed, so the report must not call it that.
         */
        $degradedRuns = collect($runs)->filter(
            static fn (MediaProcessingLog $run): bool => $run->status === ProcessingStatus::Completed
                && $run->isDegradedCompletion(),
        );

        if ($degradedRuns->isNotEmpty() && $degradedRuns->count() !== count($runs)) {
            return 'mixed_terminal';
        }

        if ($degradedRuns->isNotEmpty()) {
            return 'degraded';
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
