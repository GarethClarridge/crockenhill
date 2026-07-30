<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceSourceRecord;
use App\Models\MediaProcessingLog;
use App\Support\CanonicalJson;
use App\Support\MediaAssetPath;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoricProcessingResultBundleExporter
{
    public function __construct(
        private readonly HistoricProcessingResultInventory $inventory,
        private readonly HistoricProcessingResultReadinessService $readiness,
        private readonly HistoricProcessingResultBundle $bundles,
        private readonly HistoricProcessingResultBundleFiles $files,
    ) {}

    /**
     * @param  list<string>  $processingIds
     * @param  array<string, mixed>  $processingFingerprint
     * @return array{path: string, bundle_hash: string, service_count: int}
     */
    public function export(
        array $processingIds,
        string $batchHash,
        array $processingFingerprint,
        string $outputPath,
    ): array {
        $processingIds = array_values(array_unique($processingIds));
        $runs = MediaProcessingLog::query()
            ->with(['churchService.reviewSessions', 'churchService.sourceRecords.assertions'])
            ->whereIn('processing_id', $processingIds)
            ->get()
            ->keyBy('processing_id');

        if ($processingIds === [] || $runs->count() !== count($processingIds)) {
            throw new RuntimeException('Every selected historic processing run must exist exactly once.');
        }

        $services = [];

        foreach ($processingIds as $processingId) {
            $run = $runs->get($processingId);

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException("Historic processing run {$processingId} could not be loaded.");
            }

            $services[] = $this->service($run);
        }

        $bundle = $this->bundles->make($batchHash, $processingFingerprint, $services);

        return [
            'path' => $this->files->write($outputPath, $bundle),
            'bundle_hash' => $bundle['bundle_hash'],
            'service_count' => count($services),
        ];
    }

    /** @return array<string, mixed> */
    private function service(MediaProcessingLog $run): array
    {
        $firstRead = $this->readiness->audit($run);
        $freshRun = $run->fresh() ?? $run;
        $secondRead = $this->readiness->audit($freshRun, $firstRead->logicalHash);

        if (! $firstRead->ready || ! $secondRead->ready) {
            throw new RuntimeException(implode(' ', array_unique([...$firstRead->reasons, ...$secondRead->reasons])));
        }

        $service = $run->churchService;

        if ($service === null || $service->reviewed_canonical_revision === null) {
            throw new RuntimeException("Processing run {$run->processing_id} has no finally reviewed church service.");
        }

        $review = $service->reviewSessions
            ->whereNotNull('completed_at')
            ->sortByDesc('completed_at')
            ->first();

        if ($review === null || blank($review->base_canonical_hash)) {
            throw new RuntimeException("Processing run {$run->processing_id} has no completed canonical review base.");
        }

        $livestream = $service->sourceRecords
            ->where('source', ChurchServiceSource::Livestream)
            ->sortByDesc('captured_at')
            ->first();

        if (! $livestream instanceof ChurchServiceSourceRecord || ! $livestream->payload_complete) {
            throw new RuntimeException("Processing run {$run->processing_id} has no complete Livestream source revision.");
        }

        $machineRecords = $service->sourceRecords
            ->where('source', '!=', ChurchServiceSource::Manual)
            ->sortBy('revision_hash')
            ->values();
        $mediaGraph = $this->inventory->build($run);

        return [
            'date' => $service->date->toDateString(),
            'service' => $service->service->value,
            'source_manifest_hash' => CanonicalJson::hash(
                data_get($mediaGraph, 'metadata.historic_import.sources', []),
            ),
            'evidence_set_hash' => CanonicalJson::hash($machineRecords->map(fn (ChurchServiceSourceRecord $record): array => [
                'source' => $record->source->value,
                'source_key' => $record->source_key,
                'revision_hash' => $record->revision_hash,
                'processing_fingerprint' => $record->processing_fingerprint,
            ])->all()),
            'pre_review_hash' => $review->base_canonical_hash,
            'media_graph' => $mediaGraph,
            'livestream_source_revision' => $this->sourceRevision($livestream),
            'assets' => $this->assets($mediaGraph),
        ];
    }

    /** @return array<string, mixed> */
    private function sourceRevision(ChurchServiceSourceRecord $record): array
    {
        return [
            'source_key' => $record->source_key,
            'revision_hash' => $record->revision_hash,
            'input_hash' => $record->input_hash,
            'batch_hash' => $record->batch_hash,
            'processing_fingerprint' => $record->processing_fingerprint,
            'service_content' => $record->service_content,
            'payload_complete' => $record->payload_complete,
            'captured_at' => $record->captured_at?->toISOString(),
            'assertions' => $record->assertions->sortBy('assertion_key')->map(fn ($assertion): array => [
                'assertion_key' => $assertion->assertion_key,
                'source_position' => $assertion->source_position,
                'evidence_kind' => $assertion->evidence_kind->value,
                'type' => $assertion->type,
                'section_type' => $assertion->section_type?->value,
                'title' => $assertion->title,
                'source_title' => $assertion->source_title,
                'normalized_title' => $assertion->normalized_title,
                'song_canonical_key' => $assertion->song_canonical_key,
                'scripture_reference' => $assertion->scripture_reference,
                'normalized_scripture_key' => $assertion->normalized_scripture_key,
                'start_seconds' => $assertion->start_seconds,
                'end_seconds' => $assertion->end_seconds,
                'confidence' => $assertion->confidence,
                'metadata' => $assertion->metadata,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return list<array{role: string, path: string, size: int, sha256: string}>
     */
    private function assets(array $graph): array
    {
        $paths = collect();

        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'rms_log_path'] as $field) {
            $path = data_get($graph, "run.{$field}");

            if (is_string($path) && $path !== '') {
                $paths->push([
                    'role' => "run_{$field}",
                    'path' => $path,
                    'disk' => $field === 'rms_log_path' ? ServiceArtifactDisk::for($path) : MediaAssetPath::disk(),
                ]);
            }
        }

        foreach (data_get($graph, 'metadata.service_artifacts', []) as $artifact) {
            if (is_array($artifact) && is_string($artifact['path'] ?? null)) {
                $paths->push([
                    'role' => 'service_artifact:'.($artifact['kind'] ?? 'unknown').':'.($artifact['sha256'] ?? hash('sha256', $artifact['path'])),
                    'path' => $artifact['path'],
                    'disk' => ServiceArtifactDisk::for($artifact['path']),
                ]);
            }
        }

        foreach (data_get($graph, 'publications', []) as $publication) {
            foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
                $path = is_array($publication) ? ($publication[$field] ?? null) : null;

                if (is_string($path) && $path !== '') {
                    $paths->push([
                        'role' => 'publication:'.($publication['publication_key'] ?? 'unknown').":{$field}",
                        'path' => $path,
                        'disk' => MediaAssetPath::disk(),
                    ]);
                }
            }
        }

        foreach (data_get($graph, 'sections', []) as $section) {
            foreach (['extracted_video_path', 'extracted_audio_path'] as $field) {
                $path = is_array($section) ? ($section[$field] ?? null) : null;

                if (is_string($path) && $path !== '') {
                    $paths->push([
                        'role' => 'section:'.($section['section_key'] ?? 'unknown').":{$field}",
                        'path' => $path,
                        'disk' => MediaAssetPath::disk(),
                    ]);
                }
            }
        }

        foreach (data_get($graph, 'song_videos', []) as $songVideo) {
            $path = is_array($songVideo) ? ($songVideo['video_file_path'] ?? null) : null;

            if (is_string($path) && $path !== '') {
                $paths->push([
                    'role' => 'song_video:'.($songVideo['section_key'] ?? 'unknown'),
                    'path' => $path,
                    'disk' => MediaAssetPath::disk(),
                ]);
            }
        }

        $manifest = $paths->unique('path')->sortBy('path')->map(function (array $asset): array {
            if (! is_string($asset['disk'] ?? null) || ! is_string($asset['path'] ?? null) || ! is_string($asset['role'] ?? null)) {
                throw new RuntimeException('Staged asset manifest contains an invalid entry.');
            }

            $storage = Storage::disk($asset['disk']);

            if (! $storage->exists($asset['path'])) {
                throw new RuntimeException("Staged asset is missing: {$asset['path']}.");
            }

            $contents = $storage->get($asset['path']);

            if (! is_string($contents)) {
                throw new RuntimeException("Staged asset could not be read: {$asset['path']}.");
            }

            return [
                'role' => $asset['role'],
                'path' => $asset['path'],
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        })->values()->all();

        return array_values($manifest);
    }
}
