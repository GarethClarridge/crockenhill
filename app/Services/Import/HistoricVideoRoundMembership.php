<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Collection;
use RuntimeException;

final class HistoricVideoRoundMembership
{
    /**
     * @param  array<string, mixed>  $expectation
     * @return array<string, mixed>
     */
    public function report(HistoricImportOperation $operation, array $expectation): array
    {
        $this->assertExpectation($operation, $expectation);

        $runs = MediaProcessingLog::query()
            ->where('processing_type', MediaType::Livestream->value)
            ->orderBy('id')
            ->get();
        $runsByIdentity = $runs->groupBy(fn (MediaProcessingLog $run): string => $this->identity($run));
        $operationRunsByItem = $runs
            ->where('historic_import_operation_id', $operation->id)
            ->groupBy(fn (MediaProcessingLog $run): string => $this->manifestItemKey($run) ?? '');
        $runsById = $runs->keyBy('id');

        $items = [];

        foreach ($expectation['items'] as $expected) {
            if (! is_array($expected)) {
                throw new RuntimeException('Historic video membership expectation contains an invalid item.');
            }

            $itemKey = $this->requiredString($expected, 'item_key');
            $date = $this->requiredString($expected, 'date');
            $service = $this->requiredString($expected, 'service');
            $items[] = $this->includedItem(
                $itemKey,
                $runsByIdentity->get("{$date}|{$service}", collect()),
                $operationRunsByItem->get($itemKey, collect()),
                $runsById,
            );
        }

        foreach ($expectation['exclusions'] as $exclusion) {
            if (! is_array($exclusion)) {
                throw new RuntimeException('Historic video membership expectation contains an invalid exclusion.');
            }

            $items[] = [
                'item_key' => $this->requiredString($exclusion, 'item_key'),
                'disposition' => 'excluded',
                'basis' => 'manifest_exclusion',
                'reason' => $this->requiredString($exclusion, 'exclusion_reason'),
                'operation_processing_ids' => [],
                'authoritative_processing_id' => null,
            ];
        }

        $counts = collect($items)->countBy('disposition')->sortKeys()->all();
        $unresolved = collect($items)
            ->where('disposition', 'unresolved')
            ->pluck('item_key')
            ->values()
            ->all();

        return [
            'format' => 'crockenhill-historic-video-round-membership',
            'version' => 1,
            'operation_id' => $operation->operation_id,
            'manifest_hash' => $expectation['manifest_hash'],
            'plan_hash' => $expectation['plan_hash'],
            'counts' => $counts,
            'items' => $items,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @param  Collection<int, MediaProcessingLog>  $identityRuns
     * @param  Collection<int, MediaProcessingLog>  $operationRuns
     * @param  Collection<int, MediaProcessingLog>  $runsById
     * @return array<string, mixed>
     */
    private function includedItem(
        string $itemKey,
        Collection $identityRuns,
        Collection $operationRuns,
        Collection $runsById,
    ): array {
        $operationProcessingIds = array_values($operationRuns
            ->map(static fn (MediaProcessingLog $run): string => $run->processing_id)
            ->all());
        $excluded = $operationRuns->filter(
            static fn (MediaProcessingLog $run): bool => $run->isExcluded(),
        );

        if ($excluded->isNotEmpty()) {
            $run = $excluded->last();

            return $this->item(
                $itemKey,
                'excluded',
                'operation_exclusion',
                $run->exclusionReason() ?? 'excluded',
                $operationProcessingIds,
                $this->authority($run, $runsById)->processing_id,
            );
        }

        $completed = $operationRuns->first(
            static fn (MediaProcessingLog $run): bool => $run->status === ProcessingStatus::Completed,
        );

        if ($completed instanceof MediaProcessingLog) {
            return $this->item(
                $itemKey,
                'complete',
                'operation_source_consumed',
                'The approved source completed under this operation; service-structure supersession does not reopen source consumption.',
                $operationProcessingIds,
                $this->authority($completed, $runsById)->processing_id,
            );
        }

        $liveCompletion = $identityRuns->last(
            static fn (MediaProcessingLog $run): bool => $run->status === ProcessingStatus::Completed
                && $run->superseded_at === null,
        );

        if ($liveCompletion instanceof MediaProcessingLog) {
            return $this->item(
                $itemKey,
                'complete',
                'preexisting_live_completion',
                'A live completed livestream already owned this service identity, so definitive dispatch correctly skipped it.',
                $operationProcessingIds,
                $liveCompletion->processing_id,
            );
        }

        $manualReview = $identityRuns->last(
            static fn (MediaProcessingLog $run): bool => $run->requiresManualSermonReview(),
        );

        if ($manualReview instanceof MediaProcessingLog) {
            $message = data_get($manualReview->processing_metadata?->toArray(), 'manual_review.reason_message');

            return $this->item(
                $itemKey,
                'unresolved',
                'manual_review',
                is_string($message) && $message !== '' ? $message : 'Manual review remains unresolved.',
                $operationProcessingIds,
                $manualReview->processing_id,
            );
        }

        return $this->item(
            $itemKey,
            'unresolved',
            'missing',
            'No completed, excluded or review-held livestream accounts for this manifest identity.',
            $operationProcessingIds,
            null,
        );
    }

    /**
     * @param  list<string>  $operationProcessingIds
     * @return array<string, mixed>
     */
    private function item(
        string $itemKey,
        string $disposition,
        string $basis,
        string $reason,
        array $operationProcessingIds,
        ?string $authoritativeProcessingId,
    ): array {
        return [
            'item_key' => $itemKey,
            'disposition' => $disposition,
            'basis' => $basis,
            'reason' => $reason,
            'operation_processing_ids' => $operationProcessingIds,
            'authoritative_processing_id' => $authoritativeProcessingId,
        ];
    }

    /** @param Collection<int, MediaProcessingLog> $runsById */
    private function authority(MediaProcessingLog $run, Collection $runsById): MediaProcessingLog
    {
        if ($run->superseded_by_processing_log_id === null) {
            return $run;
        }

        $replacement = $runsById->get((int) $run->superseded_by_processing_log_id);

        return $replacement instanceof MediaProcessingLog ? $replacement : $run;
    }

    /** @param array<string, mixed> $expectation */
    private function assertExpectation(HistoricImportOperation $operation, array $expectation): void
    {
        if (($expectation['format'] ?? null) !== 'crockenhill-historic-video-import-plan'
            || ($expectation['version'] ?? null) !== 1
            || ($expectation['batch_key'] ?? null) !== $operation->batch_key
            || ($expectation['manifest_hash'] ?? null) !== ($operation->manifest_hashes['historic_video'] ?? null)
            || ($expectation['plan_hash'] ?? null) !== $operation->plan_hash
            || ! is_array($expectation['items'] ?? null)
            || ! is_array($expectation['exclusions'] ?? null)) {
            throw new RuntimeException('Historic video membership expectation does not match the operation.');
        }
    }

    private function identity(MediaProcessingLog $run): string
    {
        $service = $run->extracted_service;

        if ($service === null) {
            return ($run->extracted_date?->toDateString() ?? '').'|';
        }

        return ($run->extracted_date?->toDateString() ?? '').'|'.$service->value;
    }

    private function manifestItemKey(MediaProcessingLog $run): ?string
    {
        $itemKey = data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key');

        return is_string($itemKey) && $itemKey !== '' ? $itemKey : null;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Historic video membership {$key} is missing.");
        }

        return $value;
    }
}
