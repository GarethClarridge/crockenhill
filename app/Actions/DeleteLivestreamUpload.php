<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChurchServiceItemSource;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewStateService;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\Media\Audio\TranscriptStorageService;
use App\Services\Sermon\SermonStorageService;
use App\Support\Path;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteLivestreamUpload
{
    public function __construct(
        private readonly ChurchServiceReviewStateService $reviewStateService,
        private readonly ChurchServiceReviewSynchronizer $reviewSynchronizer,
        private readonly SermonStorageService $sermonStorageService,
        private readonly TranscriptStorageService $transcriptStorageService,
    ) {}

    /**
     * @return array{
     *     processing_id: string,
     *     deleted_sermons: int,
     *     deleted_projected_items: int,
     *     deleted_services: int,
     *     deleted_service_ids: array<int, int>
     * }
     */
    public function execute(MediaProcessingLog $processingLog): array
    {
        /** @var array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>} $fileTargets */
        $fileTargets = [
            'stored' => [],
            'absolute' => [],
        ];

        $result = DB::transaction(function () use ($processingLog, &$fileTargets): array {
            /** @var MediaProcessingLog|null $lockedLog */
            $lockedLog = MediaProcessingLog::query()
                ->lockForUpdate()
                ->find($processingLog->id);

            if (! $lockedLog instanceof MediaProcessingLog) {
                throw new \InvalidArgumentException('Processing run not found.');
            }

            $this->ensureDeletable($lockedLog);

            $processingId = $lockedLog->processing_id;

            /** @var EloquentCollection<int, ServiceSection> $sections */
            $sections = ServiceSection::query()
                ->where('media_processing_log_id', $lockedLog->id)
                ->lockForUpdate()
                ->get();

            $sermons = $this->loadOwnedSermons($lockedLog);

            /** @var EloquentCollection<int, ChurchServiceItem> $projectedItems */
            $projectedItems = ChurchServiceItem::query()
                ->withTrashed()
                ->where('livestream_processing_id', $processingId)
                ->lockForUpdate()
                ->get();

            $affectedServiceIds = collect([$lockedLog->church_service_id])
                ->merge($projectedItems->pluck('church_service_id'))
                ->filter(fn (mixed $id): bool => is_int($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            $fileTargets = $this->buildFileTargets($lockedLog, $sections, $sermons);

            foreach ($projectedItems as $projectedItem) {
                $projectedItem->forceDelete();
            }

            $lockedLog->delete();

            foreach ($sermons as $sermon) {
                $sermon->delete();
            }

            $deletedServiceIds = [];

            foreach ($affectedServiceIds as $serviceId) {
                $service = ChurchService::query()
                    ->lockForUpdate()
                    ->find($serviceId);

                if (! $service instanceof ChurchService) {
                    continue;
                }

                if ($this->shouldDeleteService($service)) {
                    $deletedServiceIds[] = $service->id;
                    $service->delete();

                    continue;
                }

                $this->refreshServiceMetadata($service);
            }

            return [
                'processing_id' => $processingId,
                'deleted_sermons' => $sermons->count(),
                'deleted_projected_items' => $projectedItems->count(),
                'deleted_services' => count($deletedServiceIds),
                'deleted_service_ids' => $deletedServiceIds,
            ];
        });

        $this->deleteFileTargets($fileTargets);

        Log::info('Livestream upload deleted', $result);

        return $result;
    }

    private function ensureDeletable(MediaProcessingLog $processingLog): void
    {
        if ($processingLog->processing_type !== MediaType::Livestream) {
            throw new \InvalidArgumentException('Only livestream uploads can be deleted.');
        }

        if (in_array($processingLog->status, [
            ProcessingStatus::Pending,
            ProcessingStatus::Started,
            ProcessingStatus::Processing,
        ], true)) {
            throw new \InvalidArgumentException('Cancel the upload before deleting it.');
        }
    }

    /**
     * @return EloquentCollection<int, Sermon>
     */
    private function loadOwnedSermons(MediaProcessingLog $processingLog): EloquentCollection
    {
        /** @var EloquentCollection<int, Sermon> $sermons */
        $sermons = Sermon::query()
            ->where('livestream_processing_id', $processingLog->processing_id)
            ->lockForUpdate()
            ->get();

        return $sermons;
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  EloquentCollection<int, Sermon>  $sermons
     * @return array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}
     */
    private function buildFileTargets(
        MediaProcessingLog $processingLog,
        EloquentCollection $sections,
        EloquentCollection $sermons,
    ): array {
        /** @var array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>} $targets */
        $targets = [
            'stored' => [],
            'absolute' => [],
        ];

        $this->collectProcessingLogFileTargets($processingLog, $targets);

        foreach ($sections as $section) {
            $this->collectSectionFileTargets($section, $targets);
        }

        foreach ($sermons as $sermon) {
            $this->collectSermonFileTargets($sermon, $targets);
        }

        return $targets;
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function collectProcessingLogFileTargets(MediaProcessingLog $processingLog, array &$targets): void
    {
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
        $sermonDisk = (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));
        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);

        $this->addRelativeOrAbsoluteTarget($targets, $tempDisk, $processingLog->source_file_path);
        $this->addRelativeOrAbsoluteTarget($targets, $tempDisk, $processingLog->rms_log_path);
        $this->addRelativeOrAbsoluteTarget($targets, $tempDisk, $processingLog->enhanced_audio_file_path);
        $this->addStoredTarget($targets, $sermonDisk, $processingLog->audio_file_path);
        $this->addStoredTarget($targets, $sermonDisk, $processingLog->video_file_path);
        $this->addStoredTarget($targets, $transcriptDisk, $processingLog->transcript_file_path);
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function collectSectionFileTargets(ServiceSection $section, array &$targets): void
    {
        $videoPath = $section->extracted_video_path;
        if (is_string($videoPath) && $videoPath !== '') {
            $this->addStoredTarget($targets, $section->extractedAssetDisk(), $videoPath);
        }

        $audioPath = $section->extracted_audio_path;
        if (is_string($audioPath) && $audioPath !== '') {
            $this->addStoredTarget($targets, $section->extractedAssetDisk(), $audioPath);
        }
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function collectSermonFileTargets(Sermon $sermon, array &$targets): void
    {
        if ($sermon->audio_file_path !== '') {
            $audio = $this->sermonStorageService->getSermonFileInfo($sermon);
            $this->addStoredTarget($targets, $audio['disk'], $audio['path']);
        }

        $this->addStoredTarget(
            $targets,
            (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local')),
            $sermon->video_file_path
        );

        $this->addTranscriptTarget($targets, $sermon->transcript_file_path);

        $thumbnailPaths = array_unique(array_filter([
            $sermon->thumbnail_file_path,
            $sermon->plain_thumbnail_file_path,
            $sermon->card_thumbnail_file_path,
            ...$this->thumbnailCandidatePaths($sermon),
        ]));

        foreach ($thumbnailPaths as $path) {
            $this->addStoredTarget(
                $targets,
                $this->sermonStorageService->resolveThumbnailDisk($path),
                $path
            );
        }
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function addTranscriptTarget(array &$targets, ?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        foreach ($this->transcriptStorageService->getTranscriptReadDisks() as $disk) {
            try {
                if (! Storage::disk($disk)->exists($path)) {
                    continue;
                }

                $this->addStoredTarget($targets, $disk, $path);

                return;
            } catch (\Throwable) {
                continue;
            }
        }

        $fallbackDisk = (string) config('media-processing.storage.transcript_disk', config('filesystems.default', 'local'));
        $this->addStoredTarget($targets, $fallbackDisk, $path);
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function addRelativeOrAbsoluteTarget(array &$targets, string $disk, ?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        if ($this->isAbsolutePath($path)) {
            $this->addAbsoluteTarget($targets, $path);

            return;
        }

        $this->addStoredTarget($targets, $disk, $path);
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function addStoredTarget(array &$targets, string $disk, ?string $path): void
    {
        if (! is_string($path) || trim($path) === '' || Path::isUnsafe(trim($path))) {
            return;
        }

        $normalizedPath = trim($path);
        $key = $disk.'::'.$normalizedPath;

        $targets['stored'][$key] = [
            'disk' => $disk,
            'path' => $normalizedPath,
        ];
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function addAbsoluteTarget(array &$targets, string $path): void
    {
        if (! $this->isSafeAbsoluteDeletionPath($path)) {
            return;
        }

        $targets['absolute'][$path] = $path;
    }

    /**
     * @param  array{stored: array<string, array{disk: string, path: string}>, absolute: array<string, string>}  $targets
     */
    private function deleteFileTargets(array $targets): void
    {
        foreach ($targets['stored'] as $target) {
            try {
                if (Storage::disk($target['disk'])->exists($target['path'])) {
                    Storage::disk($target['disk'])->delete($target['path']);
                }
            } catch (\Throwable $throwable) {
                Log::warning('Failed to delete stored livestream upload artifact', [
                    'disk' => $target['disk'],
                    'path' => $target['path'],
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        foreach ($targets['absolute'] as $path) {
            try {
                if (file_exists($path)) {
                    @unlink($path);
                }
            } catch (\Throwable $throwable) {
                Log::warning('Failed to delete absolute livestream upload artifact', [
                    'path' => $path,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }

    private function shouldDeleteService(ChurchService $service): bool
    {
        return $service->source === MediaType::Livestream->value
            && ! $service->items()->exists()
            && ! $service->mediaProcessingLogs()->exists();
    }

    private function refreshServiceMetadata(ChurchService $service): void
    {
        $hasRemainingLivestreamItems = $service->items()
            ->where('source', ChurchServiceItemSource::Livestream->value)
            ->exists();

        if ($hasRemainingLivestreamItems) {
            return;
        }

        $importMetadata = $service->import_metadata?->toArray() ?? [];
        unset($importMetadata['livestream_projection']);

        $service->forceFill([
            'import_metadata' => $importMetadata,
            ...$this->reviewStateService->normalizedReviewColumns($importMetadata),
        ])->saveQuietly();

        $service = $service->fresh() ?? $service;

        $remainingLogIds = $service->mediaProcessingLogs()->pluck('id');
        /** @var EloquentCollection<int, ServiceSection> $remainingSections */
        $remainingSections = $remainingLogIds->isEmpty()
            ? new EloquentCollection
            : ServiceSection::query()
                ->whereIn('media_processing_log_id', $remainingLogIds)
                ->get();

        $reviewTriggers = $remainingSections->isEmpty()
            ? []
            : (is_array($importMetadata['review_triggers'] ?? null)
                ? array_values(array_filter($importMetadata['review_triggers'], 'is_string'))
                : []);

        $this->reviewSynchronizer->sync($service, $remainingSections, $reviewTriggers);
    }

    /**
     * @return array<int, string>
     */
    private function thumbnailCandidatePaths(Sermon $sermon): array
    {
        $paths = [];

        foreach ($sermon->thumbnail_candidates as $candidate) {
            if (isset($candidate['overlay_path'])) {
                $paths[] = $candidate['overlay_path'];
            }

            if (isset($candidate['card_path'])) {
                $paths[] = $candidate['card_path'];
            }

            $paths[] = $candidate['plain_path'];
        }

        return $paths;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function isSafeAbsoluteDeletionPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        $storage = str_replace('\\', '/', storage_path());
        $temporary = str_replace('\\', '/', sys_get_temp_dir());

        return str_starts_with($normalized, $storage.'/')
            || str_starts_with($normalized, $temporary.'/');
    }
}
