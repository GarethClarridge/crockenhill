<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceSourceRecord;
use App\Models\MediaProcessingLog;
use App\Support\CanonicalJson;
use App\Support\MediaAssetPath;
use App\Support\ServiceArtifactDisk;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoricProcessingResultBundleExporter
{
    public function __construct(
        private readonly HistoricProcessingResultInventory $inventory,
        private readonly HistoricProcessingResultReadinessService $readiness,
        private readonly HistoricProcessingResultBundle $bundles,
        private readonly HistoricProcessingResultBundleFiles $files,
        private readonly HistoricStagingGuard $stagingGuard,
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
        $found = MediaProcessingLog::query()->whereIn('processing_id', $processingIds)->count();

        if ($processingIds === [] || $found !== count($processingIds)) {
            throw new RuntimeException('Every selected historic processing run must exist exactly once.');
        }

        /**
         * Stream the batch rather than materialising every run and its eager-loaded
         * service graph at once. Each run's models fall out of scope as the cursor
         * advances, so peak memory tracks the largest single run, not the batch.
         *
         * @var array<string, array<string, mixed>> $built
         */
        $built = [];

        MediaProcessingLog::query()
            ->with(['churchService.reviewSessions', 'churchService.sourceRecords.assertions'])
            ->whereIn('processing_id', $processingIds)
            ->lazyById()
            ->each(function (MediaProcessingLog $run) use (&$built): void {
                $built[$run->processing_id] = $this->service($run);
            });

        $services = [];

        foreach ($processingIds as $processingId) {
            if (! isset($built[$processingId])) {
                throw new RuntimeException("Historic processing run {$processingId} could not be loaded.");
            }

            $services[] = $built[$processingId];
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
            'livestream_source_revision' => $this->sourceRevision($livestream, $run, $mediaGraph),
            'assets' => $this->assets($mediaGraph),
        ];
    }

    /**
     * @param  array<string, mixed>  $mediaGraph
     * @return array<string, mixed>
     */
    private function sourceRevision(
        ChurchServiceSourceRecord $record,
        MediaProcessingLog $run,
        array $mediaGraph,
    ): array {
        return [
            'source_key' => $record->source_key,
            'revision_hash' => $record->revision_hash,
            'input_hash' => $record->input_hash,
            'batch_hash' => $record->batch_hash,
            'processing_fingerprint' => $record->processing_fingerprint,
            'service_content' => $record->service_content,
            'payload_complete' => $record->payload_complete,
            'captured_at' => $record->captured_at?->toISOString(),
            'assertions' => $record->assertions->sortBy('assertion_key')->map(function ($assertion) use ($run, $mediaGraph): array {
                $metadata = $assertion->metadata ?? [];
                $localSectionId = $metadata['livestream_service_section_id'] ?? null;
                unset($metadata['livestream_service_section_id'], $metadata['oos_item_id']);

                if (is_numeric($localSectionId)) {
                    $section = $run->serviceSections->firstWhere('id', (int) $localSectionId);

                    if ($section === null) {
                        throw new RuntimeException("Livestream assertion {$assertion->assertion_key} references a section outside its run.");
                    }

                    $portableSection = null;

                    foreach ($mediaGraph['sections'] as $candidate) {
                        if (is_array($candidate) && ($candidate['section_order'] ?? null) === $section->section_order) {
                            $portableSection = $candidate;
                            break;
                        }
                    }

                    if (! is_array($portableSection)) {
                        throw new RuntimeException("Livestream assertion {$assertion->assertion_key} has no portable section identity.");
                    }

                    $metadata['livestream_service_section_key'] = $portableSection['section_key'];
                }

                return [
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
                    'confidence' => $assertion->confidence === null ? null : (float) $assertion->confidence,
                    'metadata' => $metadata === [] ? null : $metadata,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}>
     */
    private function assets(array $graph): array
    {
        $references = [];
        $roleSources = [];

        $addReference = function (string $role, string $path, string $disk, string $kind) use (&$references, &$roleSources): void {
            $this->guardAssetPath($path);
            $source = "{$disk}\0{$path}";

            if (isset($roleSources[$role]) && $roleSources[$role] !== $source) {
                throw new RuntimeException("Historic asset role {$role} resolves to more than one source.");
            }

            $roleSources[$role] = $source;
            $referenceKey = $source;

            if (isset($references[$referenceKey])) {
                $references[$referenceKey]['roles'][$role] = true;
                $references[$referenceKey]['kind'] = $this->mergeAssetKinds($references[$referenceKey]['kind'], $kind);

                return;
            }

            $references[$referenceKey] = [
                'path' => $path,
                'disk' => $disk,
                'kind' => $kind,
                'roles' => [$role => true],
            ];
        };

        foreach (HistoricProcessingResultAssetRole::RUN_FIELDS as $field) {
            $path = data_get($graph, "run.{$field}");

            if (is_string($path) && $path !== '') {
                $addReference(
                    HistoricProcessingResultAssetRole::run($field),
                    $path,
                    $field === 'rms_log_path' ? ServiceArtifactDisk::for($path) : MediaAssetPath::disk(),
                    $this->assetKind($field),
                );
            }
        }

        foreach (data_get($graph, 'metadata.service_artifacts', []) as $artifact) {
            if (is_array($artifact) && is_string($artifact['path'] ?? null)) {
                $addReference(
                    HistoricProcessingResultAssetRole::serviceArtifact($artifact),
                    $artifact['path'],
                    ServiceArtifactDisk::for($artifact['path']),
                    HistoricProcessingResultAssetRole::serviceArtifactKind($artifact['kind'] ?? null),
                );
            }
        }

        foreach (data_get($graph, 'publications', []) as $publication) {
            if (! is_array($publication) || ! is_string($publication['publication_key'] ?? null)) {
                continue;
            }

            $publicationKey = $publication['publication_key'];

            foreach (HistoricProcessingResultAssetRole::PUBLICATION_FIELDS as $field) {
                $path = $publication[$field] ?? null;

                if (is_string($path) && $path !== '') {
                    $addReference(
                        HistoricProcessingResultAssetRole::publicationField($publicationKey, $field),
                        $path,
                        MediaAssetPath::disk(),
                        $this->assetKind($field),
                    );
                }
            }

            $thumbnailMetadata = $publication['thumbnail_metadata'] ?? null;

            if (is_array($thumbnailMetadata)) {
                foreach (HistoricProcessingResultAssetRole::THUMBNAIL_METADATA_FIELDS as $field) {
                    $path = $thumbnailMetadata[$field] ?? null;

                    if (is_string($path) && $path !== '') {
                        $addReference(
                            HistoricProcessingResultAssetRole::publicationThumbnailMetadata($publicationKey, $field),
                            $path,
                            MediaAssetPath::disk(),
                            'thumbnail',
                        );
                    }
                }

                foreach (array_values($thumbnailMetadata['thumbnail_candidates'] ?? []) as $candidateIndex => $candidate) {
                    if (! is_array($candidate)) {
                        continue;
                    }

                    foreach (HistoricProcessingResultAssetRole::THUMBNAIL_CANDIDATE_FIELDS as $field) {
                        $path = $candidate[$field] ?? null;

                        if (is_string($path) && $path !== '') {
                            $addReference(
                                HistoricProcessingResultAssetRole::publicationThumbnailCandidate(
                                    $publicationKey,
                                    $candidateIndex,
                                    $field,
                                ),
                                $path,
                                MediaAssetPath::disk(),
                                'thumbnail',
                            );
                        }
                    }
                }
            }
        }

        foreach (data_get($graph, 'sections', []) as $section) {
            if (! is_array($section) || ! is_string($section['section_key'] ?? null)) {
                continue;
            }

            foreach (HistoricProcessingResultAssetRole::SECTION_FIELDS as $field) {
                $path = $section[$field] ?? null;

                if (is_string($path) && $path !== '') {
                    $addReference(
                        HistoricProcessingResultAssetRole::section($section['section_key'], $field),
                        $path,
                        MediaAssetPath::disk(),
                        $this->assetKind($field),
                    );
                }
            }
        }

        foreach (data_get($graph, 'song_videos', []) as $songVideo) {
            if (! is_array($songVideo) || ! is_string($songVideo['section_key'] ?? null)) {
                continue;
            }

            $path = $songVideo[HistoricProcessingResultAssetRole::SONG_VIDEO_FIELD] ?? null;

            if (is_string($path) && $path !== '') {
                $addReference(
                    HistoricProcessingResultAssetRole::songVideo($songVideo['section_key']),
                    $path,
                    MediaAssetPath::disk(),
                    'video',
                );
            }
        }

        $this->stagingGuard->assertExportSourcesAreStaged(
            array_values(array_unique(array_column($references, 'disk'))),
        );

        uasort($references, fn (array $left, array $right): int => [$left['path'], $left['disk']] <=> [$right['path'], $right['disk']]);
        $content = [];

        foreach ($references as $reference) {
            $storage = Storage::disk($reference['disk']);

            if (! $storage->exists($reference['path'])) {
                throw new RuntimeException("Staged asset is missing: {$reference['path']}.");
            }

            $size = $storage->size($reference['path']);
            $sha256 = $this->hash($storage, $reference['path']);
            $contentKey = "{$size}:{$sha256}";
            $roles = array_keys($reference['roles']);

            if (isset($content[$contentKey])) {
                $content[$contentKey]['roles'] = array_values(array_unique([
                    ...$content[$contentKey]['roles'],
                    ...$roles,
                ]));
                sort($content[$contentKey]['roles']);
                $content[$contentKey]['kind'] = $this->mergeAssetKinds(
                    $content[$contentKey]['kind'],
                    $reference['kind'],
                );

                continue;
            }

            sort($roles);
            $content[$contentKey] = [
                'path' => $reference['path'],
                'size' => $size,
                'sha256' => $sha256,
                'kind' => $reference['kind'],
                'roles' => $roles,
            ];
        }

        return array_values($content);
    }

    private function assetKind(string $field): string
    {
        return match (true) {
            str_contains($field, 'audio') => 'audio',
            str_contains($field, 'video') => 'video',
            str_contains($field, 'transcript') => 'transcript',
            str_contains($field, 'thumbnail') => 'thumbnail',
            default => 'artifact',
        };
    }

    private function mergeAssetKinds(string $left, string $right): string
    {
        return $left === $right ? $left : 'mixed';
    }

    private function guardAssetPath(string $path): void
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '../')
            || str_contains($path, '\\')
        ) {
            throw new RuntimeException("Unsafe historic asset path: {$path}.");
        }
    }

    private function hash(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Staged asset could not be opened: {$path}.");
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
