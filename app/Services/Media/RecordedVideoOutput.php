<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\RecordedVideoOutputMismatch;
use App\Models\MediaProcessingLog;
use App\Support\MediaProcessingVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RecordedVideoOutput
{
    /** @return array<string, mixed> */
    public function provenance(MediaProcessingLog $run): array
    {
        return $this->metadataProvenance($run->processing_id, $run->processing_metadata?->toArray() ?? []);
    }

    /**
     * @param  array<string, mixed>  $provenance
     * @return array{disk: string, path: string, size: int, sha256: string, provenance: array<string, mixed>}
     */
    public function record(MediaProcessingLog $run, string $key, string $disk, string $path, array $provenance): array
    {
        $output = ['disk' => $disk, 'path' => $path, ...$this->fileIdentity($disk, $path), 'provenance' => $provenance];
        $run->writeProcessingMetadata(function (array $metadata) use ($run, $key, $output, $provenance): array {
            if ($this->metadataProvenance($run->processing_id, $metadata) !== $provenance) {
                throw new RecordedVideoOutputMismatch('recorded_video_provenance_mismatch');
            }
            $metadata['media_outputs'][$key] = $output;

            return $metadata;
        });

        return $output;
    }

    /** @return array{disk: string, path: string, size: int, sha256: string, provenance: array<string, mixed>} */
    public function verified(MediaProcessingLog $run, string $key): array
    {
        $output = ($run->processing_metadata?->toArray() ?? [])['media_outputs'][$key] ?? null;
        if (! is_array($output) || ! is_string($output['disk'] ?? null) || ! is_string($output['path'] ?? null)
            || ! is_int($output['size'] ?? null) || ! is_string($output['sha256'] ?? null)) {
            throw new RecordedVideoOutputMismatch('recorded_video_output_missing');
        }
        if (! is_array($output['provenance'] ?? null) || $this->canonicalKeys($output['provenance']) !== $this->provenance($run)) {
            throw new RecordedVideoOutputMismatch('recorded_video_provenance_mismatch');
        }
        $identity = $this->fileIdentity($output['disk'], $output['path']);
        if ($identity['size'] !== $output['size']) {
            throw new RecordedVideoOutputMismatch('recorded_video_size_mismatch');
        }
        if (! hash_equals($output['sha256'], $identity['sha256'])) {
            throw new RecordedVideoOutputMismatch('recorded_video_hash_mismatch');
        }

        return $output;
    }

    /**
     * Verify destination bytes before a database transaction. Older outputs stay unrecorded.
     * A recompose may have changed current provenance; custody preserves the original evidence.
     *
     * @return array{run_id: int, key: string, output: array<string, mixed>, target_disk: string}|null
     */
    public function prepareRelocation(MediaProcessingLog $run, string $key, string $sourceDisk, string $targetDisk, string $path): ?array
    {
        $metadata = ($run->fresh() ?? $run)->processing_metadata?->toArray() ?? [];
        if (! array_key_exists($key, $metadata['media_outputs'] ?? [])) {
            return null;
        }
        $output = $metadata['media_outputs'][$key];
        if (! is_array($output) || ($output['path'] ?? null) !== $path
            || ! in_array($output['disk'] ?? null, [$sourceDisk, $targetDisk], true)
            || ! is_int($output['size'] ?? null) || ! is_string($output['sha256'] ?? null)
            || ! is_array($output['provenance'] ?? null)) {
            throw new RecordedVideoOutputMismatch('recorded_video_custody_mismatch');
        }
        $identity = $this->fileIdentity($targetDisk, $path);
        if ($identity['size'] !== $output['size']) {
            throw new RecordedVideoOutputMismatch('recorded_video_size_mismatch');
        }
        if (! hash_equals($output['sha256'], $identity['sha256'])) {
            throw new RecordedVideoOutputMismatch('recorded_video_hash_mismatch');
        }

        return ['run_id' => $run->id, 'key' => $key, 'output' => $output, 'target_disk' => $targetDisk];
    }

    /**
     * Bind only the identity whose destination was verified; perform no storage I/O under lock.
     *
     * @param  array{run_id: int, key: string, output: array<string, mixed>, target_disk: string}|null  $relocation
     */
    public function commitRelocation(?array $relocation): void
    {
        if ($relocation === null) {
            return;
        }
        DB::transaction(function () use ($relocation): void {
            $locked = MediaProcessingLog::query()->whereKey($relocation['run_id'])->lockForUpdate()->firstOrFail();
            $metadata = $locked->processing_metadata?->toArray() ?? [];
            $current = $metadata['media_outputs'][$relocation['key']] ?? null;
            if (! is_array($current) || $this->canonicalKeys($current) !== $this->canonicalKeys($relocation['output'])) {
                throw new RecordedVideoOutputMismatch('recorded_video_custody_mismatch');
            }
            $metadata['media_outputs'][$relocation['key']] = [...$current, 'disk' => $relocation['target_disk']];
            $locked->forceFill(['processing_metadata' => $metadata])->save();
        });
    }

    /** @return array{size: int, sha256: string} */
    private function fileIdentity(string $disk, string $path): array
    {
        $filesystem = Storage::disk($disk);
        if (! $filesystem->exists($path)) {
            throw new RecordedVideoOutputMismatch('recorded_video_file_missing');
        }
        $stream = $filesystem->readStream($path);
        if (! is_resource($stream)) {
            throw new RecordedVideoOutputMismatch('recorded_video_file_unreadable');
        }
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);

            return ['size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($stream);
        }
    }

    /**
     * Bind stable dispatch and selection facts; storage and promotion may consume replacement markers.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadataProvenance(string $processingId, array $metadata): array
    {
        $rounds = $metadata['corpus_rerun'] ?? [];
        $round = $rounds === [] ? [] : end($rounds);

        return $this->canonicalKeys([
            'processing_id' => $processingId,
            'round' => Arr::only($round, ['snapshot_file_sha256', 'code_revision', 'dispatched_at', 'extraction_dispatched_at']),
            'projection' => $metadata['service_structure_projection'] ?? null,
            'segments' => $metadata['trim']['segments'] ?? [],
            'media_processing' => MediaProcessingVersion::signature(),
        ]);
    }

    /**
     * Normalise JSON object keys without changing the order of selected spans.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function canonicalKeys(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonicalKeys($value);
            }
        }
        if (! array_is_list($values)) {
            ksort($values);
        }

        return $values;
    }
}
