<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use RuntimeException;

class HistoricProcessingMetadataSerializer
{
    private const PORTABLE_KEYS = [
        'historic_import',
        'rms_log_path',
        'service_artifacts',
        'service_structure',
        'service_structure_result',
        'service_transcript_path',
        'raw_service_transcript_path',
        'compressed_service_audio_path',
        'processing_fingerprint',
    ];

    private const RUNTIME_KEYS = [
        'attempt_count',
        'enhanced_audio_file_path',
        'job_id',
        'owner_user_id',
        'queue_name',
        'retry_state',
        'source_file_path',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function serialize(array $metadata): array
    {
        $portable = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, self::RUNTIME_KEYS, true)) {
                continue;
            }

            if (! in_array($key, self::PORTABLE_KEYS, true)) {
                $this->guardUnknownKey($key, $value);

                continue;
            }

            if ($key === 'historic_import' && is_array($value)) {
                $value = $this->portableHistoricImport($value);
            }

            $this->guardPortableValue($key, $value);
            $portable[$key] = $value;
        }

        ksort($portable);

        return $portable;
    }

    /**
     * @param  array<string, mixed>  $historicImport
     * @return array<string, mixed>
     */
    private function portableHistoricImport(array $historicImport): array
    {
        $sources = collect($historicImport['sources'] ?? [])
            ->filter(fn (mixed $source): bool => is_array($source))
            ->map(fn (array $source): array => [
                'sha256' => $source['sha256'] ?? null,
                'size' => $source['size'] ?? null,
            ])
            ->values()
            ->all();

        return array_filter([
            'tag' => $historicImport['tag'] ?? null,
            'concatenation' => $historicImport['concatenation'] ?? null,
            'codec_fingerprint' => $historicImport['codec_fingerprint'] ?? null,
            'sources' => $sources,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function guardUnknownKey(string $key, mixed $value): void
    {
        if (
            preg_match('/(^|_)(id|ids|path|paths|token|secret|key)$/i', $key) === 1
            || str_contains(mb_strtolower($key), 'proposal')
            || str_contains(mb_strtolower($key), 'retry')
            || $this->containsAbsolutePath($value)
        ) {
            throw new RuntimeException("Unsupported ID-bearing, path or runtime processing metadata: {$key}.");
        }
    }

    private function guardPortableValue(string $key, mixed $value): void
    {
        if ($this->containsAbsolutePath($value)) {
            throw new RuntimeException("Portable processing metadata contains an absolute path in {$key}.");
        }

        if ($this->containsForbiddenNestedKey($value)) {
            throw new RuntimeException("Portable processing metadata contains a local identity or runtime field in {$key}.");
        }
    }

    private function containsAbsolutePath(mixed $value): bool
    {
        if (is_string($value)) {
            return str_starts_with($value, '/')
                || preg_match('/\A[A-Za-z]:[\\\\\\/]/', $value) === 1
                || str_starts_with($value, 'file://');
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->containsAbsolutePath($nested)) {
                return true;
            }
        }

        return false;
    }

    private function containsForbiddenNestedKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key) && (
                preg_match('/(^|_)(id|ids)$/i', $key) === 1
                || in_array($key, self::RUNTIME_KEYS, true)
                || str_contains(mb_strtolower($key), 'proposal')
                || str_contains(mb_strtolower($key), 'retry')
            )) {
                return true;
            }

            if ($this->containsForbiddenNestedKey($nested)) {
                return true;
            }
        }

        return false;
    }
}
