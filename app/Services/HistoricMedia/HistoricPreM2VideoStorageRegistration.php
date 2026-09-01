<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Jobs\AwaitHistoricSermonVideoStorage;
use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\ExtractedMediaDurationProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Register the sermon-video storage that a pre-M2 historic run performed before
 * the tracking existed.
 *
 * {@see AwaitHistoricSermonVideoStorage} settles on a
 * {@see HistoricImportNestedJob} row written by {@see StoreSermonVideo}. That
 * registration landed with M2; every run dispatched before it stored its video
 * without writing one. The gate therefore fails closed on those runs for a
 * missing record rather than missing media, which strands their promotion and
 * cleanup tail permanently.
 *
 * This backfill is deliberately narrow. It does not relax the gate: a run that
 * could have been registered is refused, and settledness is never assumed. The
 * only evidence accepted is the durable asset itself — present, non-empty and
 * probing as real media.
 *
 * Deletion trigger: Delete once every pre-M2 historic run has a truthful
 * terminal disposition and the historic import's closeout retention window has
 * expired. No run created after {@see self::REGISTRATION_LANDED_AT} can use it.
 */
final class HistoricPreM2VideoStorageRegistration
{
    /**
     * When StoreSermonVideo began registering its nested job (commit 5b25f09e1,
     * 2026-08-31 11:24:02 +0100), expressed in UTC to match stored timestamps.
     *
     * A run created at or after this instant had the registration available and
     * is refused: an absent row there means storage genuinely never ran, which
     * is exactly what the gate exists to catch.
     */
    public const REGISTRATION_LANDED_AT = '2026-08-31 10:24:02';

    public function __construct(
        private readonly ExtractedMediaDurationProbe $durationProbe,
    ) {}

