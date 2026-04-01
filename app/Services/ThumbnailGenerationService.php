<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    public const int CANDIDATE_COUNT = 5;

    public const int WEB_WIDTH = 1280;

    public const int WEB_HEIGHT = 720;

    public const int WEB_QUALITY = 85;

    private const int REFERENCE_WIDTH = 1920;

    private const int REF_EDGE_INSET = 75;

    private const int REF_LOGO_WIDTH = 250;

    private const int REF_TITLE_FONT_SIZE = 96;

    private const int REF_CARD_TITLE_FONT_SIZE = 84;

    private const int REF_METADATA_FONT_SIZE = 72;

    private const int REF_TITLE_MIN_FONT_SIZE = 58;

    private const int REF_CARD_TITLE_MIN_FONT_SIZE = 48;

    private const int REF_METADATA_MIN_FONT_SIZE = 42;

    private const int REF_ACCENT_WIDTH = 12;

    private const int REF_ACCENT_GAP = 24;

    private const int REF_TITLE_TO_META_GAP = 64;

    private const int REF_META_LINE_GAP = 14;

    private const int REF_TEXT_TO_SUBJECT_GAP = 42;

    private const int REF_SUBJECT_MAX_WIDTH = 760;

    private const int MAIN_TITLE_MAX_LINES = 4;

    private const int CARD_TITLE_MAX_LINES = 3;

    private const float TITLE_LINE_HEIGHT = 0.92;

    private const float METADATA_LINE_HEIGHT = 1.04;

    private string $storageDisk;

    private string $storagePath;

    private string $tempDisk;

    private string $tempPath;

    private readonly FrameExtractionService $frameExtractionService;

    public function __construct(
        FrameExtractionService $frameExtractionService,
        private readonly StorageAdapterHelper $storageHelper,
        private readonly ThumbnailTextHelper $textHelper,
        private readonly ThumbnailForegroundExtractionService $foregroundExtractor
    ) {
        $this->storageDisk = (string) config('thumbnail-generation.storage.disk', 'public');
        $this->storagePath = (string) config('thumbnail-generation.storage.path', 'sermons/thumbnails');
        $this->tempDisk = (string) config('thumbnail-generation.processing.temp_disk', 'local');
        $this->tempPath = (string) config('thumbnail-generation.processing.temp_path', 'temp/thumbnails');
        $this->frameExtractionService = $frameExtractionService;
    }

    public function generateThumbnail(Sermon $sermon, string $videoPath, ?string $disk = null): ThumbnailResult
    {
        $tempVideoPath = null;

        try {
            if (! config('thumbnail-generation.enabled')) {
                return ThumbnailResult::skipped('Thumbnail generation is disabled');
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
     * @return array{path:string,composition_metadata:array<string, mixed>}|null
     */
    public function createBrandedThumbnail(Sermon $sermon, string $baseFramePath): ?array
    {
        try {
            $image = $this->createResizedBaseImage($baseFramePath);
            $foreground = $this->extractForegroundLayer($image);
            $mainImage = $this->buildMainThumbnailCanvas($sermon, $foreground);

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
        $disk = $this->resolveStoredThumbnailDisk($plainPath);
        if (! Storage::disk($disk)->exists($plainPath)) {
            Log::warning('Plain thumbnail not found for overlay rendering', [
                'sermon_id' => $sermon->id,
                'plain_path' => $plainPath,
                'disk' => $disk,
            ]);

            return null;
        }

        try {
            $image = Image::read(Storage::disk($disk)->path($plainPath));

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
     * @return RenderedAssets|null
     */
    private function createRenderedAssetsFromImage(Sermon $sermon, ImageInterface $image): ?array
    {
        try {
            $foreground = $this->extractForegroundLayer($image);
            $overlayImage = $this->buildMainThumbnailCanvas($sermon, $foreground);
            $cardImage = $this->buildCardThumbnailCanvas($sermon, $foreground);

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
     * @param  ForegroundLayer|null  $foreground
     */
    private function buildMainThumbnailCanvas(Sermon $sermon, ?array $foreground): ImageInterface
    {
        $canvas = $this->createBaseCanvas();
        $width = $canvas->width();
        $height = $canvas->height();
        $inset = $this->scaleFromReference(self::REF_EDGE_INSET, $width);
        $accentWidth = $this->scaleFromReference(self::REF_ACCENT_WIDTH, $width);
        $accentGap = $this->scaleFromReference(self::REF_ACCENT_GAP, $width);
        $titleMetaGap = $this->scaleFromReference(self::REF_TITLE_TO_META_GAP, $width);
        $subjectGap = $this->scaleFromReference(self::REF_TEXT_TO_SUBJECT_GAP, $width);
        $subjectMaxWidth = min(
            (int) round($width * 0.42),
            $this->scaleFromReference(self::REF_SUBJECT_MAX_WIDTH, $width),
        );
        $textRightEdge = $width - $inset - $subjectMaxWidth - $subjectGap;
        $titleStartX = $inset + $accentWidth + $accentGap;
        $titleMaxWidth = max($this->scaleFromReference(380, $width), $textRightEdge - $titleStartX);

        $titleLayout = $this->resolveTextLayout(
            $sermon->title,
            $titleMaxWidth,
            $this->scaleFromReference(self::REF_TITLE_FONT_SIZE, $width),
            $this->scaleFromReference(self::REF_TITLE_MIN_FONT_SIZE, $width),
            self::MAIN_TITLE_MAX_LINES,
            self::TITLE_LINE_HEIGHT,
            (int) round($height * 0.38),
        );

        $titleTopY = max(
            $inset + $this->scaleFromReference(self::REF_LOGO_WIDTH, $width) / 4,
            (int) round(($height / 2) - ($titleLayout['height'] / 2)),
        );

        $this->drawAccentLine($canvas, $inset, $titleTopY, $accentWidth, $titleLayout['height']);
        $this->drawTextLines(
            $canvas,
            $titleLayout['lines'],
            $titleStartX,
            $titleTopY,
            $titleLayout['font_size'],
            $this->foregroundColor(),
            self::TITLE_LINE_HEIGHT,
        );

        $metadataLines = $this->mainMetadataLines($sermon);
        if ($metadataLines !== []) {
            $metadataFontSize = $this->scaleFromReference(self::REF_METADATA_FONT_SIZE, $width);
            $metadataMinFontSize = $this->scaleFromReference(self::REF_METADATA_MIN_FONT_SIZE, $width);
            $metadataTopY = $titleTopY + $titleLayout['height'] + $titleMetaGap;
            $metadataY = $metadataTopY;
            $metadataGap = $this->scaleFromReference(self::REF_META_LINE_GAP, $width);
            $metadataMaxWidth = max($this->scaleFromReference(340, $width), $textRightEdge - $inset);

            foreach ($metadataLines as $line) {
                $renderedLine = $this->fitSingleLineText($line, $metadataMaxWidth, $metadataFontSize, $metadataMinFontSize);
                $this->drawTextLines(
                    $canvas,
                    [$renderedLine['text']],
                    $inset,
                    $metadataY,
                    $renderedLine['font_size'],
                    $this->foregroundColor(),
                    self::METADATA_LINE_HEIGHT,
                );
                $metadataY += $renderedLine['font_size'] + $metadataGap;
            }
        }

        $this->placeForegroundSubject($canvas, $foreground, $subjectMaxWidth, $inset, $inset);

        return $canvas;
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    private function buildCardThumbnailCanvas(Sermon $sermon, ?array $foreground): ImageInterface
    {
        $canvas = $this->createBaseCanvas();
        $width = $canvas->width();
        $height = $canvas->height();
        $inset = $this->scaleFromReference(self::REF_EDGE_INSET, $width);
        $subjectGap = $this->scaleFromReference(self::REF_TEXT_TO_SUBJECT_GAP, $width);
        $subjectMaxWidth = min(
            (int) round($width * 0.42),
            $this->scaleFromReference(self::REF_SUBJECT_MAX_WIDTH, $width),
        );
        $textRightEdge = $width - $inset - $subjectMaxWidth - $subjectGap;
        $titleMaxWidth = max($this->scaleFromReference(360, $width), $textRightEdge - $inset);
        $titleLayout = $this->resolveTextLayout(
            $sermon->title,
            $titleMaxWidth,
            $this->scaleFromReference(self::REF_CARD_TITLE_FONT_SIZE, $width),
            $this->scaleFromReference(self::REF_CARD_TITLE_MIN_FONT_SIZE, $width),
            self::CARD_TITLE_MAX_LINES,
            self::TITLE_LINE_HEIGHT,
            (int) round($height * 0.34),
        );

        $titleTopY = max(
            $inset + $this->scaleFromReference(180, $width),
            (int) round(($height * 0.58) - ($titleLayout['height'] / 2)),
        );

        $this->drawTextLines(
            $canvas,
            $titleLayout['lines'],
            $inset,
            $titleTopY,
            $titleLayout['font_size'],
            $this->foregroundColor(),
            self::TITLE_LINE_HEIGHT,
        );

        $this->placeForegroundSubject($canvas, $foreground, $subjectMaxWidth, $inset, $inset);

        return $canvas;
    }

    private function createBaseCanvas(): ImageInterface
    {
        $canvas = Image::create(self::WEB_WIDTH, self::WEB_HEIGHT)->fill($this->backgroundColor());
        $this->placeLogo($canvas);

        return $canvas;
    }

    private function placeLogo(ImageInterface $image): void
    {
        $logoRelativePath = $this->logoPath();
        $fullLogoPath = public_path($logoRelativePath);

        if (! file_exists($fullLogoPath)) {
            Log::warning('Thumbnail logo image not found', ['path' => $logoRelativePath]);

            return;
        }

        try {
            $logo = $this->tintImage(Image::read($fullLogoPath), $this->foregroundColor());
            $targetWidth = $this->scaleFromReference(self::REF_LOGO_WIDTH, $image->width());
            $targetHeight = max(1, (int) round($logo->height() * ($targetWidth / max(1, $logo->width()))));
            $logo->resize($targetWidth, $targetHeight);

            $offset = $this->scaleFromReference(self::REF_EDGE_INSET, $image->width());
            $image->place($logo, 'top-left', $offset, $offset);
        } catch (\Throwable $e) {
            Log::warning('Failed to place thumbnail logo', [
                'logo_path' => $logoRelativePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function drawAccentLine(ImageInterface $image, int $x, int $y, int $width, int $height): void
    {
        $image->drawRectangle($x, $y, function ($draw) use ($width, $height): void {
            $draw->size($width, $height);
            $draw->background($this->foregroundColor());
            $draw->border('transparent', 0);
        });
    }

    /**
     * @param  list<string>  $lines
     */
    private function drawTextLines(
        ImageInterface $image,
        array $lines,
        int $x,
        int $y,
        int $fontSize,
        string $fontColor,
        float $lineHeightMultiplier
    ): void {
        $fontPath = $this->getOswaldFontPath();

        foreach ($lines as $index => $line) {
            $lineY = $y + (int) round($index * $fontSize * $lineHeightMultiplier);

            $image->text($line, $x, $lineY, function ($font) use ($fontSize, $fontColor, $fontPath): void {
                $font->size($fontSize);
                $font->color($fontColor);
                $font->align('left');
                $font->valign('top');

                if ($fontPath !== null && file_exists($fontPath)) {
                    $font->filename($fontPath);
                }
            });
        }
    }

    /**
     * @return list<string>
     */
    private function mainMetadataLines(Sermon $sermon): array
    {
        return array_values(array_filter([
            $sermon->displayReference(),
            $sermon->displayPreacherName(),
            $sermon->date->format('l jS F Y'),
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== ''));
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    private function placeForegroundSubject(
        ImageInterface $canvas,
        ?array $foreground,
        int $maxWidth,
        int $topInset,
        int $rightInset
    ): void {
        if ($foreground === null) {
            return;
        }

        $subjectImage = $this->cloneImage($foreground['image']);
        $subjectWidth = max(1, $subjectImage->width());
        $subjectHeight = max(1, $subjectImage->height());
        $maxHeight = max(1, $canvas->height() - $topInset);
        $scale = min($maxWidth / $subjectWidth, $maxHeight / $subjectHeight);

        if ($scale <= 0) {
            return;
        }

        $targetWidth = max(1, (int) round($subjectWidth * $scale));
        $targetHeight = max(1, (int) round($subjectHeight * $scale));

        $subjectImage->resize($targetWidth, $targetHeight);

        $x = $canvas->width() - $rightInset - $targetWidth;
        $y = $canvas->height() - $targetHeight;

        $canvas->place($subjectImage, 'top-left', $x, $y);
    }

    /**
     * @return array{lines:list<string>,font_size:int,height:int}
     */
    private function resolveTextLayout(
        string $text,
        int $maxWidth,
        int $startingFontSize,
        int $minimumFontSize,
        int $maxLines,
        float $lineHeightMultiplier,
        int $maxHeight
    ): array {
        $normalizedText = trim($text);

        if ($normalizedText === '') {
            return [
                'lines' => [],
                'font_size' => $startingFontSize,
                'height' => 0,
            ];
        }

        for ($fontSize = $startingFontSize; $fontSize >= $minimumFontSize; $fontSize -= 2) {
            $wrappedText = $this->wrapText($normalizedText, $maxWidth, $fontSize);
            $lines = $this->normalizeWrappedLines($wrappedText);
            $height = $this->textBlockHeight(count($lines), $fontSize, $lineHeightMultiplier);

            if ($lines !== [] && count($lines) <= $maxLines && $height <= $maxHeight) {
                return [
                    'lines' => $lines,
                    'font_size' => $fontSize,
                    'height' => $height,
                ];
            }
        }

        $wrappedText = $this->wrapText($normalizedText, $maxWidth, $minimumFontSize);
        $lines = $this->normalizeWrappedLines($wrappedText);

        if (count($lines) > $maxLines) {
            $overflow = array_slice($lines, $maxLines - 1);
            $lines = array_slice($lines, 0, $maxLines - 1);
            $lines[] = $this->ellipsizeText(implode(' ', $overflow), $maxWidth, $minimumFontSize);
        }

        while ($lines !== [] && $this->textBlockHeight(count($lines), $minimumFontSize, $lineHeightMultiplier) > $maxHeight) {
            $overflow = array_pop($lines);

            if ($lines === []) {
                $lines[] = $this->ellipsizeText((string) $overflow, $maxWidth, $minimumFontSize);
                break;
            }

            $lastIndex = count($lines) - 1;
            $merged = trim($lines[$lastIndex].' '.(string) $overflow);
            $lines[$lastIndex] = $this->ellipsizeText($merged, $maxWidth, $minimumFontSize);
        }

        return [
            'lines' => $lines,
            'font_size' => $minimumFontSize,
            'height' => $this->textBlockHeight(count($lines), $minimumFontSize, $lineHeightMultiplier),
        ];
    }

    /**
     * @return array{text:string,font_size:int}
     */
    private function fitSingleLineText(string $text, int $maxWidth, int $startingFontSize, int $minimumFontSize): array
    {
        $normalizedText = trim($text);

        for ($fontSize = $startingFontSize; $fontSize >= $minimumFontSize; $fontSize -= 2) {
            $bounds = $this->textHelper->calculateTextBounds($normalizedText, $fontSize, $this->getOswaldFontPath());

            if ((int) round($bounds['width']) <= $maxWidth) {
                return [
                    'text' => $normalizedText,
                    'font_size' => $fontSize,
                ];
            }
        }

        return [
            'text' => $this->ellipsizeText($normalizedText, $maxWidth, $minimumFontSize),
            'font_size' => $minimumFontSize,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeWrappedLines(string $wrappedText): array
    {
        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), explode("\n", $wrappedText)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function textBlockHeight(int $lineCount, int $fontSize, float $lineHeightMultiplier): int
    {
        if ($lineCount <= 0) {
            return 0;
        }

        return (int) round(($fontSize * $lineHeightMultiplier * max(0, $lineCount - 1)) + $fontSize);
    }

    private function ellipsizeText(string $text, int $maxWidth, int $fontSize): string
    {
        $normalizedText = trim($text);
        $fontPath = $this->getOswaldFontPath();

        if ($normalizedText === '') {
            return '';
        }

        $ellipsis = '...';
        $candidate = $normalizedText;

        while (true) {
            $bounds = $this->textHelper->calculateTextBounds($candidate.$ellipsis, $fontSize, $fontPath);

            if ((int) round($bounds['width']) <= $maxWidth) {
                return $candidate.$ellipsis;
            }

            $nextCandidate = rtrim((string) preg_replace('/\s+\S*$/', '', $candidate));

            if ($nextCandidate === $candidate) {
                if (mb_strlen($candidate) <= 1) {
                    return $ellipsis;
                }

                $nextCandidate = mb_substr($candidate, 0, mb_strlen($candidate) - 1);
            }

            $candidate = $nextCandidate;
        }
    }

    /**
     * Wrap text to fit within the specified width.
     */
    private function wrapText(string $text, int $maxWidth, int $fontSize): string
    {
        try {
            $normalizedText = trim($text);
            if ($normalizedText === '') {
                return '';
            }

            $fontPath = $this->getOswaldFontPath();
            $words = preg_split('/\s+/', $normalizedText) ?: [];
            $lines = [];
            $currentLine = '';

            foreach ($words as $word) {
                $testLine = $currentLine === '' ? $word : $currentLine.' '.$word;
                $bounds = $this->textHelper->calculateTextBounds($testLine, $fontSize, $fontPath);

                if ((int) round($bounds['width']) <= $maxWidth || $currentLine === '') {
                    $currentLine = $testLine;

                    continue;
                }

                $lines[] = $currentLine;
                $currentLine = $word;
            }

            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::warning('Text wrapping failed, using fallback', [
                'text' => $text,
                'error' => $e->getMessage(),
            ]);

            return $this->textHelper->fallbackTextWrap($text, $maxWidth, $fontSize);
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
                'image' => $this->cropForegroundToBounds($foreground['image'], $foreground['bounds']),
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
     * @param  array{x:int,y:int,width:int,height:int}  $bounds
     */
    private function cropForegroundToBounds(ImageInterface $image, array $bounds): ImageInterface
    {
        $native = $image->core()->native();

        if (! ($native instanceof \GdImage)) {
            throw new \RuntimeException('Foreground cropping requires the GD image driver.');
        }

        $cropped = imagecrop($native, [
            'x' => $bounds['x'],
            'y' => $bounds['y'],
            'width' => $bounds['width'],
            'height' => $bounds['height'],
        ]);

        if (! ($cropped instanceof \GdImage)) {
            throw new \RuntimeException('Foreground crop failed.');
        }

        imagesavealpha($cropped, true);

        $encoded = $this->encodeGdImage($cropped);
        imagedestroy($cropped);

        return Image::read($encoded);
    }

    private function cloneImage(ImageInterface $image): ImageInterface
    {
        $native = $image->core()->native();

        if (! ($native instanceof \GdImage)) {
            throw new \RuntimeException('Image cloning requires the GD image driver.');
        }

        return Image::read($this->encodeGdImage($native));
    }

    private function tintImage(ImageInterface $image, string $hexColor): ImageInterface
    {
        $clone = $this->cloneImage($image);
        $native = $clone->core()->native();

        if (! ($native instanceof \GdImage)) {
            return $clone;
        }

        [$red, $green, $blue] = $this->hexToRgb($hexColor);
        $width = imagesx($native);
        $height = imagesy($native);

        imagealphablending($native, false);
        imagesavealpha($native, true);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colorIndex = imagecolorat($native, $x, $y);
                if ($colorIndex === false) {
                    continue;
                }

                $color = imagecolorsforindex($native, $colorIndex);
                $alpha = (int) $color['alpha'];

                if ($alpha >= 127) {
                    continue;
                }

                $tintedColor = imagecolorallocatealpha($native, $red, $green, $blue, $alpha);
                if ($tintedColor === false) {
                    continue;
                }

                imagesetpixel($native, $x, $y, $tintedColor);
            }
        }

        return $clone;
    }

    /**
     * @return array{0:int<0, 255>,1:int<0, 255>,2:int<0, 255>}
     */
    private function hexToRgb(string $hexColor): array
    {
        $normalized = ltrim($hexColor, '#');

        if (strlen($normalized) === 3) {
            $normalized = preg_replace('/(.)/', '$1$1', $normalized) ?? $normalized;
        }

        if (strlen($normalized) !== 6) {
            return [20, 85, 87];
        }

        return [
            max(0, min(255, (int) hexdec(substr($normalized, 0, 2)))),
            max(0, min(255, (int) hexdec(substr($normalized, 2, 2)))),
            max(0, min(255, (int) hexdec(substr($normalized, 4, 2)))),
        ];
    }

    /**
     * Store thumbnail in final storage location.
     */
    public function storeThumbnail(string $thumbnailPath, Sermon $sermon, string $variant = 'overlay'): ?string
    {
        try {
            $fullTempPath = Storage::disk($this->tempDisk)->path($thumbnailPath);

            $filename = $this->buildStorageFilename($sermon, $variant);
            $finalPath = $this->storagePath.'/'.$filename;

            Storage::disk($this->storageDisk)->makeDirectory($this->storagePath);

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

    public function regenerateThumbnail(Sermon $sermon): ThumbnailResult
    {
        if (! $sermon->hasVideo()) {
            return ThumbnailResult::skipped('Sermon has no video file for thumbnail generation');
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
        $image = Image::read($fullBaseFramePath);

        $image->scaleDown(self::WEB_WIDTH, self::WEB_HEIGHT);

        if ($image->width() !== self::WEB_WIDTH || $image->height() !== self::WEB_HEIGHT) {
            $canvas = Image::create(self::WEB_WIDTH, self::WEB_HEIGHT)->fill('#000000');
            $canvas->place($image, 'center');

            return $canvas;
        }

        return $image;
    }

    private function saveTemporaryThumbnail(ImageInterface $image, string $prefix = 'thumbnail'): string
    {
        $thumbnailFilename = $prefix.'_'.Str::uuid().'.webp';
        $tempThumbnailPath = $this->tempPath.'/'.$thumbnailFilename;
        $fullTempThumbnailPath = Storage::disk($this->tempDisk)->path($tempThumbnailPath);

        $image->toWebp(quality: self::WEB_QUALITY)->save($fullTempThumbnailPath);

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

        $width = imagesx($image);
        $height = imagesy($image);

        $stepX = max(1, (int) floor($width / 48));
        $stepY = max(1, (int) floor($height / 48));

        $luminanceValues = [];
        $detailAccumulator = 0.0;

        for ($y = 0; $y < $height; $y += $stepY) {
            $previousRowLuminance = null;

            for ($x = 0; $x < $width; $x += $stepX) {
                $rgb = imagecolorat($image, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;

                $luminance = (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);
                $luminanceValues[] = $luminance;

                if ($x >= $stepX) {
                    $previousRgb = imagecolorat($image, $x - $stepX, $y);
                    $previousRed = ($previousRgb >> 16) & 0xFF;
                    $previousGreen = ($previousRgb >> 8) & 0xFF;
                    $previousBlue = $previousRgb & 0xFF;
                    $previousLuminance = (0.2126 * $previousRed) + (0.7152 * $previousGreen) + (0.0722 * $previousBlue);
                    $detailAccumulator += abs($luminance - $previousLuminance);
                }

                if ($previousRowLuminance !== null) {
                    $detailAccumulator += abs($luminance - $previousRowLuminance);
                }

                $previousRowLuminance = $luminance;
            }
        }

        imagedestroy($image);

        $sampleCount = count($luminanceValues);
        $averageBrightness = array_sum($luminanceValues) / $sampleCount;
        $variance = array_sum(array_map(
            static fn (float $value): float => ($value - $averageBrightness) ** 2,
            $luminanceValues
        )) / $sampleCount;
        $contrast = sqrt($variance);
        $detail = $detailAccumulator / $sampleCount;

        $brightnessScore = max(0.0, 1 - (abs($averageBrightness - 128.0) / 128.0));
        $contrastScore = min(1.0, $contrast / 64.0);
        $detailScore = min(1.0, $detail / 80.0);

        return round(($brightnessScore * 0.25) + ($contrastScore * 0.35) + ($detailScore * 0.40), 4);
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
            $disk = str_starts_with($path, 'private/')
                ? 'local'
                : $this->storageDisk;

            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete stored thumbnail', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveStoredThumbnailDisk(string $path): string
    {
        return str_starts_with($path, 'private/')
            ? 'local'
            : $this->storageDisk;
    }

    private function storedThumbnailExists(string $path): bool
    {
        return Storage::disk($this->resolveStoredThumbnailDisk($path))->exists($path);
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

    private function getOswaldFontPath(): ?string
    {
        $fontPaths = [
            public_path('fonts/oswald-regular.woff2'),
            public_path('fonts/Oswald-Regular.ttf'),
            public_path('fonts/oswald-regular.ttf'),
            storage_path('fonts/oswald-regular.ttf'),
            '/System/Library/Fonts/Helvetica.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ];

        foreach ($fontPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        Log::warning('Oswald font not found, text will use system default');

        return null;
    }

    private function scaleFromReference(int $value, int $currentWidth): int
    {
        $scale = $currentWidth / self::REFERENCE_WIDTH;

        return max(1, (int) round($value * $scale));
    }

    private function backgroundColor(): string
    {
        return (string) config('thumbnail-generation.theme.palette.background_color', '#D7EAE6');
    }

    private function foregroundColor(): string
    {
        return (string) config('thumbnail-generation.theme.palette.foreground_color', '#145557');
    }

    private function logoPath(): string
    {
        return (string) config('thumbnail-generation.theme.logo_path', 'images/Primary.png');
    }

    private function encodeGdImage(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
