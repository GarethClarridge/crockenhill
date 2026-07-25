<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Storage;

final class ServiceArtifactStorage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function putJson(string $processingId, string $suffix, array $payload): string
    {
        $path = $this->basePath($processingId).".{$suffix}.json";
        Storage::disk($this->transcriptDisk())->put(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $this->recordPath($processingId, "service_{$suffix}_path", $path);

        return $path;
    }

    public function archiveAudio(string $processingId, string $sourcePath): string
    {
        $path = str_replace('service-transcripts/', 'service-audio/', $this->basePath($processingId)).'.mp3';
        $stream = fopen($sourcePath, 'rb');

        if (! is_resource($stream)) {
            throw new \RuntimeException("Unable to open compressed service audio: {$sourcePath}");
        }

        try {
            if (! Storage::disk($this->sermonDisk())->put($path, $stream)) {
                throw new \RuntimeException("Unable to archive compressed service audio: {$path}");
            }
        } finally {
            fclose($stream);
        }
        $this->recordPath($processingId, 'service_audio_path', $path);

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
        $this->recordPath($processingId, 'rms_archive_path', $path);

        return $path;
    }

    private function basePath(string $processingId): string
    {
        $log = MediaProcessingLog::query()->where('processing_id', $processingId)->first();
        $date = $log?->extracted_date?->format('Y-m-d') ?? 'unknown-date';
        $service = $log?->extracted_service->value ?? 'other';

        return "service-transcripts/{$date}/{$service}-{$processingId}";
    }

    private function transcriptDisk(): string
    {
        return (string) config('media-processing.storage.transcript_disk', 'local');
    }

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', 'public');
    }

    private function recordPath(string $processingId, string $key, string $path): void
    {
        $log = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if ($log === null) {
            return;
        }
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $metadata[$key] = $path;
        $log->forceFill(['processing_metadata' => $metadata])->save();
    }
}
