<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\RecordedVideoOutputMismatch;
use App\Models\MediaProcessingLog;
use App\Support\MediaProcessingVersion;
use Illuminate\Support\Arr;
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