    /**
     * @param  list<string>  $processingIds
     * @return list<array{
     *     processing_id: string,
     *     run: MediaProcessingLog,
     *     sermon: Sermon,
     *     asset_path: string,
     *     asset_disk: string,
     *     asset_bytes: int,
     *     asset_duration: float,
     *     disposition: 'pending'|'already_registered'
     * }>
     */
    public function inspect(HistoricImportOperation $operation, array $processingIds): array
    {
        if ($processingIds === [] || count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Name every target processing ID exactly once.');
        }

        $runs = MediaProcessingLog::query()
            ->whereIn('processing_id', $processingIds)
            ->get()
            ->keyBy('processing_id');
        $missing = array_values(array_diff($processingIds, $runs->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException('Selected processing runs do not exist: '.implode(', ', $missing).'.');
        }

        $entries = [];

        foreach ($processingIds as $processingId) {
            $run = $runs->get($processingId);

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException("Processing run {$processingId} could not be loaded.");
            }

            $this->assertEligible($run, $operation);

            $sermon = $this->sermonForRun($run);
            $asset = $this->verifiedAsset($run, $sermon);

            $entries[] = [
                'processing_id' => $processingId,
                'run' => $run,
                'sermon' => $sermon,
                'asset_path' => $asset['path'],
                'asset_disk' => $asset['disk'],
                'asset_bytes' => $asset['bytes'],
                'asset_duration' => $asset['duration'],
                'disposition' => $this->existingRegistration($run) instanceof HistoricImportNestedJob
                    ? 'already_registered'
                    : 'pending',
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{
     *     processing_id: string,
     *     run: MediaProcessingLog,
     *     sermon: Sermon,
     *     asset_path: string,
     *     asset_disk: string,
     *     asset_bytes: int,
     *     asset_duration: float,
     *     disposition: 'pending'|'already_registered'
     * }>  $entries
     * @return array{registered: int, already_registered: int}
     */
    public function apply(HistoricImportOperation $operation, array $entries): array
    {
        return DB::transaction(function () use ($operation, $entries): array {
            $registered = 0;
            $alreadyRegistered = 0;

            foreach ($entries as $entry) {
                $run = MediaProcessingLog::query()
                    ->where('processing_id', $entry['processing_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $run instanceof MediaProcessingLog) {
                    throw new RuntimeException("Processing run {$entry['processing_id']} disappeared before registration.");
                }

                $this->assertEligible($run, $operation);

                if ($this->existingRegistration($run) instanceof HistoricImportNestedJob) {
                    $alreadyRegistered++;

                    continue;
                }

                HistoricImportNestedJob::query()->create([
                    'historic_import_operation_id' => $run->historic_import_operation_id,
                    'media_processing_log_id' => $run->id,
                    'job_key' => StoreSermonVideo::nestedJobKey($run->processing_id),
                    'job_type' => StoreSermonVideo::class,
                    'state' => 'completed',
                    'attempts' => 1,
                    'dispatched_at' => $run->created_at,
                    'settled_at' => $run->updated_at,
                ]);

                $registered++;
            }

            return ['registered' => $registered, 'already_registered' => $alreadyRegistered];
        });
    }

    /**
     * Refuse anything the gate should still be allowed to catch.
     */
    private function assertEligible(MediaProcessingLog $run, HistoricImportOperation $operation): void
    {
        if ($run->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException("Processing run {$run->processing_id} is not owned by operation {$operation->operation_id}.");
        }

        if ($run->created_at === null || $run->created_at->gte(self::REGISTRATION_LANDED_AT)) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} was created after sermon video storage began registering itself; "
                .'an absent registration there means storage never ran.',
            );
        }
    }

    private function existingRegistration(MediaProcessingLog $run): ?HistoricImportNestedJob
    {
        return HistoricImportNestedJob::query()
            ->where('historic_import_operation_id', $run->historic_import_operation_id)
            ->where('media_processing_log_id', $run->id)
            ->where('job_key', StoreSermonVideo::nestedJobKey($run->processing_id))
            ->where('job_type', StoreSermonVideo::class)
            ->first();
    }

    private function sermonForRun(MediaProcessingLog $run): Sermon
    {
        $sermons = Sermon::query()
            ->where('livestream_processing_id', $run->processing_id)
            ->orderBy('id')
            ->get();

        if ($sermons->count() !== 1 || ! $sermons->first() instanceof Sermon) {
            throw new RuntimeException("Processing run {$run->processing_id} must identify exactly one sermon.");
        }

        $sermon = $sermons->first();

        if ($run->sermon_id !== null && $run->sermon_id !== $sermon->id) {
            throw new RuntimeException("Processing run {$run->processing_id} has an inconsistent sermon link.");
        }

        return $sermon;
    }

    /**
     * The only accepted evidence that storage settled: the durable video exists,
     * holds bytes and probes as real media.
     *
     * @return array{path: string, disk: string, bytes: int, duration: float}
     */
    private function verifiedAsset(MediaProcessingLog $run, Sermon $sermon): array
    {
        $path = $sermon->video_file_path;

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("Sermon {$sermon->id} has no video asset, so its storage cannot be registered.");
        }

        $assetDisk = (string) ($sermon->asset_disk ?? '');

        if ($assetDisk === '') {
            $context = $run->historicStagingContext();

            if ($context === null) {
                throw new RuntimeException(
                    "Processing run {$run->processing_id} has no asset disk and no staging context to resolve its video.",
                );
            }

            $assetDisk = $context->stagingDisk;
            $path = rtrim($context->batchRoot, '/').'/'.ltrim($path, '/');
        }

        $disk = Storage::disk($assetDisk);

        if (! $disk->exists($path)) {
            throw new RuntimeException("Sermon {$sermon->id} video asset is missing from disk {$assetDisk}: {$path}");
        }

        $bytes = (int) $disk->size($path);

        if ($bytes <= 0) {
            throw new RuntimeException("Sermon {$sermon->id} video asset holds no bytes on disk {$assetDisk}: {$path}");
        }

        // The probe itself refuses an unreadable or non-positive duration, which
        // is what makes this evidence rather than a file-existence check.
        $duration = $this->durationProbe->durationOf($disk->path($path));

        return ['path' => $path, 'disk' => $assetDisk, 'bytes' => $bytes, 'duration' => $duration];
    }
}
