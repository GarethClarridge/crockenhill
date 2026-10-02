<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\ServiceSermonAbsence;
use App\Enums\ChurchServiceSource;
use App\Jobs\AnalyzeSegments;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\SourceAdapters\LivestreamSourceAdapter;
use App\Services\Import\HistoricReleaseReviewHolds;
use App\Services\Processing\ProcessingNotificationRouter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record an operator's decision that a historic run is excluded, so the run holds
 * a truthful terminal disposition instead of sitting in a review hold nobody is
 * coming back to.
 *
 * Only reasons a person can establish belong here. A silent source is detected
 * from the audio and recorded by {@see AnalyzeSegments} without anyone looking.
 *
 * "This recording holds no sermon" used to be written here and nowhere else, on
 * the grounds that it could not be detected at all. That stopped being true when
 * the LLM-first structure pipeline went `primary`: the projection reads content,
 * and it can now say so in a structured way that the run honours
 * ({@see ServiceSermonAbsence}). Two claims that sound alike have to
 * be kept apart (D1, 2026-09-03):
 *
 *  - *This service held no sermon* — a mission presentation, a carol service. A
 *    real service with real sections, and the detector proposes it while an
 *    operator confirms the occasion. Excluding it would be wrong: exclusion is
 *    terminal and would discard the sections and the church service that are the
 *    whole point of importing it.
 *  - *This recording holds no sermon* — the camera caught a different part of
 *    the service, or too little of it. That is a fact about the capture, not
 *    about the occasion, and it is still only establishable by a person who has
 *    watched the recording. That reason is recorded here.
 *
 * Two further reasons were ruled on 2026-09-14, and neither is "this service held
 * no sermon" either:
 *
 *  - *This recording rehearses a sermon another run carries* — a Saturday take of
 *    Sunday's sermon. The rehearsal's sermon is a real sermon, but a second copy.
 *    The kept run is named and must itself stand, so the only copy is never the
 *    one excluded.
 *  - *This is a private occasion* — a funeral. A real, correctly dated service
 *    whose sermon and songs do not belong in the public archive.
 *
 * Neither would have been uploaded had the week been processed by hand, so both
 * also remove the service the run created — but only a service that run alone
 * describes. A service with another source or another run is a real service
 * record and is refused, never deleted.
 *
 * Exclusion does not withdraw the sermon or song videos a run created. Release
 * refuses them instead ({@see HistoricReleaseReviewHolds}),
 * and the quarantined bytes stay where they are.
 *
 * Deletion trigger: Delete once the historic import operation is closed out and
 * no further exclusion decisions can be recorded against it.
 */
class HistoricRunExclusion
{
    /** Reasons an operator may record. Silent audio is excluded: the pipeline owns it. */
    public const OPERATOR_REASONS = [
        MediaProcessingLog::EXCLUSION_REASON_NO_SERMON_IN_SOURCE,
        MediaProcessingLog::EXCLUSION_REASON_REHEARSAL_DUPLICATE,
        MediaProcessingLog::EXCLUSION_REASON_PRIVATE_OCCASION,
    ];

    /**
     * Reasons whose ruling is that the occasion would never have been uploaded, so
     * the service the run created goes with it (operator ruling 2026-09-15).
     */
    private const SERVICE_REMOVING_REASONS = [
        MediaProcessingLog::EXCLUSION_REASON_REHEARSAL_DUPLICATE,
        MediaProcessingLog::EXCLUSION_REASON_PRIVATE_OCCASION,
    ];

    public function __construct(
        private readonly ProcessingNotificationRouter $notificationRouter,
    ) {}

