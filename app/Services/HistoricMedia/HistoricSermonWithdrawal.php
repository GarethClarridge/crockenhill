<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Withdraw a sermon row a historic run produced, keeping its bytes. A sermon has no
 * supersession state, so its assets are relocated under
 * `superseded/{operation}/{sermon}/` on the same disk, an inventory is written
 * beside them, and the caller deletes the row — leaving every sermon query correct
 * with no change.
 *
 * Shared by run retirement (the source was replaced) and run identity correction
 * (the run's date was wrong, so its sermon row was).
 *
 * Deletion trigger: Delete with {@see HistoricRunRetirement} once the historic
 * import operation is closed out.
 */
class HistoricSermonWithdrawal
{
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

    /**
     * A published sermon is not a historic import's to withdraw, and a sermon a
     * service section has published cannot be deleted at all — the foreign key
     * restricts it. Both are refused here so the operator reads why rather than
     * a constraint violation.
     */
    public function guardWithdrawable(Sermon $sermon): void
    {
        if ($sermon->publication_state === SermonPublicationState::Published) {
            throw new RuntimeException(
                "Sermon [{$sermon->id}] is published; unpublish it before withdrawing it."
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
     * The stored assets this sermon actually has. A column naming a file that is
     * no longer there is skipped rather than failing the withdrawal: the point is
     * to withdraw the result, and a missing file is already withdrawn.
     *
     * @return list<array{role:string, disk:string, path:string, bytes:int}>
     */
    public function assetsFor(Sermon $sermon): array
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

    /**
     * Move each asset to the superseded prefix, returning the inventory of what
     * moved. A destination that already holds a file is refused rather than
     * overwritten: it means a previous withdrawal of this sermon is still there.
     *
     * @param  list<array{role:string, disk:string, path:string, bytes:int}>  $assets
     * @return list<array{role:string, disk:string, from:string, to:string, bytes:int, sha256:string}>
     */
    public function relocate(Sermon $sermon, HistoricImportOperation $operation, array $assets): array
    {
        $moved = [];

        foreach ($assets as $asset) {
            $disk = Storage::disk($asset['disk']);
            $destination = $this->prefix($operation, $sermon).'/'.$asset['role'].'-'.basename($asset['path']);

            if ($disk->exists($destination)) {
                $this->rollBack($moved);

                throw new RuntimeException(
                    "A superseded asset already exists at [{$asset['disk']}:{$destination}]; resolve it before withdrawing again."
                );
            }

            $sha256 = hash_file('sha256', $disk->path($asset['path']));

            try {
                $disk->move($asset['path'], $destination);
            } catch (Throwable $exception) {
                $this->rollBack($moved);

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
    public function rollBack(array $moved): void
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
     * The record written beside the relocated files, so the disk explains itself
     * without the database. The database row is authoritative; this is the copy
     * that survives a restore of one without the other.
     *
     * @param  list<array{role:string, disk:string, from:string, to:string, bytes:int, sha256:string}>  $moved
     * @param  array<string, mixed>  $context
     */
    public function writeInventory(
        HistoricImportOperation $operation,
        MediaProcessingLog $run,
        Sermon $sermon,
        array $moved,
        string $filename,
        array $context,
    ): void {
        if ($moved === []) {
            return;
        }

        Storage::disk((string) $sermon->asset_disk)->put(
            $this->prefix($operation, $sermon).'/'.$filename,
            json_encode([
                'operation_id' => $operation->operation_id,
                'processing_id' => $run->processing_id,
                'sermon_id' => $sermon->id,
                'sermon_slug' => $sermon->slug,
                ...$context,
                'assets' => $moved,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    private function prefix(HistoricImportOperation $operation, Sermon $sermon): string
    {
        return self::SUPERSEDED_PREFIX.'/'.$operation->operation_id.'/'.$sermon->id;
    }
}
