<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Jobs\AnalyzeSegments;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingNotificationRouter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record an operator's decision that a historic run is excluded, so the run holds
 * a truthful terminal disposition instead of sitting in a review hold nobody is
 * coming back to.
 *
 * Only reasons a person can establish belong here. A silent source is detected
 * from the audio and recorded by {@see AnalyzeSegments} without anyone
 * looking; "this recording holds no sermon" cannot be detected at all — it needs
 * someone to watch the recording — so it is written here and nowhere else.
 *
 * Deletion trigger: Delete once the historic import operation is closed out and
 * no further exclusion decisions can be recorded against it.
 */
class HistoricRunExclusion
{
    /** Reasons an operator may record. Silent audio is excluded: the pipeline owns it. */
    public const OPERATOR_REASONS = [
        MediaProcessingLog::EXCLUSION_REASON_NO_SERMON_IN_SOURCE,
    ];

    public function __construct(
        private readonly ProcessingNotificationRouter $notificationRouter,
    ) {}

    /**
     * Resolve the runs named by these processing IDs and report what excluding
     * them would do, without writing anything.
     *
     * @param  list<string>  $processingIds
     * @return list<array{run: MediaProcessingLog, item_key: string, disposition_now: string, already_excluded: bool}>
     */
    public function inspect(HistoricImportOperation $operation, array $processingIds, string $reason): array
    {
        $this->guardReason($reason);

        $entries = [];

        foreach ($processingIds as $processingId) {
            $run = MediaProcessingLog::query()
                ->where('processing_id', $processingId)
                ->first();

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException("No processing run exists for [{$processingId}].");
            }

            if ($run->historic_import_operation_id !== $operation->id) {
                throw new RuntimeException("Run [{$processingId}] does not belong to operation [{$operation->operation_id}].");
            }

            $metadataOperationId = data_get($run->processing_metadata?->toArray(), 'historic_import.operation_id');

            if ($metadataOperationId !== null && $metadataOperationId !== $operation->operation_id) {
                throw new RuntimeException("Run [{$processingId}] records a different owning operation than the one named.");
            }

            if ($run->isExcludedSilentAudio()) {
                throw new RuntimeException(
                    "Run [{$processingId}] is already excluded as a silent source; the pipeline owns that decision."
                );
            }

            $entries[] = [
                'run' => $run,
                'item_key' => (string) (data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key') ?? '(unknown)'),
                'disposition_now' => $this->runDisposition($run),
                'already_excluded' => $run->isExcluded(),
            ];
        }

        if ($entries === []) {
            throw new RuntimeException('No processing run was named.');
        }

        return $entries;
    }

    /**
     * Write the exclusion for each inspected run, together with the alert that
     * makes the reason readable in the pass report. Re-running is a no-op for a
     * run already excluded under the same reason.
     *
     * @param  list<array{run: MediaProcessingLog, item_key: string, disposition_now: string, already_excluded: bool}>  $entries
     * @return array{excluded: int, already_excluded: int}
     */
    public function apply(HistoricImportOperation $operation, array $entries, string $reason, string $note): array
    {
        $this->guardReason($reason);

        if (trim($note) === '') {
            throw new RuntimeException('An exclusion must carry the operator note that justifies it.');
        }

        $excluded = 0;
        $alreadyExcluded = 0;

        foreach ($entries as $entry) {
            $run = $entry['run'];

            if ($run->exclusionReason() === $reason) {
                $alreadyExcluded++;

                continue;
            }

            DB::transaction(function () use ($run, $operation, $reason, $note, $entry): void {
                $run->putExclusion($reason, [
                    'recorded_by' => 'operator',
                    'note' => $note,
                    'manifest_item_key' => $entry['item_key'],
                    'status_when_excluded' => $run->status->value,
                    'step_when_excluded' => $run->current_step,
                ]);

                $this->notificationRouter->suppressIfHistoric(
                    $run->fresh() ?? $run,
                    'excluded_'.$reason,
                    'warning',
                    [
                        'reason' => $note,
                        'manifest_item_key' => $entry['item_key'],
                        'operation_id' => $operation->operation_id,
                    ],
                );
            });

            $excluded++;
        }

        return ['excluded' => $excluded, 'already_excluded' => $alreadyExcluded];
    }

    /**
     * @phpstan-assert value-of<self::OPERATOR_REASONS> $reason
     */
    private function guardReason(string $reason): void
    {
        if (! in_array($reason, self::OPERATOR_REASONS, true)) {
            throw new RuntimeException(sprintf(
                'Reason [%s] is not one an operator may record. Available: %s.',
                $reason,
                implode(', ', self::OPERATOR_REASONS),
            ));
        }
    }

    private function runDisposition(MediaProcessingLog $run): string
    {
        if ($run->isExcluded()) {
            return 'excluded';
        }

        if ($run->current_step === 'manual_review_required') {
            return 'manual_review';
        }

        return $run->status->value;
    }
}
