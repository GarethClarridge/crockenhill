<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
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
 * A weekly livestream run (no operation) may be retired *into* an operation, so the
 * round re-imports its service from the manifest's source (plan §4.1a step 3b; the
 * 09-04 findings §15 found no command for it). Its item key is its identity, which is
 * the manifest's own key. Its published sermon is not withdrawn: it stays live,
 * recorded as awaiting replacement, until release swaps the re-import's cut into it
 * (R11, amended 10-31). An unpublished one is withdrawn as a historic run's is.
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
     *     lane: string,
     *     status_now: string,
     *     sermon: ?Sermon,
     *     keeps_sermon: bool,
     *     assets: list<array{role:string, disk:string, path:string, bytes:int}>,
     *     sections: int,
     *     published_sections: list<int>,
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

            $weekly = $run->historic_import_operation_id === null;
            $weeklyItemKey = $weekly ? $this->weeklyItemKey($run) : null;

            if (! $weekly && $run->historic_import_operation_id !== $operation->id) {
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
            $keepsSermon = $weekly && $sermon?->publication_state === SermonPublicationState::Published;

            $entries[] = [
                'run' => $run,
                'item_key' => $weeklyItemKey
                    ?? (string) (data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key') ?? '(unknown)'),
                'lane' => $weekly ? 'weekly' : 'historic',
                'status_now' => $run->status->value,
                'sermon' => $sermon,
                'keeps_sermon' => $keepsSermon,
                'assets' => $sermon instanceof Sermon && ! $keepsSermon ? $this->sermonWithdrawal->assetsFor($sermon) : [],
                'sections' => ServiceSection::query()->where('media_processing_log_id', $run->id)->count(),
                'published_sections' => array_values(array_map('intval', ServiceSection::query()
                    ->where('media_processing_log_id', $run->id)
                    ->where('publication_status', ServiceSectionPublicationStatus::Published)
                    ->orderBy('id')
                    ->pluck('id')
                    ->all())),
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
     *     lane: string,
     *     status_now: string,
     *     sermon: ?Sermon,
     *     keeps_sermon: bool,
     *     assets: list<array{role:string, disk:string, path:string, bytes:int}>,
     *     sections: int,
     *     published_sections: list<int>,
     *     already_retired: bool,
     *     already_superseded: bool
     * }>  $entries
     * @return array{retired: int, already_retired: int, sermons_deleted: int, sermons_kept: int, assets_relocated: int}
     */
    public function apply(HistoricImportOperation $operation, array $entries, string $note): array
    {
        if (trim($note) === '') {
            throw new RuntimeException('A retirement must carry the operator note that justifies it.');
        }

        $retired = 0;
        $alreadyRetired = 0;
        $sermonsDeleted = 0;
        $sermonsKept = 0;
        $assetsRelocated = 0;

        foreach ($entries as $entry) {
            $run = $entry['run'];

            if ($run->retirementRecord() !== null) {
                $alreadyRetired++;

                continue;
            }

            $sermon = $entry['sermon'];
            $withdrawn = $entry['keeps_sermon'] ? null : $sermon;
            $moved = [];

            if ($withdrawn instanceof Sermon) {
                $this->sermonWithdrawal->guardWithdrawable($sermon);
                $moved = $this->sermonWithdrawal->relocate($sermon, $operation, $entry['assets']);
            }

            try {
                DB::transaction(function () use ($run, $operation, $sermon, $withdrawn, $entry, $note, $moved): void {
                    $run->putRetirement([
                        'recorded_by' => 'operator',
                        'note' => $note,
                        'lane' => $entry['lane'],
                        'operation_id' => $operation->operation_id,
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
                            'awaiting_replacement' => $entry['keeps_sermon'],
                        ] : null,
                        'published_sections' => $entry['published_sections'],
                        'assets' => $moved,
                    ]);

                    $withdrawn?->delete();

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

            if ($withdrawn instanceof Sermon) {
                $this->sermonWithdrawal->writeInventory($operation, $run, $withdrawn, $moved, 'retirement.json', [
                    'retired_at' => now()->toIso8601String(),
                ]);
            }

            $retired++;
            $assetsRelocated += count($moved);

            if ($withdrawn instanceof Sermon) {
                $sermonsDeleted++;
            } elseif ($sermon instanceof Sermon) {
                $sermonsKept++;
            }
        }

        return [
            'retired' => $retired,
            'already_retired' => $alreadyRetired,
            'sermons_deleted' => $sermonsDeleted,
            'sermons_kept' => $sermonsKept,
            'assets_relocated' => $assetsRelocated,
        ];
    }

    /**
     * A weekly run is retired into a round only as a livestream with a service identity:
     * the identity is the manifest item the round re-imports, keyed as the manifest keys it.
     */
    private function weeklyItemKey(MediaProcessingLog $run): string
    {
        if ($run->processing_type !== MediaType::Livestream) {
            throw new RuntimeException("Run [{$run->processing_id}] is not a livestream run; only a livestream service can be re-imported.");
        }

        if ($run->extracted_date === null || $run->extracted_service === null) {
            throw new RuntimeException("Run [{$run->processing_id}] has no date and service, so no manifest item can re-import it.");
        }

        return $run->extracted_date->toDateString().'-'.$run->extracted_service->value;
    }

    private function sermonFor(MediaProcessingLog $run): ?Sermon
    {
        if ($run->sermon_id === null) {
            return null;
        }

        return Sermon::query()->find($run->sermon_id);
    }
}
