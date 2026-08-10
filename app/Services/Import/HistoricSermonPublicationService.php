<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\Sermon;
use App\Models\SongVideo;
use App\Services\Song\SongVideoService;
use App\Support\Path;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoricSermonPublicationService
{
    public function __construct(
        private readonly HistoricImportJournal $journal,
    ) {}

    public function release(HistoricImportOperation $operation, Sermon $sermon): Sermon
    {
        return $this->releaseRecords($operation, [$sermon->id], [])['sermons'][0];
    }

    /**
     * Release an exact, enumerated batch of quarantined historic records.
     *
     * Song videos travel with the sermons deliberately. `SongVideo` has no
     * per-record disk and {@see SongVideoService::getVideoUrl()}
     * always builds a sermon-disk URL, so a song video whose state flips without
     * its bytes moving would advertise a public URL for a file that only exists
     * on the private quarantine disk.
     *
     * @param  list<int>  $sermonIds
     * @param  list<int>  $songVideoIds
     * @return array{sermons: list<Sermon>, song_videos: list<SongVideo>}
     */
    public function releaseRecords(HistoricImportOperation $operation, array $sermonIds, array $songVideoIds): array
    {
        $targetDiskName = (string) config('media-processing.storage.sermon_disk');
        $sermons = $this->quarantinedSermons($operation, $sermonIds);
        $songVideos = $this->quarantinedSongVideos($operation, $songVideoIds);
        $target = Storage::disk($targetDiskName);
        $created = [];

        try {
            $sermonPaths = [];

            foreach ($sermons as $sermon) {
                $sourceDiskName = (string) $sermon->asset_disk;
                $this->assertDistinctDisks($sourceDiskName, $targetDiskName);
                $source = Storage::disk($sourceDiskName);
                $sermonPaths[$sermon->id] = $this->assetPaths($sermon);

                foreach ($sermonPaths[$sermon->id] as $path) {
                    $this->copyVerified($source, $target, $path, $created);
                }
            }

            $quarantineDiskName = (string) config('media-processing.storage.historic_quarantine_disk');

            if ($songVideos !== []) {
                $this->assertDistinctDisks($quarantineDiskName, $targetDiskName);
                $quarantine = Storage::disk($quarantineDiskName);

                foreach ($songVideos as $songVideo) {
                    $this->copyVerified($quarantine, $target, $songVideo->video_file_path, $created);
                }
            }

            return DB::transaction(fn (): array => $this->commit(
                $operation,
                $sermons,
                $songVideos,
                $sermonPaths,
                $quarantineDiskName,
                $targetDiskName,
            ));
        } catch (\Throwable $exception) {
            foreach ($created as $path) {
                $target->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * @param  list<Sermon>  $sermons
     * @param  list<SongVideo>  $songVideos
     * @param  array<int, list<string>>  $sermonPaths
     * @return array{sermons: list<Sermon>, song_videos: list<SongVideo>}
     */
    private function commit(
        HistoricImportOperation $operation,
        array $sermons,
        array $songVideos,
        array $sermonPaths,
        string $quarantineDiskName,
        string $targetDiskName,
    ): array {
        $releasedSermons = [];
        $releasedSongVideos = [];

        foreach ($sermons as $sermon) {
            $sourceDiskName = (string) $sermon->asset_disk;
            $locked = Sermon::query()->whereKey($sermon->id)->lockForUpdate()->firstOrFail();

            if ($locked->historic_import_operation_id !== $operation->id
                || $locked->publication_state !== SermonPublicationState::Quarantined
                || $locked->asset_disk !== $sourceDiskName) {
                throw new RuntimeException('Historic sermon release binding changed before commit.');
            }

            $locked->forceFill([
                'publication_state' => SermonPublicationState::Published,
                'asset_disk' => $targetDiskName,
            ])->save();

            $this->journal->append($operation, 'sermon_released', [
                'sermon_id' => $locked->id,
                'source_disk' => $sourceDiskName,
                'target_disk' => $targetDiskName,
                'asset_paths' => $sermonPaths[$sermon->id],
            ]);

            $releasedSermons[] = $locked->fresh() ?? $locked;
        }

        foreach ($songVideos as $songVideo) {
            $locked = SongVideo::query()->whereKey($songVideo->id)->lockForUpdate()->firstOrFail();

            if ($locked->historic_import_operation_id !== $operation->id
                || $locked->publication_state !== SermonPublicationState::Quarantined) {
                throw new RuntimeException('Historic song video release binding changed before commit.');
            }

            $locked->forceFill(['publication_state' => SermonPublicationState::Published])->save();

            $this->journal->append($operation, 'song_video_released', [
                'song_video_id' => $locked->id,
                'source_disk' => $quarantineDiskName,
                'target_disk' => $targetDiskName,
                'asset_paths' => [$locked->video_file_path],
            ]);

            $releasedSongVideos[] = $locked->fresh() ?? $locked;
        }

        return ['sermons' => $releasedSermons, 'song_videos' => $releasedSongVideos];
    }

    /**
     * Exact membership, resolved before a single byte is copied: every id must
     * exist, belong to this operation and still be quarantined.
     *
     * @param  list<int>  $sermonIds
     * @return list<Sermon>
     */
    private function quarantinedSermons(HistoricImportOperation $operation, array $sermonIds): array
    {
        if ($sermonIds === []) {
            return [];
        }

        $sermons = Sermon::query()
            ->whereKey($sermonIds)
            ->where('historic_import_operation_id', $operation->id)
            ->where('publication_state', SermonPublicationState::Quarantined)
            ->orderBy('id')
            ->get();

        if ($sermons->count() !== count($sermonIds)) {
            throw new RuntimeException(
                'Release membership is not exact: every named sermon must be a quarantined record of this operation.',
            );
        }

        return array_values($sermons->all());
    }

    /**
     * @param  list<int>  $songVideoIds
     * @return list<SongVideo>
     */
    private function quarantinedSongVideos(HistoricImportOperation $operation, array $songVideoIds): array
    {
        if ($songVideoIds === []) {
            return [];
        }

        $songVideos = SongVideo::query()
            ->whereKey($songVideoIds)
            ->where('historic_import_operation_id', $operation->id)
            ->where('publication_state', SermonPublicationState::Quarantined)
            ->orderBy('id')
            ->get();

        if ($songVideos->count() !== count($songVideoIds)) {
            throw new RuntimeException(
                'Release membership is not exact: every named song video must be a quarantined record of this operation.',
            );
        }

        return array_values($songVideos->all());
    }

    private function assertDistinctDisks(string $sourceDiskName, string $targetDiskName): void
    {
        if ($sourceDiskName === '' || $targetDiskName === '' || $sourceDiskName === $targetDiskName) {
            throw new RuntimeException('Historic release requires distinct private and public media disks.');
        }
    }

    /**
     * @return list<string>
     */
    private function assetPaths(Sermon $sermon): array
    {
        $paths = [];

        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
            $value = $sermon->getAttribute($field);

            if (is_string($value) && $value !== '') {
                $paths[] = $value;
            }
        }

        $this->collectMetadataPaths($sermon->thumbnail_metadata?->toArray() ?? [], $paths);

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @param  list<string>  $paths
     */
    private function collectMetadataPaths(array $values, array &$paths): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->collectMetadataPaths($value, $paths);

                continue;
            }

            if (is_string($key) && str_ends_with($key, '_path') && is_string($value) && $value !== '') {
                $paths[] = $value;
            }
        }
    }

    /** @param list<string> $created */
    private function copyVerified(
        FilesystemAdapter $source,
        FilesystemAdapter $target,
        string $path,
        array &$created,
    ): void {
        if (Path::isUnsafe($path) || ! $source->exists($path)) {
            throw new RuntimeException("Quarantined sermon asset is missing or unsafe: {$path}.");
        }

        $sourceHash = $this->hash($source, $path);
        $sourceSize = $source->size($path);

        if ($target->exists($path)) {
            if ($target->size($path) !== $sourceSize || ! hash_equals($sourceHash, $this->hash($target, $path))) {
                throw new RuntimeException("Public sermon asset path contains different bytes: {$path}.");
            }

            return;
        }

        $stream = $source->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Quarantined sermon asset cannot be read: {$path}.");
        }

        try {
            if (! $target->writeStream($path, $stream)) {
                throw new RuntimeException("Quarantined sermon asset cannot be released: {$path}.");
            }
        } finally {
            fclose($stream);
        }

        $created[] = $path;

        if ($target->size($path) !== $sourceSize || ! hash_equals($sourceHash, $this->hash($target, $path))) {
            throw new RuntimeException("Released sermon asset failed verification: {$path}.");
        }
    }

    private function hash(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Sermon asset cannot be hashed: {$path}.");
        }

        $hash = hash_init('sha256');

        try {
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
