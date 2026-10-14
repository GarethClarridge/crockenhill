<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\Processing\ProcessingNotificationRouter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Retire a historic run whose result is superseded by a better source, so the
 * identity becomes dispatchable again and nothing left behind still asserts the
 * retired result.
 *
 * This is the counterpart to {@see HistoricRunExclusion}, and the distinction is
 * about the *recording*, not the run. An exclusion says "no run of this source
 * can ever succeed" and is terminal. A retirement says "this run's result is
 * withdrawn because the source it read has been replaced" — the identity is
 * expected to be processed again from the new source.
 *
 * Retirement uses the supersession the schema already carries
 * (`media_processing_logs.superseded_at`), which {@see ServiceSection::scopeVisible()}
 * and the review dashboard already honour, so a retired run's sections drop out
 * of every reader without a second mechanism. The sermon needs explicit work:
 * it has no supersession state, so its assets are relocated under
 * `superseded/{operation}/{sermon}/` on the same disk and its row is deleted,
 * leaving every existing sermon query correct with no change.
 *
 * Deletion trigger: Delete once the historic import operation is closed out and
 * no further source replacements can arrive.
 */
class HistoricRunRetirement
{
    public function __construct(
        private readonly ProcessingNotificationRouter $notificationRouter,
        private readonly HistoricSermonWithdrawal $sermonWithdrawal,
    ) {}

    /**
     * Resolve the runs named by these processing IDs and report what retiring
     * them would do, without writing anything or moving a byte.
     *
     * @param  list<string>  $processingIds
     * @return list<array{
     *     run: MediaProcessingLog,
     *     item_key: string,
     *     status_now: string,
     *     sermon: ?Sermon,
     *     assets: list<array{role:string, disk:string, path:string, bytes:int}>,
     *     sections: int,
     *     already_retired: bool,
     *     already_superseded: bool
     * }>
     */
    public function inspect(HistoricImportOperation $operation, array $processingIds): array
    {
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

            // Retiring live work is a race: the jobs still hold the run and will
            // keep writing to rows this method is about to withdraw. Stop the
            // run first, then retire it.
            if (in_array($run->status, [
                ProcessingStatus::Pending,
                ProcessingStatus::Started,
                ProcessingStatus::Processing,
            ], true)) {
                throw new RuntimeException(
                    "Run [{$processingId}] is still in flight ({$run->status->value}); stop it before retiring it."
                );
            }

            $sermon = $this->sermonFor($run);

            $entries[] = [
                'run' => $run,
                'item_key' => (string) (data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key') ?? '(unknown)'),
                'status_now' => $run->status->value,
                'sermon' => $sermon,
                'assets' => $sermon instanceof Sermon ? $this->sermonWithdrawal->assetsFor($sermon) : [],
                'sections' => ServiceSection::query()->where('media_processing_log_id', $run->id)->count(),
                // A run superseded by another path — the projector reconciling
                // duplicate runs against one service — is withdrawn but carries no
                // operator disposition. It is not yet retired; it still needs one.
                'already_retired' => $run->retirementRecord() !== null,
                'already_superseded' => $run->superseded_at !== null,
            ];
        }

        if ($entries === []) {
            throw new RuntimeException('No processing run was named.');
        }

        return $entries;
    }

    /**
     * Retire each inspected run: relocate its sermon's assets, delete the sermon
     * row, mark the run superseded and record the inventory that says what was
     * withdrawn. The retirement *record* is what makes a run retired, not the
     * supersession: a run some other path already superseded still carries no
     * operator disposition, and the Phase 8 exit gate wants one. Re-running is a
     * no-op only once that record exists.
     *
     * Assets move before the database changes and are moved back if the
     * transaction fails, because the sermon row is the only thing naming where
     * they came from — losing it while the files sit at their new paths would
     * leave orphans nothing can identify.
     *
     * @param  list<array{
     *     run: MediaProcessingLog,
     *     item_key: string,
     *     status_now: string,
     *     sermon: ?Sermon,
     *     assets: list<array{role:string, disk:string, path:string, bytes:int}>,
     *     sections: int,
     *     already_retired: bool,
     *     already_superseded: bool
     * }>  $entries
     * @return array{retired: int, already_retired: int, sermons_deleted: int, assets_relocated: int}
     */
    public function apply(HistoricImportOperation $operation, array $entries, string $note): array
    {
        if (trim($note) === '') {
            throw new RuntimeException('A retirement must carry the operator note that justifies it.');
        }

        $retired = 0;
        $alreadyRetired = 0;
        $sermonsDeleted = 0;
        $assetsRelocated = 0;

        foreach ($entries as $entry) {
            $run = $entry['run'];

            if ($run->retirementRecord() !== null) {
                $alreadyRetired++;

                continue;
            }

            $sermon = $entry['sermon'];
            $moved = [];

            if ($sermon instanceof Sermon) {
                $this->sermonWithdrawal->guardWithdrawable($sermon);
                $moved = $this->sermonWithdrawal->relocate($sermon, $operation, $entry['assets']);
            }

            try {
                DB::transaction(function () use ($run, $operation, $sermon, $entry, $note, $moved): void {
                    $run->putRetirement([
                        'recorded_by' => 'operator',
                        'note' => $note,
                        'manifest_item_key' => $entry['item_key'],
                        'status_when_retired' => $entry['status_now'],
                        'superseded_before_retirement' => $entry['already_superseded'],
                        'step_when_retired' => $run->current_step,
                        'sections_withdrawn' => $entry['sections'],
                        'sermon' => $sermon instanceof Sermon ? [
                            'id' => $sermon->id,
                            'slug' => $sermon->slug,
                            'title' => $sermon->title,
                            'duration' => $sermon->duration,
                            'asset_disk' => $sermon->asset_disk,
                            'publication_state' => $sermon->publication_state->value,
                        ] : null,
                        'assets' => $moved,
                    ]);

                    $sermon?->delete();

                    // A run another path already superseded keeps the moment it
                    // was withdrawn; only the operator's disposition is new.
                    $run->forceFill(['superseded_at' => $run->superseded_at ?? now()])->save();

                    $this->notificationRouter->suppressIfHistoric(
                        $run->fresh() ?? $run,
                        'retired_superseded_source',
                        'warning',
                        [
                            'reason' => $note,
                            'manifest_item_key' => $entry['item_key'],
                            'operation_id' => $operation->operation_id,
                            'sermon_id' => $sermon?->id,
                        ],
                    );
                });
            } catch (Throwable $exception) {
                $this->sermonWithdrawal->rollBack($moved);

                throw $exception;
            }

            if ($sermon instanceof Sermon) {
                $this->sermonWithdrawal->writeInventory($operation, $run, $sermon, $moved, 'retirement.json', [
                    'retired_at' => now()->toIso8601String(),
                ]);
            }

            $retired++;
            $assetsRelocated += count($moved);

            if ($sermon instanceof Sermon) {
                $sermonsDeleted++;
            }
        }

        return [
            'retired' => $retired,
            'already_retired' => $alreadyRetired,
            'sermons_deleted' => $sermonsDeleted,
            'assets_relocated' => $assetsRelocated,
        ];
    }

    private function sermonFor(MediaProcessingLog $run): ?Sermon
    {
        if ($run->sermon_id === null) {
            return null;
        }

        return Sermon::query()->find($run->sermon_id);
    }
}
