<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\Sermon;
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
        $sourceDiskName = (string) $sermon->asset_disk;
        $targetDiskName = (string) config('media-processing.storage.sermon_disk');

        if ($sermon->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException('Historic sermon belongs to another import operation.');
        }

        if ($sermon->publication_state !== SermonPublicationState::Quarantined) {
            throw new RuntimeException('Only a quarantined historic sermon can be released.');
        }

        if ($sourceDiskName === '' || $targetDiskName === '' || $sourceDiskName === $targetDiskName) {
            throw new RuntimeException('Historic release requires distinct private and public media disks.');
        }

        $paths = $this->assetPaths($sermon);
        $source = Storage::disk($sourceDiskName);
        $target = Storage::disk($targetDiskName);
        $created = [];

        try {
            foreach ($paths as $path) {
                $this->copyVerified($source, $target, $path, $created);
            }

            return DB::transaction(function () use ($operation, $sermon, $sourceDiskName, $targetDiskName, $paths): Sermon {
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
                    'asset_paths' => $paths,
                ]);

                return $locked->fresh() ?? $locked;
            });
        } catch (\Throwable $exception) {
            foreach ($created as $path) {
                $target->delete($path);
            }

            throw $exception;
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
