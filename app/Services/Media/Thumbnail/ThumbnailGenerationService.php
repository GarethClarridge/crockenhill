<?php

declare(strict_types=1);

namespace App\Services\Media\Thumbnail;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Media\Video\FrameQualityScorer;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Sermon\SermonExposurePolicy;
use App\Support\HistoricImportAssetPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;

/**
 * @phpstan-type ThumbnailCandidate array{
 *     id: string,
 *     timestamp: float,
 *     score: float,
 *     plain_path: string,
 *     card_path?: string|null,
 *     overlay_path?: string|null,
 *     composition_mode?: string|null,
 *     foreground_extraction_method?: string|null,
 *     foreground_bounds?: array<string, int>,
 *     foreground_coverage?: float|null
 * }
 * @phpstan-type RenderedThumbnailCandidate array{
 *     id: string,
 *     timestamp: float,
 *     score: float,
 *     plain_path: string,
 *     card_path: non-empty-string,
 *     overlay_path: non-empty-string,
 *     composition_mode: string,
 *     foreground_extraction_method?: string|null,
 *     foreground_bounds?: array<string, int>,
 *     foreground_coverage?: float|null
 * }
 * @phpstan-type CompositionMetadata array{
 *     composition_mode: string,
 *     foreground_extraction_method?: string|null,
 *     foreground_bounds?: array{x:int,y:int,width:int,height:int},
 *     foreground_coverage?: float
 * }
 * @phpstan-type ForegroundLayer array{
 *     image: ImageInterface,
 *     coverage: float,
 *     bounds: array{x:int,y:int,width:int,height:int},
 *     method: string
 * }
 * @phpstan-type RenderedAssets array{
 *     overlay_temp_path: string,
 *     card_temp_path: string,
 *     composition_metadata: CompositionMetadata
 * }
 */
class ThumbnailGenerationService
{
    /** Number of frames to extract as potential thumbnail candidates. */
    public const int CANDIDATE_COUNT = 5;

    /** Target width for generated web-optimised thumbnails (720p). */
    public const int WEB_WIDTH = 1280;

    /** Target height for generated web-optimised thumbnails (720p). */
    public const int WEB_HEIGHT = 720;

    /** WebP encoding quality (0-100) for generated thumbnails. */
    public const int WEB_QUALITY = 85;

    private string $storageDisk;

    private string $storagePath;

    private string $tempDisk;

    private string $tempPath;

    private readonly FrameExtractionService $frameExtractionService;

    public function __construct(
        FrameExtractionService $frameExtractionService,
        private readonly StorageAdapterHelper $storageHelper,
        private readonly ThumbnailForegroundExtractionService $foregroundExtractor,
        private readonly ThumbnailCanvasComposer $canvasComposer,
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly FrameQualityScorer $frameQualityScorer = new FrameQualityScorer,
    ) {
        $this->storageDisk = (string) config('thumbnail-generation.storage.disk', 'public');
        $this->storagePath = (string) config('thumbnail-generation.storage.path', 'sermons/thumbnails');
        $this->tempDisk = (string) config('thumbnail-generation.processing.temp_disk', 'local');
        $this->tempPath = (string) config('thumbnail-generation.processing.temp_path', 'temp/thumbnails');
        $this->frameExtractionService = $frameExtractionService;
    }

