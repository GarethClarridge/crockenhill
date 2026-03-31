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
 *     overlay_path?: string|null,
 *     composition_mode?: string|null,
 *     foreground_extraction_method?: string|null,
 *     foreground_bounds?: array<string, int>,
 *     foreground_coverage?: float|null
 * }
 * @phpstan-type RenderedThumbnailCandidate ThumbnailCandidate&array{
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
 */
class ThumbnailGenerationService
{
    public const int CANDIDATE_COUNT = 5;

    // Thumbnail dimensions
    public const int WEB_WIDTH = 1280;

    public const int WEB_HEIGHT = 720;

    public const int WEB_QUALITY = 85;

    // Font
    public const int TITLE_FONT_SIZE = 144;

    public const float TITLE_LINE_HEIGHT = 0.9;

    public const string TITLE_COLOR = '#FFFFFF';

    public const int DATE_FONT_SIZE = 32;

    public const string DATE_COLOR = '#000000';

    public const string STROKE_COLOR = '#FFFFFF';

    public const int STROKE_WIDTH = 2;

    // Background (date pill)
    public const string BACKGROUND_COLOR = '#FFFFFF';

    public const int BACKGROUND_HORIZONTAL_PADDING = 0;

    public const int BACKGROUND_VERTICAL_PADDING = 15;

    // Layout positioning (as fractions of image dimensions)
    public const float TITLE_X_PERCENT = 0.5;

    public const float TITLE_Y_CENTER_PERCENT = 0.35;

    public const float TITLE_WIDTH_PERCENT = 1.0;

    public const float DATE_X_PERCENT = 0.5;

    public const float DATE_Y_PERCENT = 0.85;

    // Brand overlay
    public const string BRAND_IMAGE = 'images/BrandOverlay.png';

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
        $this->storageDisk = config('thumbnail-generation.storage.disk');
        $this->storagePath = config('thumbnail-generation.storage.path');
        $this->tempDisk = config('thumbnail-generation.processing.temp_disk');
        $this->tempPath = config('thumbnail-generation.processing.temp_path');
        $this->frameExtractionService = $frameExtractionService;
    }

    /**
     * Generate thumbnail for a sermon video
     *
     * @param  Sermon  $sermon  The sermon model
     * @param  string  $videoPath  Full path to the video file (local) or relative path (S3)
     * @param  string|null  $disk  Storage disk name for S3-compatible storage
     * @return ThumbnailResult Result of thumbnail generation
     */
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

            if ($metadata['duration'] < config('thumbnail-generation.extraction.min_video_duration')) {
                $this->frameExtractionService->cleanupDownloadedVideo($tempVideoPath);

                return ThumbnailResult::skipped('Video too short for thumbnail generation');
            }

            $candidateTimestamps = $this->frameExtractionService->calculateCandidateTimestamps(
                (float) $metadata['duration'],
                self::CANDIDATE_COUNT
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
            $selectedCandidate = $this->renderOverlayForCandidate($sermon, $successfulCandidates[0]);
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
                'overlay_thumbnail_path' => $selectedCandidate['overlay_path'],
                'selected_thumbnail_candidate_id' => $selectedCandidate['id'],
                'thumbnail_candidates' => $successfulCandidates,
            ];

            $resultMetadata = array_merge($resultMetadata, $this->candidateCompositionMetadata($selectedCandidate));

            return ThumbnailResult::success($selectedCandidate['overlay_path'], $resultMetadata);

        } catch (\Exception $e) {
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
     * Create branded thumbnail with sermon metadata overlay
     *
     * @param  Sermon  $sermon  The sermon model
     * @param  string  $baseFramePath  Path to base frame image
     * @return array{path:string,composition_metadata:array<string, mixed>}|null
     */
    public function createBrandedThumbnail(Sermon $sermon, string $baseFramePath): ?array
    {
        try {
            $image = $this->createResizedBaseImage($baseFramePath);

            return $this->createBrandedThumbnailFromImage($sermon, $image);

        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
     * Create plain thumbnail without text or brand overlays.
     *
     * @param  string  $baseFramePath  Path to base frame image
     * @return string|null Path to plain thumbnail or null on failure
     */
    public function createPlainThumbnail(string $baseFramePath): ?string
    {
        try {
            $image = $this->createResizedBaseImage($baseFramePath);

            return $this->saveTemporaryThumbnail($image, 'thumbnail_plain');
        } catch (\Exception $e) {
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

        $selectedCandidate = $this->renderOverlayForCandidate($sermon, $candidate);
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
     * @param  array{
     *     coverage: float,
     *     bounds: array{x:int,y:int,width:int,height:int},
     *     method: string
     * }|null  $foreground
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
    private function renderOverlayForCandidate(Sermon $sermon, array $candidate): ?array
    {
        if (
            isset($candidate['overlay_path'])
            && $candidate['overlay_path'] !== ''
            && $this->storedThumbnailExists($candidate['overlay_path'])
        ) {
            return $this->buildRenderedCandidate(
                $candidate,
                $candidate['overlay_path'],
                $this->candidateCompositionMetadata($candidate),
            );
        }

        $brandedThumbnail = $this->createBrandedThumbnailFromStoredPlainPath($sermon, $candidate['plain_path']);
        if ($brandedThumbnail === null) {
            return null;
        }

        try {
            $overlayPath = $this->storeThumbnail($brandedThumbnail['path'], $sermon, "{$candidate['id']}_overlay");
            if (! is_string($overlayPath) || $overlayPath === '') {
                return null;
            }

            return $this->buildRenderedCandidate(
                $candidate,
                $overlayPath,
                $brandedThumbnail['composition_metadata'],
            );
        } finally {
            $this->cleanupTempFile($brandedThumbnail['path']);
        }
    }

    /**
     * @param  list<ThumbnailCandidate>  $candidates
     * @param  ThumbnailCandidate  $replacement
     * @return list<ThumbnailCandidate>
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
     * @param  CompositionMetadata  $compositionMetadata
     * @return RenderedThumbnailCandidate
     */
    private function buildRenderedCandidate(array $candidate, string $overlayPath, array $compositionMetadata): array
    {
        $renderedCandidate = [
            'id' => $candidate['id'],
            'timestamp' => $candidate['timestamp'],
            'score' => $candidate['score'],
            'plain_path' => $candidate['plain_path'],
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

        return $renderedCandidate;
    }

    /**
     * @param  array<string, mixed>  $baseMetadata
     * @param  RenderedThumbnailCandidate  $selectedCandidate
     * @param  list<ThumbnailCandidate>  $thumbnailCandidates
     * @return array<string, mixed>
     */
    private function buildSelectedCandidateMetadata(array $baseMetadata, array $selectedCandidate, array $thumbnailCandidates): array
    {
        $metadata = $baseMetadata;
        $metadata['timestamp'] = $selectedCandidate['timestamp'];
        $metadata['plain_thumbnail_path'] = $selectedCandidate['plain_path'];
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
     * @return array{path:string,composition_metadata:CompositionMetadata}|null
     */
    private function createBrandedThumbnailFromStoredPlainPath(Sermon $sermon, string $plainPath): ?array
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

            return $this->createBrandedThumbnailFromImage($sermon, $image);
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
     * @return array{path:string,composition_metadata:CompositionMetadata}|null
     */
    private function createBrandedThumbnailFromImage(Sermon $sermon, ImageInterface $image): ?array
    {
        try {
            $foreground = $this->foregroundExtractor->extract($image);

            $this->addTitleOverlay($image, $sermon);

            if ($foreground !== null) {
                $image->place($foreground['image'], 'top-left', 0, 0);
            }

            $this->addBrandOverlay($image);
            $this->addDateOverlay($image, $sermon);

            return [
                'path' => $this->saveTemporaryThumbnail($image),
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
     * Store thumbnail in final storage location
     *
     * @param  string  $thumbnailPath  Path to temporary thumbnail
     * @param  Sermon  $sermon  The sermon model
     * @return string|null Final storage path or null on failure
     */
    public function storeThumbnail(string $thumbnailPath, Sermon $sermon, string $variant = 'overlay'): ?string
    {
        try {
            $fullTempPath = Storage::disk($this->tempDisk)->path($thumbnailPath);

            // Generate final filename
            $filename = $this->buildStorageFilename($sermon, $variant);
            $finalPath = $this->storagePath.'/'.$filename;

            // Ensure storage directory exists
            Storage::disk($this->storageDisk)->makeDirectory($this->storagePath);

            // Copy thumbnail to final storage location
            $thumbnailContent = file_get_contents($fullTempPath);
            if (! $thumbnailContent) {
                return null;
            }

            $stored = Storage::disk($this->storageDisk)->put($finalPath, $thumbnailContent);

            if (! $stored) {
                return null;
            }

            return $finalPath;

        } catch (\Exception $e) {
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
     * Add the main sermon title to the thumbnail.
     */
    private function addTitleOverlay(ImageInterface $image, Sermon $sermon): void
    {
        $imageWidth = $image->width();

        // Prepare sermon title (with word wrapping for full width)
        $titleMaxWidth = $imageWidth * self::TITLE_WIDTH_PERCENT;
        $title = $this->wrapText($sermon->title, (int) $titleMaxWidth, self::TITLE_FONT_SIZE);

        // Calculate responsive font sizes
        $titleFontSize = $this->textHelper->calculateResponsiveFontSize(self::TITLE_FONT_SIZE, $imageWidth, 1280);

        $titleX = (int) ($imageWidth * self::TITLE_X_PERCENT);
        $titleCenterY = (int) ($image->height() * self::TITLE_Y_CENTER_PERCENT);

        $this->addTextWithoutBackgroundCentered($image, $title, $titleX, $titleCenterY, $titleFontSize, self::TITLE_COLOR);
    }

    /**
     * Add the service date pill after all other layers.
     */
    private function addDateOverlay(ImageInterface $image, Sermon $sermon): void
    {
        $imageWidth = $image->width();
        $imageHeight = $image->height();

        $serviceDate = $sermon->date->format('l jS F Y');
        if ($sermon->service) {
            $serviceDate .= ' - '.ucfirst($sermon->service->value).' Service';
        }

        $dateFontSize = $this->textHelper->calculateResponsiveFontSize(self::DATE_FONT_SIZE, $imageWidth, 1280);
        $dateX = (int) ($imageWidth * self::DATE_X_PERCENT);
        $dateY = (int) ($imageHeight * self::DATE_Y_PERCENT);

        $this->addTextWithBackground($image, $serviceDate, $dateX, $dateY, $dateFontSize, self::DATE_COLOR);
    }

    /**
     * Add brand overlay to thumbnail image, stretched to fit the whole thumbnail
     *
     * @param  ImageInterface  $image  The image to modify
     */
    private function addBrandOverlay(ImageInterface $image): void
    {
        $brandImagePath = self::BRAND_IMAGE;

        // Check if brand image exists (in public/ directory for static assets)
        $fullBrandPath = public_path($brandImagePath);
        if (! file_exists($fullBrandPath)) {
            Log::warning('Brand overlay image not found', ['path' => $brandImagePath]);

            return;
        }

        try {
            $brandImageFullPath = $fullBrandPath;
            $brandOverlay = Image::read($brandImageFullPath);

            // Get thumbnail dimensions
            $thumbnailWidth = $image->width();
            $thumbnailHeight = $image->height();

            // Stretch brand overlay to fit the entire thumbnail
            $brandOverlay->resize($thumbnailWidth, $thumbnailHeight);

            // Insert the stretched brand overlay as the background at full size
            $image->place($brandOverlay, 'top-left', 0, 0); // Insert at top-left position

        } catch (\Exception $e) {
            Log::warning('Failed to add brand overlay', [
                'brand_image_path' => $brandImagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add text with background to image
     *
     * @param  ImageInterface  $image  The image to modify
     * @param  string  $text  Text to add
     * @param  int  $x  X position (center of text area)
     * @param  int  $y  Y position (center of text area)
     * @param  int  $fontSize  Font size
     * @param  string  $fontColor  Font color
     */
    private function addTextWithBackground(ImageInterface $image, string $text, int $x, int $y, int $fontSize, string $fontColor): void
    {
        try {
            // Get font path for Oswald font (following accessibility requirements)
            $fontPath = $this->getOswaldFontPath();

            // Calculate text dimensions for background sizing
            $textBounds = $this->textHelper->calculateTextBounds($text, $fontSize, $fontPath);

            $bgWidth = $textBounds['width'] + (self::BACKGROUND_HORIZONTAL_PADDING * 2);
            $bgHeight = $textBounds['height'] + (self::BACKGROUND_VERTICAL_PADDING * 2);

            $bgX = $x - ($bgWidth / 2);
            $bgY = $y - ($bgHeight / 2);

            // Create white background rectangle for accessibility
            $this->addTextBackground($image, (int) $bgX, (int) $bgY, $textBounds);

            // Add main text with Oswald font, centered in the background
            $image->text($text, $x, $y, function ($font) use ($fontSize, $fontColor, $fontPath) {
                $font->size($fontSize);
                $font->color($fontColor);
                $font->align('center');    // Center horizontally
                $font->valign('middle');   // Center vertically
                if ($fontPath && file_exists($fontPath)) {
                    $font->filename($fontPath);
                }
            });

        } catch (\Exception $e) {
            Log::warning('Failed to add text overlay', [
                'text' => $text,
                'error' => $e->getMessage(),
            ]);

            // Fallback to simple text without background if overlay fails
            $this->addFallbackText($image, $text, $x, $y, $fontSize, $fontColor);
        }
    }

    /**
     * Add text without background to image with vertical centering
     *
     * @param  ImageInterface  $image  The image to modify
     * @param  string  $text  Text to add
     * @param  int  $x  X position (center of text)
     * @param  int  $y  Y position (center of entire text block)
     * @param  int  $fontSize  Font size
     * @param  string  $fontColor  Font color
     */
    private function addTextWithoutBackgroundCentered(ImageInterface $image, string $text, int $x, int $y, int $fontSize, string $fontColor): void
    {
        try {
            // Get font path for Oswald font
            $fontPath = $this->getOswaldFontPath();

            // Split text into lines for manual centering
            $lines = explode("\n", $text);

            $lineHeight = $fontSize * self::TITLE_LINE_HEIGHT;

            // Calculate total height of text block
            $totalHeight = (count($lines) - 1) * $lineHeight + $fontSize;

            // Calculate starting Y position to center the entire text block
            $startY = $y - ($totalHeight / 2);

            // Add each line separately, centered
            foreach ($lines as $index => $line) {
                $lineY = $startY + ($index * $lineHeight);

                $image->text(trim($line), $x, (int) $lineY, function ($font) use ($fontSize, $fontColor, $fontPath) {
                    $font->size($fontSize);
                    $font->color($fontColor);
                    $font->align('center');    // Center each line individually
                    $font->valign('top');      // Top alignment for precise positioning
                    if ($fontPath && file_exists($fontPath)) {
                        $font->filename($fontPath);
                    }
                });
            }

        } catch (\Exception $e) {
            Log::warning('Failed to add centered text overlay without background', [
                'text' => $text,
                'error' => $e->getMessage(),
            ]);

            // Fallback to simple text
            $this->addFallbackText($image, $text, $x, $y, $fontSize, $fontColor);
        }
    }

    /**
     * Wrap text to fit within specified width using intelligent word wrapping
     *
     * @param  string  $text  Text to wrap
     * @param  int  $maxWidth  Maximum width in pixels
     * @param  int  $fontSize  Font size
     * @return string Wrapped text
     */
    private function wrapText(string $text, int $maxWidth, int $fontSize): string
    {
        try {
            $fontPath = $this->getOswaldFontPath();
            $words = explode(' ', $text);
            $lines = [];
            $currentLine = '';

            foreach ($words as $word) {
                $testLine = $currentLine ? $currentLine.' '.$word : $word;
                $testBounds = $this->textHelper->calculateTextBounds($testLine, $fontSize, $fontPath);

                if ($testBounds['width'] <= $maxWidth) {
                    $currentLine = $testLine;
                } else {
                    if ($currentLine) {
                        $lines[] = $currentLine;
                        $currentLine = $word;
                    } else {
                        // Single word is too long, add it anyway
                        $lines[] = $word;
                    }
                }
            }

            if ($currentLine) {
                $lines[] = $currentLine;
            }

            return implode("\n", $lines);

        } catch (\Exception $e) {
            Log::warning('Text wrapping failed, using fallback', [
                'text' => $text,
                'error' => $e->getMessage(),
            ]);

            // Fallback to simple character-based wrapping
            return $this->textHelper->fallbackTextWrap($text, $maxWidth, $fontSize);
        }
    }

    /**
     * Clean up temporary file
     *
     * @param  string  $tempPath  Path to temporary file
     */
    private function cleanupTempFile(string $tempPath): void
    {
        try {
            if (config('thumbnail-generation.processing.cleanup_temp_files')) {
                Storage::disk($this->tempDisk)->delete($tempPath);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup temp file', [
                'temp_path' => $tempPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Regenerate thumbnail for existing sermon
     *
     * @param  Sermon  $sermon  The sermon model
     * @return ThumbnailResult Result of thumbnail regeneration
     */
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

        return $this->generateThumbnail($sermon, $videoPath, $sermonDisk);
    }

    /**
     * Build a fully sized thumbnail canvas from the extracted base frame.
     *
     * @param  string  $baseFramePath  Path to base frame image on temp disk
     */
    private function createResizedBaseImage(string $baseFramePath): ImageInterface
    {
        $fullBaseFramePath = Storage::disk($this->tempDisk)->path($baseFramePath);
        $image = Image::read($fullBaseFramePath);

        $targetWidth = self::WEB_WIDTH;
        $targetHeight = self::WEB_HEIGHT;

        $image->scaleDown($targetWidth, $targetHeight);

        if ($image->width() !== $targetWidth || $image->height() !== $targetHeight) {
            $canvas = Image::create($targetWidth, $targetHeight)->fill('#000000');
            $canvas->place($image, 'center');
            $image = $canvas;
        }

        return $image;
    }

    /**
     * Save a temporary webp thumbnail image on the configured temp disk.
     */
    private function saveTemporaryThumbnail(ImageInterface $image, string $prefix = 'thumbnail'): string
    {
        $thumbnailFilename = $prefix.'_'.Str::uuid().'.webp';
        $tempThumbnailPath = $this->tempPath.'/'.$thumbnailFilename;
        $fullTempThumbnailPath = Storage::disk($this->tempDisk)->path($tempThumbnailPath);

        $image->toWebp(quality: self::WEB_QUALITY)->save($fullTempThumbnailPath);

        return $tempThumbnailPath;
    }

    /**
     * Build the final persisted thumbnail filename.
     */
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

        if ($image === false) {
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
            $candidatePaths[] = $candidate['plain_path'];
        }

        $paths = array_unique(array_filter([
            $sermon->thumbnail_file_path,
            $sermon->plain_thumbnail_file_path,
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
        } catch (\Exception $e) {
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

    /**
     * Get path to Oswald font file
     *
     * @return string|null Path to font file or null if not found
     */
    private function getOswaldFontPath(): ?string
    {
        $fontPaths = [
            public_path('fonts/oswald-regular.woff2'),
            public_path('fonts/Oswald-Regular.ttf'),
            public_path('fonts/oswald-regular.ttf'),
            storage_path('fonts/oswald-regular.ttf'),
            '/System/Library/Fonts/Helvetica.ttc', // macOS fallback
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', // Linux fallback
        ];

        foreach ($fontPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        Log::warning('Oswald font not found, text will use system default');

        return null;
    }

    /**
     * Add white background rectangle for text accessibility
     *
     * @param  ImageInterface  $image  The image to modify
     * @param  int  $x  X position
     * @param  int  $y  Y position
     * @param  array{width: float|int, height: float|int}  $textBounds  Text dimensions
     */
    private function addTextBackground(ImageInterface $image, int $x, int $y, array $textBounds): void
    {
        try {
            $bgWidth = $textBounds['width'] + (self::BACKGROUND_HORIZONTAL_PADDING * 2);
            $bgHeight = $textBounds['height'] + (self::BACKGROUND_VERTICAL_PADDING * 2);

            $image->drawRectangle($x, $y, function ($draw) use ($bgWidth, $bgHeight) {
                $draw->size($bgWidth, $bgHeight);
                $draw->background(self::BACKGROUND_COLOR);
                $draw->border('transparent', 0);
            });

        } catch (\Exception $e) {
            Log::warning('Failed to add text background', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add fallback text without background styling
     *
     * @param  ImageInterface  $image  The image to modify
     * @param  string  $text  Text to add
     * @param  int  $x  X position
     * @param  int  $y  Y position
     * @param  int  $fontSize  Font size
     * @param  string  $fontColor  Font color
     */
    private function addFallbackText(ImageInterface $image, string $text, int $x, int $y, int $fontSize, string $fontColor): void
    {
        try {
            for ($sx = -self::STROKE_WIDTH; $sx <= self::STROKE_WIDTH; $sx++) {
                for ($sy = -self::STROKE_WIDTH; $sy <= self::STROKE_WIDTH; $sy++) {
                    if ($sx !== 0 || $sy !== 0) {
                        $image->text($text, $x + $sx, $y + $sy, function ($font) use ($fontSize) {
                            $font->size($fontSize);
                            $font->color(self::STROKE_COLOR);
                            $font->align('left');
                            $font->valign('top');
                        });
                    }
                }
            }

            // Add main text
            $image->text($text, $x, $y, function ($font) use ($fontSize, $fontColor) {
                $font->size($fontSize);
                $font->color($fontColor);
                $font->align('left');
                $font->valign('top');
            });

        } catch (\Exception $e) {
            Log::error('Fallback text rendering failed', [
                'text' => $text,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
