<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Models\HistoricImportAlert;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\Import\HistoricImportJournal;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessingNotificationRouter
{
    public function __construct(
        private readonly HistoricImportJournal $journal,
    ) {}

    /**
     * Persist the private fact before suppressing any historic notification.
     * Returns false for an ordinary current run.
     *
     * @param  array<string, mixed>  $facts
     */
    public function suppressIfHistoric(
        MediaProcessingLog $processingLog,
        string $kind,
        string $severity,
        array $facts,
    ): bool {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $historicMetadata = $metadata['historic_import'] ?? null;
        $operationId = $processingLog->historic_import_operation_id;

        if (is_array($historicMetadata) && $operationId === null) {
            throw new RuntimeException('Historic processing notification has no immutable operation binding.');
        }

        if ($operationId === null) {
            return false;
        }

        $operation = HistoricImportOperation::query()->find($operationId);

        if (! $operation instanceof HistoricImportOperation
            || $operation->notification_mode !== 'external_disabled') {
            throw new RuntimeException('Historic processing notification isolation binding is invalid.');
        }

        $metadataOperationId = is_array($historicMetadata) ? ($historicMetadata['operation_id'] ?? null) : null;

        if ($metadataOperationId !== null && $metadataOperationId !== $operation->operation_id) {
            throw new RuntimeException('Historic processing metadata and operation binding disagree.');
        }

        $safeFacts = [
            'processing_id' => $processingLog->processing_id,
            'kind' => $kind,
            'severity' => $severity,
            'facts' => $facts,
        ];
        $alertKey = $kind.'-'.CanonicalJson::hash($safeFacts);

        DB::transaction(function () use ($operation, $processingLog, $alertKey, $kind, $severity, $safeFacts): void {
            $existing = HistoricImportAlert::query()
                ->where('historic_import_operation_id', $operation->id)
                ->where('alert_key', $alertKey)
                ->first();

            if ($existing instanceof HistoricImportAlert) {
                return;
            }

            HistoricImportAlert::query()->create([
                'historic_import_operation_id' => $operation->id,
                'media_processing_log_id' => $processingLog->id,
                'alert_key' => $alertKey,
                'kind' => $kind,
                'severity' => $severity,
                'payload' => $safeFacts,
                'recorded_at' => now(),
            ]);

            $this->journal->append($operation, 'notification_suppressed', [
                'alert_key' => $alertKey,
                ...$safeFacts,
            ]);
        });

        return true;
    }
}