    /**
     * Generate branded thumbnail candidates from a sermon video.
     *
     * Coordinates the complete thumbnail pipeline: quality validation, frame
     * extraction for multiple candidates, quality scoring, and asset rendering
     * (plain, branded overlay, and social card) for the best candidate.
     *
     * @param  Sermon  $sermon  The sermon record to associate with
     * @param  string  $videoPath  Absolute path or relative storage path to source video
     * @param  string|null  $disk  Optional disk name if videoPath is relative
     * @return ThumbnailResult Result containing the primary thumbnail path and candidate metadata
     */
    public function generateThumbnail(Sermon $sermon, string $videoPath, ?string $disk = null): ThumbnailResult
    {
        $tempVideoPath = null;

        try {
            if (! config('thumbnail-generation.enabled')) {
                return ThumbnailResult::skipped('Thumbnail generation is disabled');
            }

            if (! $this->videoThumbnailAllowed($sermon)) {
                return ThumbnailResult::skipped('Video quality verdict does not allow public thumbnail generation');
            }

            if (! $this->frameExtractionService->videoFileExists($videoPath, $disk)) {
                return ThumbnailResult::skipped('Video file not found: '.$videoPath);
            }

            $localVideoPath = $this->frameExtractionService->ensureLocalVideoPath($videoPath, $disk);

            if ($disk && $this->storageHelper->isS3CompatibleDisk(Storage::disk($disk))) {
                $tempVideoPath = $localVideoPath;
            }

            $metadata = $this->frameExtractionService->getVideoMetadata($localVideoPath);

            if ((float) $metadata['duration'] < (float) config('thumbnail-generation.extraction.min_video_duration', 420)) {
                $this->frameExtractionService->cleanupDownloadedVideo($tempVideoPath);

                return ThumbnailResult::skipped('Video too short for thumbnail generation');
            }

            $candidateTimestamps = $this->frameExtractionService->calculateCandidateTimestamps(
                (float) $metadata['duration'],
                self::CANDIDATE_COUNT,
            );

            /** @var list<ThumbnailCandidate> $successfulCandidates */
            $successfulCandidates = [];

            foreach ($candidateTimestamps as $index => $timestamp) {
                $candidateId = 'candidate-'.($index + 1);
                $baseFramePath = $this->frameExtractionService->extractBaseFrame($localVideoPath, $timestamp);

                if (! $baseFramePath) {
                    Log::warning('Skipping thumbnail candidate after frame extraction failure', [
                        'sermon_id' => $sermon->id,
                        'candidate_id' => $candidateId,
                        'timestamp' => $timestamp,
                    ]);

                    continue;
                }

                try {
                    $candidate = $this->buildThumbnailCandidate($sermon, $candidateId, $timestamp, $baseFramePath);

                    if ($candidate !== null) {
                        $successfulCandidates[] = $candidate;
                    }
                } finally {
                    $this->cleanupTempFile($baseFramePath);
                }
            }

            $this->frameExtractionService->cleanupDownloadedVideo($tempVideoPath);

            if ($successfulCandidates === []) {
                return ThumbnailResult::failed('Failed to create any thumbnail candidates');
            }

            usort($successfulCandidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

            $selectedCandidate = $this->renderAssetsForCandidate($sermon, $successfulCandidates[0]);
            if ($selectedCandidate === null) {
                return ThumbnailResult::failed('Failed to render the selected thumbnail candidate');
            }

            $successfulCandidates = $this->replaceCandidate($successfulCandidates, $selectedCandidate);

            $resultMetadata = [
                'timestamp' => $selectedCandidate['timestamp'],
                'video_duration' => $metadata['duration'],
                'video_resolution' => [
                    'width' => $metadata['width'],
                    'height' => $metadata['height'],
                ],
                'thumbnail_sizes' => [
                    'web' => ['width' => self::WEB_WIDTH, 'height' => self::WEB_HEIGHT, 'quality' => self::WEB_QUALITY],
                ],
                'generated_at' => now()->toISOString(),
                'plain_thumbnail_path' => $selectedCandidate['plain_path'],
                'card_thumbnail_path' => $selectedCandidate['card_path'],
                'overlay_thumbnail_path' => $selectedCandidate['overlay_path'],
                'selected_thumbnail_candidate_id' => $selectedCandidate['id'],
                'thumbnail_candidates' => $successfulCandidates,
            ];

            $resultMetadata = array_merge($resultMetadata, $this->candidateCompositionMetadata($selectedCandidate));

            return ThumbnailResult::success($selectedCandidate['overlay_path'], $resultMetadata);
        } catch (\Throwable $e) {
            $this->frameExtractionService->cleanupDownloadedVideo($tempVideoPath);

            Log::warning('Thumbnail generation failed, skipping', [
                'sermon_id' => $sermon->id,
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);

            return ThumbnailResult::skipped($e->getMessage());
        }
    }

    /**
     * Create a standalone branded thumbnail from a single frame.
     *
     * Resizes the frame, extracts the subject (foreground), and composes it onto
     * a branded background with text.
     *
     * @param  Sermon  $sermon  The sermon record for branding text
     * @param  string  $baseFramePath  Absolute path to the extracted source frame
     * @return array{path: string, composition_metadata: CompositionMetadata}|null
     */
    public function createBrandedThumbnail(Sermon $sermon, string $baseFramePath): ?array
    {
        try {
            $image = $this->createResizedBaseImage($baseFramePath);
            $foreground = $this->extractForegroundLayer($image);
            $mainImage = $this->buildOverlayCanvas($sermon, $foreground);

            return [
                'path' => $this->saveTemporaryThumbnail($mainImage, 'thumbnail_overlay'),
                'composition_metadata' => $this->buildCompositionMetadata($foreground),
            ];
        } catch (\Throwable $e) {
            Log::error('Branded thumbnail creation failed', [
                'sermon_id' => $sermon->id,
                'base_frame_path' => $baseFramePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return ThumbnailCandidate|null
     */
    private function buildThumbnailCandidate(Sermon $sermon, string $candidateId, float $timestamp, string $baseFramePath): ?array
    {
        try {
            $plainThumbnailPath = $this->createPlainThumbnail($baseFramePath);
            if (! is_string($plainThumbnailPath) || $plainThumbnailPath === '') {
                Log::warning('Skipping thumbnail candidate after plain thumbnail generation failure', [
                    'sermon_id' => $sermon->id,
                    'candidate_id' => $candidateId,
                    'timestamp' => $timestamp,
                ]);

                return null;
            }

            try {
                $plainPath = $this->storeThumbnail($plainThumbnailPath, $sermon, "{$candidateId}_plain");
                if (! is_string($plainPath) || $plainPath === '') {
                    return null;
                }

                return [
                    'id' => $candidateId,
                    'timestamp' => round($timestamp, 3),
                    'score' => $this->scoreFrameQuality($baseFramePath),
                    'plain_path' => $plainPath,
                ];
            } finally {
                $this->cleanupTempFile($plainThumbnailPath);
            }
        } catch (\Throwable $e) {
            Log::warning('Thumbnail candidate generation failed', [
                'sermon_id' => $sermon->id,
                'candidate_id' => $candidateId,
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a resized, unbranded thumbnail from a single frame.
     *
     * @param  string  $baseFramePath  Absolute path to the source frame
     * @return string|null Absolute path to the temporary plain thumbnail
     */
    public function createPlainThumbnail(string $baseFramePath): ?string
    {
        try {
            $image = $this->createResizedBaseImage($baseFramePath);

            return $this->saveTemporaryThumbnail($image, 'thumbnail_plain');
        } catch (\Throwable $e) {
            Log::error('Plain thumbnail creation failed', [
                'base_frame_path' => $baseFramePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Render the full set of assets for a previously extracted candidate.
     *
     * Used when an operator selects a specific candidate from the extracted set.
     * Renders the overlay and social card assets for the selected option.
     *
     * @param  Sermon  $sermon  The sermon record
     * @param  string  $candidateId  The ID of the candidate to render (e.g., 'candidate-1')
     * @return ThumbnailResult Result containing the primary thumbnail path and metadata
     */
    public function renderSelectedThumbnailCandidate(Sermon $sermon, string $candidateId): ThumbnailResult
    {
        /** @var ThumbnailCandidate|null $candidate */
        $candidate = $sermon->findThumbnailCandidate($candidateId);

        if ($candidate === null) {
            return ThumbnailResult::skipped('Thumbnail option not found');
        }

        $selectedCandidate = $this->renderAssetsForCandidate($sermon, $candidate);
        if ($selectedCandidate === null) {
            return ThumbnailResult::failed('Failed to render the selected thumbnail candidate');
        }

        /** @var list<ThumbnailCandidate> $existingCandidates */
        $existingCandidates = $sermon->thumbnail_candidates;

        $thumbnailCandidates = $this->replaceCandidate($existingCandidates, $selectedCandidate);
        $metadata = $this->buildSelectedCandidateMetadata(
            $sermon->thumbnail_metadata?->toArray() ?? [],
            $selectedCandidate,
            $thumbnailCandidates,
        );

        return ThumbnailResult::success($selectedCandidate['overlay_path'], $metadata);
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     * @return CompositionMetadata
     */
    private function buildCompositionMetadata(?array $foreground): array
    {
        if ($foreground === null) {
            return [
                'composition_mode' => 'flat_fallback',
            ];
        }

        return [
            'composition_mode' => 'layered_subject',
            'foreground_extraction_method' => $foreground['method'],
            'foreground_bounds' => $foreground['bounds'],
            'foreground_coverage' => round($foreground['coverage'], 4),
        ];
    }

    /**
     * @param  ThumbnailCandidate|RenderedThumbnailCandidate  $candidate
     * @return CompositionMetadata
     */
    private function candidateCompositionMetadata(array $candidate): array
    {
        $metadata = [
            'composition_mode' => $candidate['composition_mode'] ?? 'flat_fallback',
        ];

        if (isset($candidate['foreground_extraction_method'])) {
            $metadata['foreground_extraction_method'] = $candidate['foreground_extraction_method'];
        }

        if (
            isset($candidate['foreground_bounds']['x'], $candidate['foreground_bounds']['y'], $candidate['foreground_bounds']['width'], $candidate['foreground_bounds']['height'])
        ) {
            $metadata['foreground_bounds'] = [
                'x' => $candidate['foreground_bounds']['x'],
                'y' => $candidate['foreground_bounds']['y'],
                'width' => $candidate['foreground_bounds']['width'],
                'height' => $candidate['foreground_bounds']['height'],
            ];
        }

        if (is_float($candidate['foreground_coverage'] ?? null)) {
            $metadata['foreground_coverage'] = $candidate['foreground_coverage'];
        }

        return $metadata;
    }

    /**
     * @param  ThumbnailCandidate  $candidate
     * @return RenderedThumbnailCandidate|null
     */
    private function renderAssetsForCandidate(Sermon $sermon, array $candidate): ?array
    {
        $hasOverlay = isset($candidate['overlay_path']) && $candidate['overlay_path'] !== '' && $this->storedThumbnailExists($candidate['overlay_path']);
        $hasCard = isset($candidate['card_path']) && $candidate['card_path'] !== '' && $this->storedThumbnailExists($candidate['card_path']);

        if ($hasOverlay && $hasCard) {
            /** @var non-empty-string $overlayPath */
            $overlayPath = $candidate['overlay_path'];
            /** @var non-empty-string $cardPath */
            $cardPath = $candidate['card_path'];

            return $this->buildRenderedCandidate(
                $candidate,
                $overlayPath,
                $cardPath,
                $this->candidateCompositionMetadata($candidate),
            );
        }

        $renderedAssets = $this->createRenderedAssetsFromStoredPlainPath($sermon, $candidate['plain_path']);
        if ($renderedAssets === null) {
            return null;
        }

        try {
            $overlayPath = $hasOverlay && isset($candidate['overlay_path']) ? $candidate['overlay_path'] : $this->storeThumbnail(
                $renderedAssets['overlay_temp_path'],
                $sermon,
                "{$candidate['id']}_overlay",
            );

            $cardPath = $hasCard && isset($candidate['card_path']) ? $candidate['card_path'] : $this->storeThumbnail(
                $renderedAssets['card_temp_path'],
                $sermon,
                "{$candidate['id']}_card",
            );

            if (! is_string($overlayPath) || $overlayPath === '' || ! is_string($cardPath) || $cardPath === '') {
                return null;
            }

            return $this->buildRenderedCandidate(
                $candidate,
                $overlayPath,
                $cardPath,
                $renderedAssets['composition_metadata'],
            );
        } finally {
            $this->cleanupTempFile($renderedAssets['overlay_temp_path']);
            $this->cleanupTempFile($renderedAssets['card_temp_path']);
        }
    }

    /**
     * @param  list<ThumbnailCandidate|RenderedThumbnailCandidate>  $candidates
     * @param  RenderedThumbnailCandidate  $replacement
     * @return list<ThumbnailCandidate|RenderedThumbnailCandidate>
     */
    private function replaceCandidate(array $candidates, array $replacement): array
    {
        return array_map(
            static fn (array $candidate): array => $candidate['id'] === $replacement['id'] ? $replacement : $candidate,
            $candidates,
        );
    }

    /**
     * @param  ThumbnailCandidate  $candidate
     * @param  non-empty-string  $overlayPath
     * @param  non-empty-string  $cardPath
     * @param  CompositionMetadata  $compositionMetadata
     * @return RenderedThumbnailCandidate
     */
    private function buildRenderedCandidate(array $candidate, string $overlayPath, string $cardPath, array $compositionMetadata): array
    {
        $renderedCandidate = [
            'id' => $candidate['id'],
            'timestamp' => $candidate['timestamp'],
            'score' => $candidate['score'],
            'plain_path' => $candidate['plain_path'],
            'card_path' => $cardPath,
            'overlay_path' => $overlayPath,
            'composition_mode' => $compositionMetadata['composition_mode'],
        ];

        if (array_key_exists('foreground_extraction_method', $compositionMetadata)) {
            $renderedCandidate['foreground_extraction_method'] = $compositionMetadata['foreground_extraction_method'];
        }

        if (array_key_exists('foreground_bounds', $compositionMetadata)) {
            $renderedCandidate['foreground_bounds'] = $compositionMetadata['foreground_bounds'];
        }

        if (array_key_exists('foreground_coverage', $compositionMetadata)) {
            $renderedCandidate['foreground_coverage'] = $compositionMetadata['foreground_coverage'];
        }

        /** @var RenderedThumbnailCandidate $renderedCandidate */
        return $renderedCandidate;
    }

    /**
     * @param  array<string, mixed>  $baseMetadata
     * @param  RenderedThumbnailCandidate  $selectedCandidate
     * @param  list<ThumbnailCandidate|RenderedThumbnailCandidate>  $thumbnailCandidates
     * @return array<string, mixed>
     */
    private function buildSelectedCandidateMetadata(array $baseMetadata, array $selectedCandidate, array $thumbnailCandidates): array
    {
        $metadata = $baseMetadata;
        $metadata['timestamp'] = $selectedCandidate['timestamp'];
        $metadata['plain_thumbnail_path'] = $selectedCandidate['plain_path'];
        $metadata['card_thumbnail_path'] = $selectedCandidate['card_path'];
        $metadata['overlay_thumbnail_path'] = $selectedCandidate['overlay_path'];
        $metadata['selected_thumbnail_candidate_id'] = $selectedCandidate['id'];
        $metadata['thumbnail_candidates'] = $thumbnailCandidates;

        unset(
            $metadata['composition_mode'],
            $metadata['foreground_extraction_method'],
            $metadata['foreground_bounds'],
            $metadata['foreground_coverage'],
        );

        return array_merge($metadata, $this->candidateCompositionMetadata($selectedCandidate));
    }

    /**
     * @return RenderedAssets|null
     */
    private function createRenderedAssetsFromStoredPlainPath(Sermon $sermon, string $plainPath): ?array
    {
        $disk = $this->storageDisk;
        if (! Storage::disk($disk)->exists($plainPath)) {
            Log::warning('Plain thumbnail not found for overlay rendering', [
                'sermon_id' => $sermon->id,
                'plain_path' => $plainPath,
                'disk' => $disk,
            ]);

            return null;
        }

        try {
            $image = Image::decode(Storage::disk($disk)->path($plainPath));

            return $this->createRenderedAssetsFromImage($sermon, $image);
        } catch (\Throwable $e) {
            Log::error('Branded thumbnail creation from stored plain image failed', [
                'sermon_id' => $sermon->id,
                'plain_path' => $plainPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    private function buildOverlayCanvas(Sermon $sermon, ?array $foreground): ImageInterface
    {
        if (config('thumbnail-generation.theme.style', 'centered') === 'classic') {
            return $this->canvasComposer->buildMainThumbnailCanvas($sermon, $foreground);
        }

        return $this->canvasComposer->buildCenteredThumbnailCanvas($sermon, $foreground);
    }

    /**
     * @return RenderedAssets|null
     */
    private function createRenderedAssetsFromImage(Sermon $sermon, ImageInterface $image): ?array
    {
        try {
            $foreground = $this->extractForegroundLayer($image);
            $overlayImage = $this->buildOverlayCanvas($sermon, $foreground);
            $cardImage = $this->canvasComposer->buildCardThumbnailCanvas($sermon, $foreground);

            return [
                'overlay_temp_path' => $this->saveTemporaryThumbnail($overlayImage, 'thumbnail_overlay'),
                'card_temp_path' => $this->saveTemporaryThumbnail($cardImage, 'thumbnail_card'),
                'composition_metadata' => $this->buildCompositionMetadata($foreground),
            ];
        } catch (\Throwable $e) {
            Log::error('Branded thumbnail composition failed', [
                'sermon_id' => $sermon->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return ForegroundLayer|null
     */
    private function extractForegroundLayer(ImageInterface $image): ?array
    {
        $foreground = $this->foregroundExtractor->extract($image);

        if ($foreground === null) {
            return null;
        }

        try {
            return [
                'image' => $this->canvasComposer->cropForegroundToBounds($foreground['image'], $foreground['bounds']),
                'coverage' => $foreground['coverage'],
                'bounds' => $foreground['bounds'],
                'method' => $foreground['method'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Foreground cropping failed, falling back to flat composition', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store a generated thumbnail in its final storage location.
     *
     * Moves the file from the temporary processing disk to the permanent
     * thumbnail disk, using a standardized filename based on the sermon and variant.
     *
     * @param  string  $thumbnailPath  Absolute path to the temporary thumbnail file
     * @param  Sermon  $sermon  The associated sermon record
     * @param  string  $variant  Thumbnail variant name (e.g., 'overlay', 'card', 'plain')
     * @return string|null The relative path on the permanent storage disk, or null on failure
     */
    public function storeThumbnail(string $thumbnailPath, Sermon $sermon, string $variant = 'overlay'): ?string
    {
        try {
            $fullTempPath = Storage::disk($this->tempDisk)->path($thumbnailPath);

            $historicProcessingId = HistoricImportAssetPath::forSermon($sermon);
            $finalPath = $historicProcessingId !== null
                ? HistoricImportAssetPath::thumbnail($historicProcessingId, $variant)
                : $this->storagePath.'/'.$this->buildStorageFilename($sermon, $variant);

            Storage::disk($this->storageDisk)->makeDirectory(dirname($finalPath));

            $thumbnailContent = file_get_contents($fullTempPath);
            if (! is_string($thumbnailContent) || $thumbnailContent === '') {
                return null;
            }

            $stored = Storage::disk($this->storageDisk)->put($finalPath, $thumbnailContent);

            if (! $stored) {
                return null;
            }

            return $finalPath;
        } catch (\Throwable $e) {
            Log::error('Thumbnail storage failed', [
                'sermon_id' => $sermon->id,
                'thumbnail_path' => $thumbnailPath,
                'variant' => $variant,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete existing thumbnails and generate a fresh set from the sermon video.
     *
     * Used to refresh thumbnails when branding themes change or to re-run
     * generation for existing records.
     *
     * @param  Sermon  $sermon  The sermon record to refresh
     * @return ThumbnailResult Fresh thumbnail generation result
     *
     * @throws \InvalidArgumentException If the sermon has no valid video path
     */
    public function regenerateThumbnail(Sermon $sermon): ThumbnailResult
    {
        if (! $sermon->hasVideo()) {
            return ThumbnailResult::skipped('Sermon has no video file for thumbnail generation');
        }

        if (! $this->videoThumbnailAllowed($sermon)) {
            return ThumbnailResult::skipped('Video quality verdict does not allow public thumbnail generation');
        }

        $sermonDisk = config('media-processing.storage.sermon_disk');
        $videoPath = $sermon->video_file_path;

        if (! is_string($videoPath) || $videoPath === '') {
            throw new \InvalidArgumentException('Sermon does not have a valid video path');
        }

        $this->deleteExistingGeneratedThumbnails($sermon);

        return $this->generateThumbnail($sermon, $videoPath, is_string($sermonDisk) ? $sermonDisk : null);
    }

    private function createResizedBaseImage(string $baseFramePath): ImageInterface
    {
        $fullBaseFramePath = Storage::disk($this->tempDisk)->path($baseFramePath);
        $image = Image::decode($fullBaseFramePath);

        $image->scaleDown(self::WEB_WIDTH, self::WEB_HEIGHT);

        if ($image->width() !== self::WEB_WIDTH || $image->height() !== self::WEB_HEIGHT) {
            $canvas = Image::createImage(self::WEB_WIDTH, self::WEB_HEIGHT)->fill('#000000');
            $canvas->insert($image, 0, 0, 'center');

            return $canvas;
        }

        return $image;
    }

    private function videoThumbnailAllowed(Sermon $sermon): bool
    {
        if (! $sermon->hasVideo()) {
            return true;
        }

        return $this->exposurePolicy->shouldGenerateVideoThumbnail($sermon);
    }

    private function saveTemporaryThumbnail(ImageInterface $image, string $prefix = 'thumbnail'): string
    {
        $thumbnailFilename = $prefix.'_'.Str::uuid().'.webp';
        $tempThumbnailPath = $this->tempPath.'/'.$thumbnailFilename;
        $fullTempThumbnailPath = Storage::disk($this->tempDisk)->path($tempThumbnailPath);

        $image->encode(new WebpEncoder(quality: self::WEB_QUALITY))->save($fullTempThumbnailPath);

        return $tempThumbnailPath;
    }

    private function buildStorageFilename(Sermon $sermon, string $variant): string
    {
        $baseFilename = 'sermon_'.$sermon->id.'_'.date('Y-m-d');
        $suffix = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $variant), '_');

        if ($suffix !== '' && $suffix !== 'overlay') {
            $baseFilename .= '_'.$suffix;
        }

        return $baseFilename.'.webp';
    }

    private function scoreFrameQuality(string $baseFramePath): float
    {
        $fullBaseFramePath = Storage::disk($this->tempDisk)->path($baseFramePath);
        $contents = @file_get_contents($fullBaseFramePath);

        if (! is_string($contents) || $contents === '') {
            return 0.0;
        }

        $image = @imagecreatefromstring($contents);

        if (! ($image instanceof \GdImage)) {
            return 0.0;
        }

        try {
            return $this->frameQualityScorer->score($image);
        } finally {
            imagedestroy($image);
        }
    }

    private function deleteExistingGeneratedThumbnails(Sermon $sermon): void
    {
        $candidatePaths = [];

        foreach ($sermon->thumbnail_candidates as $candidate) {
            if (isset($candidate['overlay_path'])) {
                $candidatePaths[] = $candidate['overlay_path'];
            }

            if (isset($candidate['card_path'])) {
                $candidatePaths[] = $candidate['card_path'];
            }

            $candidatePaths[] = $candidate['plain_path'];
        }

        $paths = array_unique(array_filter([
            $sermon->thumbnail_file_path,
            $sermon->plain_thumbnail_file_path,
            $sermon->card_thumbnail_file_path,
            ...$candidatePaths,
        ]));

        foreach ($paths as $path) {
            $this->deleteStoredThumbnailPath($path);
        }
    }

    private function deleteStoredThumbnailPath(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        try {
            Storage::disk($this->storageDisk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete stored thumbnail', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function storedThumbnailExists(string $path): bool
    {
        return Storage::disk($this->storageDisk)->exists($path);
    }

    private function cleanupTempFile(string $tempPath): void
    {
        try {
            if (config('thumbnail-generation.processing.cleanup_temp_files')) {
                Storage::disk($this->tempDisk)->delete($tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to cleanup temp file', [
                'temp_path' => $tempPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
