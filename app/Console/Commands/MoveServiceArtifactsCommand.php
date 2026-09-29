<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\HistoricStagingContext;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\ServiceArtifactDisk;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * One-shot: copies every run's service artifacts onto the configured service artifact disk.
 *
 * Artifacts used to follow the media disk, so on this machine they sat on the external staging
 * drive beside multi-gigabyte recordings. Each file is read exactly as its run reads it — inside
 * the run's historic staging context when it has one, so from its batch root — copied, verified
 * by SHA-256, and only then is the run's artifact record repointed. The source is never deleted:
 * it stays as the first backup. A destination that already holds different bytes is refused.
 *
 * Deletion trigger: remove once a dry run reports nothing to copy on every machine that
 * processes services.
 */
class MoveServiceArtifactsCommand extends Command
{
    protected $signature = 'media:move-service-artifacts
                            {--apply : Copy the files and repoint the records (the default is a dry run)}';

    protected $description = 'Copy every run\'s service artifacts onto the service artifact disk, verified, keeping the source';

    public function handle(HistoricStagingContextRegistry $contexts): int
    {
        $target = config('media-processing.storage.service_artifact_disk');

        if (! is_string($target) || $target === '') {
            $this->error('No service artifact disk is configured. Set SERVICE_ARTIFACT_DISK before moving artifacts.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $tally = ['copied' => 0, 'already_present' => 0, 'missing' => 0, 'conflicts' => 0];

        if (! $apply) {
            $this->warn('DRY RUN — nothing is copied or repointed. Pass --apply to move.');
        }

        MediaProcessingLog::query()
            ->whereNotNull('processing_metadata')
            ->orderBy('id')
            ->chunkById(100, function ($runs) use ($contexts, $target, $apply, &$tally): void {
                foreach ($runs as $run) {
                    $context = $run->historicStagingContext();
                    $move = function () use ($run, $target, $apply, &$tally): array {
                        return $this->moveRun($run, $target, $apply, $tally);
                    };
                    $moved = $context instanceof HistoricStagingContext ? $contexts->within($context, $move) : $move();

                    if ($apply && $moved !== []) {
                        $this->repointRecords($run, $moved, $target);
                    }
                }
            });

        $this->info(sprintf(
            '%d %s, %d already present, %d source missing, %d conflicting.',
            $tally['copied'],
            $apply ? 'copied' : 'to copy',
            $tally['already_present'],
            $tally['missing'],
            $tally['conflicts'],
        ));

        return $tally['missing'] === 0 && $tally['conflicts'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Copy (or, in a dry run, assess) each of the run's artifacts not yet on the target.
     *
     * @param  array{copied: int, already_present: int, missing: int, conflicts: int}  $tally
     * @return list<string> Paths now verified on the target, whose records may be repointed
     */
    private function moveRun(MediaProcessingLog $run, string $target, bool $apply, array &$tally): array
    {
        $destination = Storage::disk($target);
        $verified = [];

        foreach ($this->artifactsOf($run) as $path => $sourceDisk) {
            if ($sourceDisk === $target) {
                continue;
            }

            $source = Storage::disk($sourceDisk);

            if (! $source->exists($path)) {
                $tally['missing']++;
                $this->warn("{$run->processing_id}: source missing on {$sourceDisk}: {$path}");

                continue;
            }

            $sourceHash = $this->hash($source, $path);

            if ($destination->exists($path)) {
                if ($this->hash($destination, $path) !== $sourceHash) {
                    $tally['conflicts']++;
                    $this->error("{$run->processing_id}: {$target}:{$path} differs from the source; left untouched.");

                    continue;
                }

                $tally['already_present']++;
                $verified[] = $path;

                continue;
            }

            $tally['copied']++;

            if (! $apply) {
                continue;
            }

            $stream = $source->readStream($path);

            if (! is_resource($stream)) {
                throw new \RuntimeException("Unable to open {$sourceDisk}:{$path} for copying.");
            }

            try {
                $destination->writeStream($path, $stream, ServiceArtifactDisk::WRITE_OPTIONS);
            } finally {
                fclose($stream);
            }

            if ($this->hash($destination, $path) !== $sourceHash) {
                $destination->delete($path);
                $tally['copied']--;
                $tally['conflicts']++;
                $this->error("{$run->processing_id}: copy of {$path} failed verification; removed.");

                continue;
            }

            $verified[] = $path;
        }

        return $verified;
    }

    /**
     * Every artifact the run references, with the disk it is read from today: recorded entries
     * name theirs; a path field alone resolves as {@see ServiceArtifactDisk} did before the
     * artifact disk existed — to the transcript disk, as the run's context sets it.
     *
     * @return array<string, string> Path => source disk
     */
    private function artifactsOf(MediaProcessingLog $run): array
    {
        $artifacts = [];

        foreach (ServiceArtifactStorage::recordedFor($run) as $artifact) {
            $artifacts[$artifact['path']] ??= $artifact['disk'];
        }

        $transcriptDisk = (string) config('media-processing.storage.transcript_disk');

        foreach ([$run->serviceTranscriptPath(), $run->rms_log_path, $run->audio_timeline_path] as $path) {
            if (ServiceArtifactDisk::isArtifactPath($path)) {
                $artifacts[(string) $path] ??= $transcriptDisk;
            }
        }

        return $artifacts;
    }

    /**
     * @param  list<string>  $verifiedPaths
     */
    private function repointRecords(MediaProcessingLog $run, array $verifiedPaths, string $target): void
    {
        $run->writeProcessingMetadata(static function (array $metadata) use ($verifiedPaths, $target): array {
            $entries = $metadata[ServiceArtifactStorage::METADATA_KEY] ?? null;

            if (! is_array($entries)) {
                return $metadata;
            }

            foreach ($entries as $index => $entry) {
                if (is_array($entry) && in_array($entry['path'] ?? null, $verifiedPaths, true)) {
                    $entries[$index]['disk'] = $target;
                }
            }

            $metadata[ServiceArtifactStorage::METADATA_KEY] = $entries;

            return self::rebindEnsembleBundles($metadata, $entries, $target);
        });
    }

    /**
     * Point each structure-ensemble bundle at the target once every file of it is recorded there.
     *
     * The bundle's input and slot files carry the disk they were written to inside their
     * hashed bytes, so they are never rewritten; the manifest keeps that disk as `origin_disk`
     * for the replay's integrity check and names the disk the files are read from now. A bundle
     * with any file left behind keeps its old disk, so it is never half on each.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, mixed>  $entries
     * @return array<string, mixed>
     */
    private static function rebindEnsembleBundles(array $metadata, array $entries, string $target): array
    {
        $bundles = $metadata['service_structure_ensemble'] ?? null;

        if (! is_array($bundles)) {
            return $metadata;
        }

        $onTarget = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['disk'] ?? null) === $target && is_string($entry['path'] ?? null)) {
                $onTarget[$entry['path']] = true;
            }
        }

        foreach ($bundles as $index => $bundle) {
            if (! is_array($bundle) || ($bundle['artifact_disk'] ?? null) === $target) {
                continue;
            }

            $paths = [$bundle['input_path'] ?? null, ...array_column($bundle['slots'] ?? [], 'path')];

            foreach ($paths as $path) {
                if (! is_string($path) || ! isset($onTarget[$path])) {
                    continue 2;
                }
            }

            $bundles[$index]['origin_disk'] ??= $bundle['artifact_disk'] ?? null;
            $bundles[$index]['artifact_disk'] = $target;
        }

        $metadata['service_structure_ensemble'] = $bundles;

        return $metadata;
    }

    private function hash(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Unable to read {$path} for hashing.");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