    /**
     * Resolve the runs named by these processing IDs and report what excluding
     * them would do, without writing anything.
     *
     * @param  list<string>  $processingIds
     * @return list<array{run: MediaProcessingLog, item_key: string, disposition_now: string, already_excluded: bool, duplicates: ?MediaProcessingLog, removes_service: ?ChurchService}>
     */
    public function inspect(
        HistoricImportOperation $operation,
        array $processingIds,
        string $reason,
        ?string $duplicatesProcessingId = null,
    ): array {
        $this->guardReason($reason);

        $duplicates = $this->keptRun($operation, $processingIds, $reason, $duplicatesProcessingId);
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
                'duplicates' => $duplicates,
                'removes_service' => in_array($reason, self::SERVICE_REMOVING_REASONS, true)
                    ? $this->removableService($run)
                    : null,
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
     * @param  list<array{run: MediaProcessingLog, item_key: string, disposition_now: string, already_excluded: bool, duplicates: ?MediaProcessingLog, removes_service: ?ChurchService}>  $entries
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
                $service = $entry['removes_service'];

                $run->putExclusion($reason, array_filter([
                    'recorded_by' => 'operator',
                    'note' => $note,
                    'manifest_item_key' => $entry['item_key'],
                    'status_when_excluded' => $run->status->value,
                    'step_when_excluded' => $run->current_step,
                    'duplicates_processing_id' => $entry['duplicates']?->processing_id,
                    'removed_service' => $service instanceof ChurchService ? [
                        'id' => $service->id,
                        'date' => $service->date->toDateString(),
                        'service' => $service->service->value,
                        'occasion' => $service->occasion,
                        'items' => $service->items()->count(),
                        'source_record_ids' => $service->sourceRecords()->orderBy('id')->pluck('id')->all(),
                    ] : null,
                ], static fn (mixed $value): bool => $value !== null));

                // After the exclusion is written: the foreign key clears the run's
                // service link, and the cascade takes the items and source records.
                $service?->delete();

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

    /**
     * The run a rehearsal duplicates. It is required for that reason and refused
     * for every other, and it must be a different run of the same operation that
     * still stands — excluding a rehearsal whose kept run is gone would discard
     * the sermon's only copy.
     *
     * @param  list<string>  $processingIds
     */
    private function keptRun(
        HistoricImportOperation $operation,
        array $processingIds,
        string $reason,
        ?string $duplicatesProcessingId,
    ): ?MediaProcessingLog {
        $isRehearsal = $reason === MediaProcessingLog::EXCLUSION_REASON_REHEARSAL_DUPLICATE;

        if ($duplicatesProcessingId === null) {
            if ($isRehearsal) {
                throw new RuntimeException('A rehearsal exclusion must name the run whose sermon it duplicates with --duplicates.');
            }

            return null;
        }

        if (! $isRehearsal) {
            throw new RuntimeException(sprintf(
                '--duplicates applies only to [%s], not [%s].',
                MediaProcessingLog::EXCLUSION_REASON_REHEARSAL_DUPLICATE,
                $reason,
            ));
        }

        if (in_array($duplicatesProcessingId, $processingIds, true)) {
            throw new RuntimeException("Run [{$duplicatesProcessingId}] cannot be both excluded and the run it duplicates.");
        }

        $kept = MediaProcessingLog::query()
            ->where('processing_id', $duplicatesProcessingId)
            ->where('historic_import_operation_id', $operation->id)
            ->first();

        if (! $kept instanceof MediaProcessingLog) {
            throw new RuntimeException("No run [{$duplicatesProcessingId}] exists in operation [{$operation->operation_id}].");
        }

        if ($kept->isExcluded()) {
            throw new RuntimeException("Run [{$duplicatesProcessingId}] is itself excluded, so it cannot be the copy that is kept.");
        }

        return $kept;
    }

    /**
     * The service this run created, when nothing but this run describes it. The
     * historic import manufactures a service from the run's own livestream record,
     * so that is the one shape removal accepts; anything else is refused.
     */
    private function removableService(MediaProcessingLog $run): ?ChurchService
    {
        if ($run->church_service_id === null) {
            return null;
        }

        $service = ChurchService::query()->find($run->church_service_id);

        if (! $service instanceof ChurchService) {
            return null;
        }

        $ownKey = $run->processing_id.'|v'.LivestreamSourceAdapter::FORMAT_VERSION;
        $describedElsewhere = $service->sourceRecords()
            ->get(['id', 'source', 'source_key'])
            ->contains(static fn (ChurchServiceSourceRecord $record): bool => $record->source !== ChurchServiceSource::Livestream
                || $record->source_key !== $ownKey);

        if ($describedElsewhere) {
            throw new RuntimeException(
                "Service [{$service->id}] is described by another source as well as run [{$run->processing_id}], so it cannot be removed with it."
            );
        }

        $sharedWithAnotherRun = MediaProcessingLog::query()
            ->where('church_service_id', $service->id)
            ->whereKeyNot($run->id)
            ->exists();

        if ($sharedWithAnotherRun) {
            throw new RuntimeException(
                "Service [{$service->id}] belongs to another run as well as run [{$run->processing_id}], so it cannot be removed with it."
            );
        }

        return $service;
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
