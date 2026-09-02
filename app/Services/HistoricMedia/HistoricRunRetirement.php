<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\Processing\ProcessingNotificationRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    /** Where a retired sermon's assets are relocated, relative to its own disk. */
    private const SUPERSEDED_PREFIX = 'superseded';

    /**
     * Sermon columns naming a stored asset, keyed by the role recorded in the
     * inventory. Ordered so the largest file moves first: a failure then costs
     * the least rollback work.
     */
    private const ASSET_COLUMNS = [
        'video' => 'video_file_path',
        'audio' => 'audio_file_path',
        'transcript' => 'transcript_file_path',
        'thumbnail' => 'thumbnail_file_path',
    ];

    public function __construct(
        private readonly ProcessingNotificationRouter $notificationRouter,
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
     *     already_retired: bool
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
                'assets' => $sermon instanceof Sermon ? $this->assetsFor($sermon) : [],
                'sections' => ServiceSection::query()->where('media_processing_log_id', $run->id)->count(),
                'already_retired' => $run->superseded_at !== null,
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
     * withdrawn. Re-running is a no-op for a run already retired.
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
     *     already_retired: bool
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

            if ($run->superseded_at !== null) {
                $alreadyRetired++;

                continue;
            }

            $sermon = $entry['sermon'];
            $moved = [];

            if ($sermon instanceof Sermon) {
                $this->guardSermonIsWithdrawable($sermon);
                $moved = $this->relocateAssets($sermon, $operation, $entry['assets']);
            }

            try {
                DB::transaction(function () use ($run, $operation, $sermon, $entry, $note, $moved): void {
                    $run->putRetirement([
                        'recorded_by' => 'operator',
                        'note' => $note,
                        'manifest_item_key' => $entry['item_key'],
                        'status_when_retired' => $entry['status_now'],
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

                    $run->forceFill(['superseded_at' => now()])->save();

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
                $this->rollBackRelocation($moved);

                throw $exception;
            }

            $this->writeOnDiskInventory($operation, $run, $sermon, $moved);

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

    /**
     * A published sermon is not a historic import's to withdraw, and a sermon a
     * service section has published cannot be deleted at all — the foreign key
     * restricts it. Both are refused here so the operator reads why rather than
     * a constraint violation.
     */
    private function guardSermonIsWithdrawable(Sermon $sermon): void
    {
        if ($sermon->publication_state === SermonPublicationState::Published) {
            throw new RuntimeException(
                "Sermon [{$sermon->id}] is published; unpublish it before retiring the run that produced it."
            );
        }

        $publishedSections = ServiceSection::query()
            ->where('published_sermon_id', $sermon->id)
            ->count();

        if ($publishedSections > 0) {
            throw new RuntimeException(
                "Sermon [{$sermon->id}] is published by {$publishedSections} service section(s); withdraw those first."
            );
        }
    }

    /**
     * Move each asset to the superseded prefix, returning the inventory of what
     * moved. A destination that already holds a file is refused rather than
     * overwritten: it means a previous retirement of this sermon is still there.
     *
     * @param  list<array{role:string, disk:string, path:string, bytes:int}>  $assets
     * @return list<array{role:string, disk:string, from:string, to:string, bytes:int, sha256:string}>
     */
    private function relocateAssets(Sermon $sermon, HistoricImportOperation $operation, array $assets): array
    {
        $moved = [];

        foreach ($assets as $asset) {
            $disk = Storage::disk($asset['disk']);
            $destination = $this->destinationFor($operation, $sermon, $asset['role'], $asset['path']);

            if ($disk->exists($destination)) {
                $this->rollBackRelocation($moved);

                throw new RuntimeException(
                    "A superseded asset already exists at [{$asset['disk']}:{$destination}]; resolve it before retiring again."
                );
            }

            $sha256 = hash_file('sha256', $disk->path($asset['path']));

            try {
                $disk->move($asset['path'], $destination);
            } catch (Throwable $exception) {
                $this->rollBackRelocation($moved);

                throw new RuntimeException(
                    "Could not relocate [{$asset['disk']}:{$asset['path']}]: {$exception->getMessage()}",
                    0,
                    $exception,
                );
            }

            $moved[] = [
                'role' => $asset['role'],
                'disk' => $asset['disk'],
                'from' => $asset['path'],
                'to' => $destination,
                'bytes' => $asset['bytes'],
                'sha256' => $sha256 === false ? '' : $sha256,
            ];
        }

        return $moved;
    }

    /**
     * Put every relocated asset back where it came from. Best effort by
     * construction: a rollback that itself fails must not mask the original
     * failure the caller is about to report.
     *
     * @param  list<array{role:string, disk:string, from:string, to:string, bytes:int, sha256:string}>  $moved
     */
    private function rollBackRelocation(array $moved): void
    {
        foreach (array_reverse($moved) as $asset) {
            try {
                Storage::disk($asset['disk'])->move($asset['to'], $asset['from']);
            } catch (Throwable) {
                // Reported by the exception the caller is already raising.
            }
        }
    }

    /**
     * The retirement record written beside the relocated files, so the disk
     * explains itself without the database. The database row is authoritative;
     * this is the copy that survives a restore of one without the other.
     *
     * @param  list<array{role:string, disk:string, from:string, to:string, bytes:int, sha256:string}>  $moved
     */
    private function writeOnDiskInventory(
        HistoricImportOperation $operation,
        MediaProcessingLog $run,
        ?Sermon $sermon,
        array $moved,
    ): void {
        if ($moved === [] || ! $sermon instanceof Sermon) {
            return;
        }

        $disk = Storage::disk((string) $sermon->asset_disk);
        $path = $this->sermonPrefix($operation, $sermon).'/retirement.json';

        $disk->put($path, json_encode([
            'operation_id' => $operation->operation_id,
            'processing_id' => $run->processing_id,
            'sermon_id' => $sermon->id,
            'sermon_slug' => $sermon->slug,
            'retired_at' => now()->toIso8601String(),
            'assets' => $moved,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function destinationFor(
        HistoricImportOperation $operation,
        Sermon $sermon,
        string $role,
        string $path,
    ): string {
        return $this->sermonPrefix($operation, $sermon).'/'.$role.'-'.basename($path);
    }

    private function sermonPrefix(HistoricImportOperation $operation, Sermon $sermon): string
    {
        return self::SUPERSEDED_PREFIX.'/'.$operation->operation_id.'/'.$sermon->id;
    }

    /**
     * The stored assets this sermon actually has. A column naming a file that is
     * no longer there is skipped rather than failing the retirement: the point
     * is to withdraw the result, and a missing file is already withdrawn.
     *
     * @return list<array{role:string, disk:string, path:string, bytes:int}>
     */
    private function assetsFor(Sermon $sermon): array
    {
        $diskName = $sermon->asset_disk;

        if (! is_string($diskName) || $diskName === '') {
            return [];
        }

        $disk = Storage::disk($diskName);
        $assets = [];

        foreach (self::ASSET_COLUMNS as $role => $column) {
            $path = $sermon->{$column};

            if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
                continue;
            }

            $assets[] = [
                'role' => $role,
                'disk' => $diskName,
                'path' => $path,
                'bytes' => $disk->size($path),
            ];
        }

        return $assets;
    }

    private function sermonFor(MediaProcessingLog $run): ?Sermon
    {
        if ($run->sermon_id === null) {
            return null;
        }

        return Sermon::query()->find($run->sermon_id);
    }
}
