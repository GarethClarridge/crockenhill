<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the durable per-run service artifacts and records them on the log.
 *
 * Every artifact is appended to `processing_metadata['service_artifacts']` as a
 * {kind, disk, path, …} entry rather than to a kind-specific key. Two reasons:
 * the OpenAI transcription path chunks, so `raw` is genuinely a list and no
 * single key can hold it; and both the orphan audit and the delete action need
 * to enumerate what a run produced without knowing the key names in advance.
 */
final class ServiceArtifactStorage
{
    public const METADATA_KEY = 'service_artifacts';

    /**
     * Folder used when the run cannot supply a date.
     *
     * Shared with the historic readiness audit so the writer and the check that
     * rejects it cannot drift apart.
     */
    public const UNRESOLVED_DATE = 'unknown-date';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context  Provenance recorded alongside the artifact
     */
    public function putJson(string $processingId, string $kind, array $payload, array $context = []): string
    {
        $path = $this->pathForKind($processingId, $kind, $context);

        Storage::disk($this->transcriptDisk())->put(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        $this->record($processingId, $kind, $this->transcriptDisk(), $path, $context);

        return $path;
    }

    /**
     * Where this artifact goes, preserving a payload produced by a different model.
     *
     * Re-running transcription with a better model is a normal thing to do, and
     * WP-A2 requires that it not silently destroy what the previous model
     * returned — so a differing `model` earns a suffixed key rather than
     * overwriting. Re-running the *same* model rewrites in place, because that is
     * a retry and two copies of the same thing is just noise.
     *
     * @param  array<string, mixed>  $context
     */
    private function pathForKind(string $processingId, string $kind, array $context): string
    {
        $base = $this->basePath($processingId).'.'.$this->slug($kind);
        $path = $base.'.json';
        $model = $context['model'] ?? null;

        if (! is_string($model)) {
            return $path;
        }

        $log = MediaProcessingLog::query()->where('processing_id', $processingId)->first();
        $conflicting = false;

        foreach ($this->recordedEntries($log) as $entry) {
            if (($entry['kind'] ?? null) !== $kind) {
                continue;
            }

            $recordedModel = $entry['model'] ?? null;

            if (is_string($recordedModel) && $recordedModel !== $model) {
                $conflicting = true;
            }
        }

        if (! $conflicting) {
            return $path;
        }

        return $base.'.'.$this->slug($model).'.json';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordedEntries(?MediaProcessingLog $log): array
    {
        $entries = $log?->processing_metadata?->toArray()[self::METADATA_KEY] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function archiveAudio(string $processingId, string $sourcePath, array $context = []): string
    {
        $path = str_replace('service-transcripts/', 'service-audio/', $this->basePath($processingId)).'.mp3';
        $stream = fopen($sourcePath, 'rb');

        if (! is_resource($stream)) {
            throw new \RuntimeException("Unable to open compressed service audio: {$sourcePath}");
        }

        try {
            if (Storage::disk($this->sermonDisk())->put($path, $stream) === false) {
                throw new \RuntimeException("Unable to archive compressed service audio: {$path}");
            }
        } finally {
            fclose($stream);
        }

        $this->record($processingId, 'audio', $this->sermonDisk(), $path, $context);

        return $path;
    }

    public function archiveRms(string $processingId, string $temporaryPath): string
    {
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        $path = $this->basePath($processingId).'.rms.json';
        $stream = Storage::disk($tempDisk)->readStream($temporaryPath);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Unable to read generated RMS log: {$temporaryPath}");
        }

        try {
            Storage::disk($this->transcriptDisk())->put($path, $stream);
        } finally {
            fclose($stream);
        }

        $this->record($processingId, 'rms', $this->transcriptDisk(), $path);

        return $path;
    }

    /**
     * Every artifact a run has recorded, in the order they were written.
     *
     * @return list<array{kind: string, disk: string, path: string}>
     */
    public static function recordedFor(MediaProcessingLog $log): array
    {
        $entries = $log->processing_metadata?->toArray()[self::METADATA_KEY] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        $artifacts = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $kind = $entry['kind'] ?? null;
            $disk = $entry['disk'] ?? null;
            $path = $entry['path'] ?? null;

            if (is_string($kind) && is_string($disk) && is_string($path) && $path !== '') {
                $artifacts[] = ['kind' => $kind, 'disk' => $disk, 'path' => $path];
            }
        }

        return $artifacts;
    }

    private function basePath(string $processingId): string
    {
        $log = MediaProcessingLog::query()->where('processing_id', $processingId)->first();
        $date = $log?->extracted_date?->format('Y-m-d') ?? self::UNRESOLVED_DATE;
        $service = $log?->extracted_service->value ?? 'other';

        return "service-transcripts/{$date}/{$service}-{$processingId}";
    }

    private function slug(string $kind): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $kind);

        return is_string($slug) && $slug !== '' ? $slug : 'artifact';
    }

    private function transcriptDisk(): string
    {
        return (string) config('media-processing.storage.transcript_disk', 'local');
    }

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', 'public');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $processingId, string $kind, string $disk, string $path, array $context = []): void
    {
        $log = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if ($log === null) {
            return;
        }

        $metadata = $log->processing_metadata?->toArray() ?? [];
        $artifacts = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [];

        $entry = [
            'kind' => $kind,
            'disk' => $disk,
            'path' => $path,
            'recorded_at' => now()->toIso8601String(),
            ...$context,
        ];

        // Re-running a phase rewrites its artifact in place rather than accumulating
        // a second entry pointing at the same key.
        $artifacts = array_values(array_filter(
            $artifacts,
            static fn (mixed $existing): bool => ! is_array($existing) || ($existing['path'] ?? null) !== $path,
        ));
        $artifacts[] = $entry;

        $metadata[self::METADATA_KEY] = $artifacts;

        $log->forceFill(['processing_metadata' => $metadata])->save();
    }
}
