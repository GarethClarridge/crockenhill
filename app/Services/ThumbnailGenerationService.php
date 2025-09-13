<?php

namespace App\Services;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ThumbnailGenerationService
{
    private VideoSegmentationService $videoService;

    private string $storageDisk;

    private string $storagePath;

    private string $tempDisk;

    private string $tempPath;

    private array $config;

    public function __construct(VideoSegmentationService $videoService)
    {
        $this->videoService = $videoService;
        $this->config = config('thumbnail-generation');

        $this->storageDisk = $this->config['storage']['disk'];
        $this->storagePath = $this->config['storage']['path'];
        $this->tempDisk = $this->config['processing']['temp_disk'];
        $this->tempPath = $this->config['processing']['temp_path'];
    }

    /**
     * Generate thumbnail for a sermon video
     *
     * @param  Sermon  $sermon  The sermon model
     * @param  string  $videoPath  Full path to the video file
     * @return ThumbnailResult Result of thumbnail generation
     */
    public function generateThumbnail(Sermon $sermon, string $videoPath): ThumbnailResult
    {
        try {
            // Check if thumbnail generation is enabled
            if (! $this->config['enabled']) {
                return ThumbnailResult::skipped('Thumbnail generation is disabled');
            }

            // Validate video file exists
            if (! file_exists($videoPath)) {
                return ThumbnailResult::skipped('Video file not found: '.$videoPath);
            }

            // Get video metadata
            $metadata = $this->getVideoMetadata($videoPath);

            // Check minimum duration requirement
            if ($metadata['duration'] < $this->config['extraction']['min_video_duration']) {
                return ThumbnailResult::skipped('Video too short for thumbnail generation');
            }

            // Calculate optimal timestamp for frame extraction
            $timestamp = $this->calculateOptimalTimestamp($metadata['duration']);

            // Extract base frame from video
            $baseFramePath = $this->extractBaseFrame($videoPath, $timestamp);

            if (! $baseFramePath) {
                return ThumbnailResult::failed('Failed to extract frame from video');
            }

            // Create branded overlay
            $thumbnailPath = $this->createBrandedThumbnail($sermon, $baseFramePath);

            if (! $thumbnailPath) {
                $this->cleanupTempFile($baseFramePath);

                return ThumbnailResult::failed('Failed to create branded thumbnail');
            }

            // Store thumbnail in final location
            $finalPath = $this->storeThumbnail($thumbnailPath, $sermon);

            // Cleanup temporary files
            $this->cleanupTempFile($baseFramePath);
            $this->cleanupTempFile($thumbnailPath);

            if (! $finalPath) {
                return ThumbnailResult::failed('Failed to store thumbnail');
            }

            // Prepare metadata
            $resultMetadata = [
                'timestamp' => $timestamp,
                'video_duration' => $metadata['duration'],
                'video_resolution' => [
                    'width' => $metadata['width'],
                    'height' => $metadata['height'],
                ],
                'thumbnail_sizes' => $this->config['sizes'],
                'generated_at' => now()->toISOString(),
            ];

            return ThumbnailResult::success($finalPath, $resultMetadata);

        } catch (\Exception $e) {
            Log::warning('Thumbnail generation failed, skipping', [
                'sermon_id' => $sermon->id,
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);

            return ThumbnailResult::skipped($e->getMessage());
        }
    }

    /**
     * Extract base frame from video at specified timestamp
     *
     * @param  string  $videoPath  Full path to video file
     * @param  float  $timestamp  Timestamp in seconds
     * @return string|null Path to extracted frame or null on failure
     */
    public function extractBaseFrame(string $videoPath, float $timestamp): ?string
    {
        try {
            // Create temporary file for extracted frame
            $frameFilename = 'frame_'.Str::uuid().'.jpg';
            $tempFramePath = $this->tempPath.'/'.$frameFilename;

            // Ensure temp directory exists
            Storage::disk($this->tempDisk)->makeDirectory($this->tempPath);

            $fullTempPath = Storage::disk($this->tempDisk)->path($tempFramePath);

            // Use FFmpeg to extract frame at specific timestamp
            $command = [
                $this->config['ffmpeg']['path'],
                '-threads', (string) $this->config['ffmpeg']['threads'],
                '-ss', (string) $timestamp, // Seek to timestamp
                '-i', $videoPath,
                '-vframes', '1', // Extract only one frame
                '-q:v', '2', // High quality
                '-y', // Overwrite output file
                $fullTempPath,
            ];

            $process = new \Symfony\Component\Process\Process($command);
            $process->setTimeout($this->config['ffmpeg']['timeout']);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::error('FFmpeg frame extraction failed', [
                    'command' => implode(' ', $command),
                    'error' => $process->getErrorOutput(),
                ]);

                return null;
            }

            if (! file_exists($fullTempPath) || filesize($fullTempPath) === 0) {
                Log::error('Frame extraction produced no output', [
                    'temp_path' => $fullTempPath,
                ]);

                return null;
            }

            return $tempFramePath;

        } catch (\Exception $e) {
            Log::error('Frame extraction exception', [
                'video_path' => $videoPath,
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create branded thumbnail with sermon metadata overlay
     *
     * @param  Sermon  $sermon  The sermon model
     * @param  string  $baseFramePath  Path to base frame image
     * @return string|null Path to branded thumbnail or null on failure
     */
    public function createBrandedThumbnail(Sermon $sermon, string $baseFramePath): ?string
    {
        try {
            $fullBaseFramePath = Storage::disk($this->tempDisk)->path($baseFramePath);

            // Load base image using Intervention Image (following PageImageService pattern)
            $image = Image::make($fullBaseFramePath);

            // Get target size from config
            $targetWidth = $this->config['sizes']['web']['width'];
            $targetHeight = $this->config['sizes']['web']['height'];

            // Resize image to target dimensions
            $image->resize($targetWidth, $targetHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            // If image doesn't fill target dimensions, add letterboxing
            if ($image->width() !== $targetWidth || $image->height() !== $targetHeight) {
                $canvas = Image::canvas($targetWidth, $targetHeight, '#000000');
                $canvas->insert($image, 'center');
                $image = $canvas;
            }

            // Add brand overlay first (as background)
            $this->addBrandOverlay($image);

            // Add text overlays on top of brand overlay
            $this->addTextOverlays($image, $sermon);

            // Save branded thumbnail to temp location
            $thumbnailFilename = 'thumbnail_'.Str::uuid().'.jpg';
            $tempThumbnailPath = $this->tempPath.'/'.$thumbnailFilename;
            $fullTempThumbnailPath = Storage::disk($this->tempDisk)->path($tempThumbnailPath);

            // Save with quality setting (following PageImageService pattern)
            $quality = $this->config['sizes']['web']['quality'];
            $image->save($fullTempThumbnailPath, $quality);

            return $tempThumbnailPath;

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
     * Store thumbnail in final storage location
     *
     * @param  string  $thumbnailPath  Path to temporary thumbnail
     * @param  Sermon  $sermon  The sermon model
     * @return string|null Final storage path or null on failure
     */
    public function storeThumbnail(string $thumbnailPath, Sermon $sermon): ?string
    {
        try {
            $fullTempPath = Storage::disk($this->tempDisk)->path($thumbnailPath);

            // Generate final filename
            $filename = 'sermon_'.$sermon->id.'_'.date('Y-m-d').'.jpg';
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
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Calculate optimal timestamp for frame extraction based on video duration
     *
     * @param  float  $duration  Video duration in seconds
     * @return float Optimal timestamp in seconds
     */
    private function calculateOptimalTimestamp(float $duration): float
    {
        $startOffset = $this->config['extraction']['start_offset'];
        $endBuffer = $this->config['extraction']['end_buffer'];
        $fallbackPosition = $this->config['extraction']['fallback_position'];

        // For very short videos, use fallback position (midpoint)
        if ($duration <= ((float) $startOffset + (float) $endBuffer)) {
            return $duration * $fallbackPosition;
        }

        // For longer videos, extract from start_offset seconds into the video
        // but not closer than end_buffer seconds from the end
        $maxTimestamp = $duration - $endBuffer;
        $targetTimestamp = $startOffset;

        return min($targetTimestamp, $maxTimestamp);
    }

    /**
     * Add text overlays to thumbnail image
     *
     * @param  \Intervention\Image\Image  $image  The image to modify
     * @param  Sermon  $sermon  The sermon model
     */
    private function addTextOverlays($image, Sermon $sermon): void
    {
        $fontConfig = $this->config['overlay']['font'];
        $bgConfig = $this->config['overlay']['background'];
        $posConfig = $this->config['overlay']['positioning'];

        // Prepare sermon title (with word wrapping if needed)
        $title = $this->wrapText($sermon->title, $posConfig['max_title_width'], $fontConfig['title_size']);

        // Prepare service date
        $serviceDate = $sermon->date->format('F j, Y');
        if ($sermon->service) {
            $serviceDate .= ' - '.ucfirst($sermon->service->value).' Service';
        }

        // Calculate positioning based on image dimensions and percentages
        $imageWidth = $image->width();
        $imageHeight = $image->height();

        // Calculate responsive font sizes
        $titleFontSize = $this->calculateResponsiveFontSize($fontConfig['title_size'], $imageWidth, 1280);
        $dateFontSize = $this->calculateResponsiveFontSize($fontConfig['date_size'], $imageWidth, 1280);

        // Calculate center positions using percentages (text will be centered around these points)
        $titleX = $imageWidth * $posConfig['title_x_percent']; // Center horizontally
        $titleY = $imageHeight * $posConfig['title_y_percent']; // 40% down vertically

        $dateX = $imageWidth * $posConfig['date_x_percent']; // Center horizontally
        $dateY = $imageHeight * $posConfig['date_y_percent']; // 60% down vertically

        // Add title text with background
        $this->addTextWithBackground(
            $image,
            $title,
            (int) $titleX,
            (int) $titleY,
            $titleFontSize,
            $fontConfig['color'],
            $bgConfig
        );

        // Add date text with background
        $this->addTextWithBackground(
            $image,
            $serviceDate,
            (int) $dateX,
            (int) $dateY,
            $dateFontSize,
            $fontConfig['color'],
            $bgConfig
        );
    }

    /**
     * Add brand overlay to thumbnail image, stretched to fit the whole thumbnail
     *
     * @param  \Intervention\Image\Image  $image  The image to modify
     */
    private function addBrandOverlay($image): void
    {
        $brandImagePath = $this->config['overlay']['brand_image'];

        // Check if brand image exists in public_images storage (following PageImageService pattern)
        if (! Storage::disk('public_images')->exists($brandImagePath)) {
            Log::warning('Brand overlay image not found', ['path' => $brandImagePath]);

            return;
        }

        try {
            $brandImageFullPath = Storage::disk('public_images')->path($brandImagePath);
            $brandOverlay = Image::make($brandImageFullPath);

            // Get thumbnail dimensions
            $thumbnailWidth = $image->width();
            $thumbnailHeight = $image->height();

            // Stretch brand overlay to fit the entire thumbnail
            $brandOverlay->resize($thumbnailWidth, $thumbnailHeight, function ($constraint) {
                // Don't maintain aspect ratio - stretch to fill exactly
                $constraint->upsize();
            });

            // Insert the stretched brand overlay as the background at full size
            $image->insert($brandOverlay, 'top-left', 0, 0); // Insert at top-left position

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
     * @param  \Intervention\Image\Image  $image  The image to modify
     * @param  string  $text  Text to add
     * @param  int  $x  X position (center of text area)
     * @param  int  $y  Y position (center of text area)
     * @param  int  $fontSize  Font size
     * @param  string  $fontColor  Font color
     * @param  array  $bgConfig  Background configuration
     */
    private function addTextWithBackground($image, string $text, int $x, int $y, int $fontSize, string $fontColor, array $bgConfig): void
    {
        try {
            // Get font path for Oswald font (following accessibility requirements)
            $fontPath = $this->getOswaldFontPath();

            // Calculate text dimensions for background sizing
            $textBounds = $this->calculateTextBounds($text, $fontSize, $fontPath);

            // Calculate background rectangle position (centered around x,y)
            $padding = $bgConfig['padding'];
            $bgWidth = $textBounds['width'] + ($padding * 2);
            $bgHeight = $textBounds['height'] + ($padding * 2);

            $bgX = $x - ($bgWidth / 2);
            $bgY = $y - ($bgHeight / 2);

            // Create white background rectangle for accessibility
            $this->addTextBackground($image, (int) $bgX, (int) $bgY, $textBounds, $bgConfig);

            // Calculate text position (centered within the background)
            $textX = $x - ($textBounds['width'] / 2);
            $textY = $y - ($textBounds['height'] / 2);

            // Add main text with Oswald font, centered in the background
            $image->text($text, (int) $textX, (int) $textY, function ($font) use ($fontSize, $fontColor, $fontPath) {
                $font->size($fontSize);
                $font->color($fontColor);
                $font->align('left');
                $font->valign('top');
                if ($fontPath && file_exists($fontPath)) {
                    $font->file($fontPath);
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
                $testBounds = $this->calculateTextBounds($testLine, $fontSize, $fontPath);

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
            return $this->fallbackTextWrap($text, $maxWidth, $fontSize);
        }
    }

    /**
     * Fallback text wrapping using character estimation
     *
     * @param  string  $text  Text to wrap
     * @param  int  $maxWidth  Maximum width in pixels
     * @param  int  $fontSize  Font size
     * @return string Wrapped text
     */
    private function fallbackTextWrap(string $text, int $maxWidth, int $fontSize): string
    {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        // Rough estimation: each character is about fontSize/2 pixels wide for Oswald
        $charWidth = $fontSize * 0.6; // Oswald is slightly narrower than average
        $maxCharsPerLine = (int) ($maxWidth / $charWidth);

        foreach ($words as $word) {
            if (strlen($currentLine.' '.$word) <= $maxCharsPerLine) {
                $currentLine .= ($currentLine ? ' ' : '').$word;
            } else {
                if ($currentLine) {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
            }
        }

        if ($currentLine) {
            $lines[] = $currentLine;
        }

        return implode("\n", $lines);
    }

    /**
     * Get video metadata using FFProbe
     *
     * @param  string  $videoPath  Path to video file
     * @return array Video metadata
     */
    private function getVideoMetadata(string $videoPath): array
    {
        try {
            // Use existing VideoSegmentationService method
            return $this->videoService->getVideoMetadata($videoPath);
        } catch (\Exception $e) {
            Log::error('Failed to get video metadata for thumbnail', [
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);

            // Return default metadata to prevent failures
            return [
                'duration' => 0.0,
                'width' => 1920,
                'height' => 1080,
                'format_name' => 'unknown',
                'size' => 0,
                'bit_rate' => 0,
                'codec' => 'unknown',
            ];
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
            if ($this->config['processing']['cleanup_temp_files']) {
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
        // Check if sermon has video file
        if (! $sermon->hasVideo()) {
            return ThumbnailResult::skipped('Sermon has no video file for thumbnail generation');
        }

        $videoPath = Storage::disk(config('livestream-processing.sermon_disk'))->path($sermon->video_file_path);

        // Delete existing thumbnail if it exists
        if ($sermon->thumbnail_path) {
            try {
                Storage::disk($this->storageDisk)->delete($sermon->thumbnail_path);
            } catch (\Exception $e) {
                Log::warning('Failed to delete existing thumbnail', [
                    'sermon_id' => $sermon->id,
                    'thumbnail_path' => $sermon->thumbnail_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->generateThumbnail($sermon, $videoPath);
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
     * Calculate text bounds for given text and font
     *
     * @param  string  $text  Text to measure
     * @param  int  $fontSize  Font size
     * @param  string|null  $fontPath  Path to font file
     * @return array Text bounds [width, height]
     */
    private function calculateTextBounds(string $text, int $fontSize, ?string $fontPath = null): array
    {
        try {
            // Use GD functions to calculate text bounds if available
            if (function_exists('imagettfbbox') && $fontPath && file_exists($fontPath)) {
                $lines = explode("\n", $text);
                $maxWidth = 0;
                $totalHeight = 0;

                foreach ($lines as $line) {
                    $bbox = imagettfbbox($fontSize, 0, $fontPath, $line);
                    $lineWidth = $bbox[4] - $bbox[0];
                    $lineHeight = $bbox[1] - $bbox[7];

                    $maxWidth = max($maxWidth, $lineWidth);
                    $totalHeight += $lineHeight;
                }

                return [
                    'width' => $maxWidth,
                    'height' => $totalHeight,
                ];
            }
        } catch (\Exception $e) {
            Log::debug('Failed to calculate exact text bounds', ['error' => $e->getMessage()]);
        }

        // Fallback to estimation
        $lines = explode("\n", $text);
        $maxLineLength = max(array_map('strlen', $lines));

        return [
            'width' => $maxLineLength * $fontSize * 0.6, // Oswald character width estimation
            'height' => count($lines) * $fontSize * 1.2, // Line height estimation
        ];
    }

    /**
     * Add white background rectangle for text accessibility
     *
     * @param  \Intervention\Image\Image  $image  The image to modify
     * @param  int  $x  X position
     * @param  int  $y  Y position
     * @param  array  $textBounds  Text dimensions
     * @param  array  $bgConfig  Background configuration
     */
    private function addTextBackground($image, int $x, int $y, array $textBounds, array $bgConfig): void
    {
        try {
            $padding = $bgConfig['padding'];
            $bgWidth = $textBounds['width'] + ($padding * 2);
            $bgHeight = $textBounds['height'] + ($padding * 2);

            // Create solid background rectangle (no transparency for better readability)
            $bgColor = $bgConfig['color'];

            // Draw solid rectangle background
            $image->rectangle($x, $y, $x + $bgWidth, $y + $bgHeight, function ($draw) use ($bgColor) {
                $draw->background($bgColor);
                $draw->border(0, 'transparent'); // No border
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
     * @param  \Intervention\Image\Image  $image  The image to modify
     * @param  string  $text  Text to add
     * @param  int  $x  X position
     * @param  int  $y  Y position
     * @param  int  $fontSize  Font size
     * @param  string  $fontColor  Font color
     */
    private function addFallbackText($image, string $text, int $x, int $y, int $fontSize, string $fontColor): void
    {
        try {
            // Add text with white stroke for readability
            $strokeColor = '#FFFFFF';
            $strokeWidth = 2;

            // Add stroke
            for ($sx = -$strokeWidth; $sx <= $strokeWidth; $sx++) {
                for ($sy = -$strokeWidth; $sy <= $strokeWidth; $sy++) {
                    if ($sx !== 0 || $sy !== 0) {
                        $image->text($text, $x + $sx, $y + $sy, function ($font) use ($fontSize, $strokeColor) {
                            $font->size($fontSize);
                            $font->color($strokeColor);
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

    /**
     * Calculate responsive font size based on image dimensions
     *
     * @param  int  $baseFontSize  Base font size for reference resolution
     * @param  int  $currentWidth  Current image width
     * @param  int  $referenceWidth  Reference width from config
     * @return int Scaled font size
     */
    private function calculateResponsiveFontSize(int $baseFontSize, int $currentWidth, int $referenceWidth): int
    {
        $scale = $currentWidth / $referenceWidth;
        // Limit scaling to prevent fonts from becoming too small or too large
        $scale = max(0.5, min(2.0, $scale));

        return (int) ($baseFontSize * $scale);
    }
}
