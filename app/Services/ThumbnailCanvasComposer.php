<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Owns canvas composition, text layout, and low-level image manipulation
 * for the thumbnail generation pipeline.
 *
 * @phpstan-type ForegroundLayer array{
 *     image: ImageInterface,
 *     coverage: float,
 *     bounds: array{x:int,y:int,width:int,height:int},
 *     method: string
 * }
 */
class ThumbnailCanvasComposer
{
    private const int REFERENCE_WIDTH = 1920;

    private const int REF_EDGE_INSET = 75;

    private const int REF_LOGO_WIDTH = 125;

    private const int REF_TITLE_FONT_SIZE = 96;

    private const int REF_CARD_TITLE_FONT_SIZE = 84;

    private const int REF_METADATA_FONT_SIZE = 48;

    private const int REF_TITLE_MIN_FONT_SIZE = 58;

    private const int REF_CARD_TITLE_MIN_FONT_SIZE = 48;

    private const int REF_METADATA_MIN_FONT_SIZE = 28;

    private const int REF_ACCENT_WIDTH = 12;

    private const int REF_ACCENT_GAP = 24;

    private const int REF_TITLE_TO_META_GAP = 64;

    private const int REF_META_LINE_GAP = 14;

    private const int REF_TEXT_TO_SUBJECT_GAP = 42;

    private const int REF_SUBJECT_MAX_WIDTH = 760;

    private const int MAIN_TITLE_MAX_LINES = 4;

    private const int CARD_TITLE_MAX_LINES = 3;

    private const int REF_CENTERED_TITLE_FONT_SIZE = 225;

    private const int REF_CENTERED_TITLE_MIN_FONT_SIZE = 120;

    private const int CENTERED_TITLE_MAX_LINES = 3;

    private const float CENTERED_TITLE_TOP_PADDING_RATIO = 0.06;

    private const float CENTERED_TITLE_MAX_HEIGHT_RATIO = 0.65;

    private const float CENTERED_SUBJECT_OVERLAP_LINES = 1.5;

    private const float TITLE_LINE_HEIGHT = 0.92;

    private const float METADATA_LINE_HEIGHT = 1.04;

    /** @var string|false|null false = not yet resolved, null = not found */
    private string|false|null $cachedOswaldPath = false;

    /** @var string|false|null false = not yet resolved, null = not found */
    private string|false|null $cachedMontserratPath = false;

    public function __construct(
        private readonly ThumbnailTextHelper $textHelper,
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    public function buildMainThumbnailCanvas(Sermon $sermon, ?array $foreground): ImageInterface
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

        $titleTextY = $this->centerVisibleTextY(
            $titleLayout['lines'],
            $titleTopY,
            $titleLayout['height'],
            $titleLayout['font_size'],
            self::TITLE_LINE_HEIGHT,
        );

        $metadataLines = $this->mainMetadataLines($sermon);
        $metadataFontSize = $this->scaleFromReference(self::REF_METADATA_FONT_SIZE, $width);
        $metadataMinFontSize = $this->scaleFromReference(self::REF_METADATA_MIN_FONT_SIZE, $width);
        $metadataGap = $this->scaleFromReference(self::REF_META_LINE_GAP, $width);
        $metadataMaxWidth = max($this->scaleFromReference(340, $width), $textRightEdge - $inset);

        /** @var list<array{text:string,font_size:int}> $renderedMetadataLines */
        $renderedMetadataLines = [];
        if ($metadataLines !== []) {
            foreach ($metadataLines as $line) {
                $renderedMetadataLines[] = $this->fitSingleLineText($line, $metadataMaxWidth, $metadataFontSize, $metadataMinFontSize);
            }
        }

        // Place the foreground subject first so text layers render on top of it.
        $this->placeForegroundSubject($canvas, $foreground, $inset, $inset);

        $this->drawAccentLine($canvas, $inset, $titleTopY, $accentWidth, $titleLayout['height']);
        $this->drawTextLines(
            $canvas,
            $titleLayout['lines'],
            $titleStartX,
            $titleTextY,
            $titleLayout['font_size'],
            $this->foregroundColor(),
            self::TITLE_LINE_HEIGHT,
        );

        if ($renderedMetadataLines !== []) {
            $metadataTopY = $titleTopY + $titleLayout['height'] + $titleMetaGap;
            $metadataY = $metadataTopY;

            foreach ($renderedMetadataLines as $renderedLine) {
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

        return $canvas;
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    public function buildCardThumbnailCanvas(Sermon $sermon, ?array $foreground): ImageInterface
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

        $this->placeForegroundSubject($canvas, $foreground, $inset, $inset);

        return $canvas;
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    public function buildCenteredThumbnailCanvas(Sermon $sermon, ?array $foreground): ImageInterface
    {
        $canvas = $this->createCenteredBaseCanvas();
        $width = $canvas->width();
        $height = $canvas->height();

        $montserratFontPath = $this->getMontserratBlackFontPath();
        $titleMaxWidth = (int) round($width * 0.9);
        $titleFontSize = $this->scaleFromReference(self::REF_CENTERED_TITLE_FONT_SIZE, $width);
        $titleMinFontSize = $this->scaleFromReference(self::REF_CENTERED_TITLE_MIN_FONT_SIZE, $width);

        $titleLayout = $this->resolveTextLayout(
            $sermon->title,
            $titleMaxWidth,
            $titleFontSize,
            $titleMinFontSize,
            self::CENTERED_TITLE_MAX_LINES,
            self::TITLE_LINE_HEIGHT,
            $this->resolveCenteredTitleMaxHeight($height),
            $montserratFontPath,
        );

        // Keep the title near the top while allowing longer copy to occupy more of the canvas.
        $titleTopY = $this->resolveCenteredTitleTopY($height, $titleLayout['height']);
        $numLines = count($titleLayout['lines']);
        $lineSpacing = (int) round($titleLayout['font_size'] * self::TITLE_LINE_HEIGHT);
        $imageTopY = $this->resolveCenteredForegroundTopY(
            $titleTopY,
            $numLines,
            $lineSpacing,
            $titleLayout['font_size'],
        );

        $this->drawCenteredTextLines(
            $canvas,
            $titleLayout['lines'],
            (int) round($width / 2),
            $titleTopY,
            $titleLayout['font_size'],
            $this->foregroundColor(),
            self::TITLE_LINE_HEIGHT,
            $montserratFontPath,
        );

        $this->placeCenteredForegroundSubject($canvas, $foreground, $imageTopY);

        return $canvas;
    }

    /**
     * @param  array{x:int,y:int,width:int,height:int}  $bounds
     */
    public function cropForegroundToBounds(ImageInterface $image, array $bounds): ImageInterface
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

    private function createBaseCanvas(): ImageInterface
    {
        $canvas = Image::create(ThumbnailGenerationService::WEB_WIDTH, ThumbnailGenerationService::WEB_HEIGHT)
            ->fill($this->backgroundColor());
        $this->placeLogo($canvas);

        return $canvas;
    }

    private function createCenteredBaseCanvas(): ImageInterface
    {
        $canvas = Image::create(ThumbnailGenerationService::WEB_WIDTH, ThumbnailGenerationService::WEB_HEIGHT)
            ->fill($this->backgroundColor());
        $this->placeCornerOverlay($canvas);

        return $canvas;
    }

    private function placeCornerOverlay(ImageInterface $image): void
    {
        $overlayRelativePath = (string) config('thumbnail-generation.theme.corner_overlay_path', 'images/brand/CBCBrandBottomCornerOverlay.png');
        $fullOverlayPath = public_path($overlayRelativePath);

        if (! file_exists($fullOverlayPath)) {
            Log::warning('Thumbnail corner overlay image not found', ['path' => $overlayRelativePath]);

            return;
        }

        try {
            $overlay = $this->tintImage(Image::read($fullOverlayPath), $this->foregroundColor());
            $overlay->resize($image->width(), $image->height());
            $image->place($overlay, 'bottom-left', 0, 0);
        } catch (\Throwable $e) {
            Log::warning('Failed to place thumbnail corner overlay', [
                'overlay_path' => $overlayRelativePath,
                'error' => $e->getMessage(),
            ]);
        }
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
            $this->sermonViewPresenter->displayReference($sermon),
            $this->sermonViewPresenter->displayPreacherName($sermon),
            $sermon->date->format('l jS F Y'),
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== ''));
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    private function placeForegroundSubject(
        ImageInterface $canvas,
        ?array $foreground,
        int $topInset,
        int $rightInset
    ): void {
        if ($foreground === null) {
            return;
        }

        $subjectImage = $this->cloneImage($foreground['image']);
        $subjectImage = $this->flipSubjectToFaceRight($subjectImage);
        $subjectWidth = max(1, $subjectImage->width());
        $subjectHeight = max(1, $subjectImage->height());
        $maxHeight = max(1, $canvas->height() - $topInset);
        $maxCanvasWidth = max(1, $canvas->width() - $rightInset);
        $scale = $maxHeight / $subjectHeight;

        if ($scale <= 0) {
            return;
        }

        $targetWidth = max(1, (int) round($subjectWidth * $scale));
        $targetHeight = max(1, (int) round($subjectHeight * $scale));

        if ($targetWidth > $maxCanvasWidth) {
            $scale = $maxCanvasWidth / $subjectWidth;
            $targetWidth = max(1, (int) round($subjectWidth * $scale));
            $targetHeight = max(1, (int) round($subjectHeight * $scale));
        }

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
        int $maxHeight,
        ?string $fontPath = null,
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
            $wrappedText = $this->wrapText($normalizedText, $maxWidth, $fontSize, $fontPath);
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

        $wrappedText = $this->wrapText($normalizedText, $maxWidth, $minimumFontSize, $fontPath);
        $lines = $this->normalizeWrappedLines($wrappedText);

        if (count($lines) > $maxLines) {
            $overflow = array_slice($lines, $maxLines - 1);
            $lines = array_slice($lines, 0, $maxLines - 1);
            $lines[] = $this->ellipsizeText(implode(' ', $overflow), $maxWidth, $minimumFontSize, $fontPath);
        }

        while ($lines !== [] && $this->textBlockHeight(count($lines), $minimumFontSize, $lineHeightMultiplier) > $maxHeight) {
            $overflow = array_pop($lines);

            if ($lines === []) {
                $lines[] = $this->ellipsizeText((string) $overflow, $maxWidth, $minimumFontSize, $fontPath);
                break;
            }

            $lastIndex = count($lines) - 1;
            $merged = trim($lines[$lastIndex].' '.(string) $overflow);
            $lines[$lastIndex] = $this->ellipsizeText($merged, $maxWidth, $minimumFontSize, $fontPath);
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
     * @param  list<string>  $lines
     */
    private function centerVisibleTextY(
        array $lines,
        int $layoutTopY,
        int $layoutHeight,
        int $fontSize,
        float $lineHeightMultiplier
    ): int {
        $visibleHeight = $this->visibleTextBlockHeight($lines, $fontSize, $lineHeightMultiplier);

        if ($visibleHeight <= 0 || $visibleHeight >= $layoutHeight) {
            return $layoutTopY + $this->fontVisualTopOffset($fontSize);
        }

        return $layoutTopY + max(
            (int) round(($layoutHeight - $visibleHeight) / 2),
            $this->fontVisualTopOffset($fontSize),
        );
    }

    private function fontVisualTopOffset(int $fontSize): int
    {
        return max(1, (int) round($fontSize * 0.125));
    }

    /**
     * @param  list<string>  $lines
     */
    private function visibleTextBlockHeight(array $lines, int $fontSize, float $lineHeightMultiplier): int
    {
        if ($lines === []) {
            return 0;
        }

        $fontPath = $this->getOswaldFontPath();
        $maxLineHeight = 0;

        foreach ($lines as $line) {
            $bounds = $this->textHelper->calculateTextBounds($line, $fontSize, $fontPath);
            $maxLineHeight = max($maxLineHeight, (int) round($bounds['height']));
        }

        return (int) round(($fontSize * $lineHeightMultiplier * max(0, count($lines) - 1)) + $maxLineHeight);
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

    private function ellipsizeText(string $text, int $maxWidth, int $fontSize, ?string $overrideFontPath = null): string
    {
        $normalizedText = trim($text);
        $fontPath = $overrideFontPath ?? $this->getOswaldFontPath();

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

    public function wrapText(string $text, int $maxWidth, int $fontSize, ?string $overrideFontPath = null): string
    {
        try {
            $normalizedText = trim($text);
            if ($normalizedText === '') {
                return '';
            }

            $fontPath = $overrideFontPath ?? $this->getOswaldFontPath();
            $words = preg_split('/\s+/', $normalizedText) ?: [];
            $lines = [];
            $currentLine = '';

            foreach ($words as $word) {
                $testLine = $currentLine === '' ? $word : $currentLine.' '.$word;
                $bounds = $this->textHelper->calculateTextBounds($testLine, $fontSize, $fontPath);

                if ((int) round($bounds['width']) <= $maxWidth) {
                    $currentLine = $testLine;

                    continue;
                }

                if ($currentLine === '') {
                    // Single word overflows — break at character level rather than accepting overflow.
                    $partial = '';
                    foreach (mb_str_split($word) as $char) {
                        $testPartial = $partial.$char;
                        $charBounds = $this->textHelper->calculateTextBounds($testPartial, $fontSize, $fontPath);

                        if ((int) round($charBounds['width']) > $maxWidth && $partial !== '') {
                            $lines[] = $partial;
                            $partial = $char;
                        } else {
                            $partial .= $char;
                        }
                    }

                    $currentLine = $partial;

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

    private function cloneImage(ImageInterface $image): ImageInterface
    {
        $native = $image->core()->native();

        if (! ($native instanceof \GdImage)) {
            throw new \RuntimeException('Image cloning requires the GD image driver.');
        }

        return Image::read($this->encodeGdImage($native));
    }

    /**
     * Flips the subject horizontally if its opaque centre-of-mass is in the left half,
     * so the body faces toward the right side of the canvas where it will be placed.
     */
    private function flipSubjectToFaceRight(ImageInterface $image): ImageInterface
    {
        $native = $image->core()->native();

        if (! ($native instanceof \GdImage)) {
            return $image;
        }

        $imageWidth = imagesx($native);
        $imageHeight = imagesy($native);
        $weightedX = 0.0;
        $opaquePixels = 0;

        for ($y = 0; $y < $imageHeight; $y++) {
            for ($x = 0; $x < $imageWidth; $x++) {
                $colorIndex = imagecolorat($native, $x, $y);
                if ($colorIndex === false) {
                    continue;
                }

                $color = imagecolorsforindex($native, $colorIndex);
                if ((int) $color['alpha'] >= 127) {
                    continue;
                }

                $weightedX += $x;
                $opaquePixels++;
            }
        }

        if ($opaquePixels === 0) {
            return $image;
        }

        $centreX = $weightedX / $opaquePixels;

        if ($centreX >= ($imageWidth / 2)) {
            return $image;
        }

        // $native is the GdImage object held by $image — imageflip mutates it in-place,
        // so the flip is immediately visible through the Intervention Image wrapper.
        imageflip($native, IMG_FLIP_HORIZONTAL);

        return $image;
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
        return $this->textHelper->hexToRgb($hexColor);
    }

    private function encodeGdImage(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function getOswaldFontPath(): ?string
    {
        if ($this->cachedOswaldPath !== false) {
            return $this->cachedOswaldPath;
        }

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
                return $this->cachedOswaldPath = $path;
            }
        }

        Log::warning('Oswald font not found, text will use system default');

        return $this->cachedOswaldPath = null;
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

    /**
     * @param  list<string>  $lines
     */
    private function drawCenteredTextLines(
        ImageInterface $image,
        array $lines,
        int $centerX,
        int $y,
        int $fontSize,
        string $fontColor,
        float $lineHeightMultiplier,
        ?string $fontPath = null,
    ): void {
        $resolvedFontPath = $fontPath ?? $this->getMontserratBlackFontPath();

        foreach ($lines as $index => $line) {
            $lineY = $y + (int) round($index * $fontSize * $lineHeightMultiplier);

            $image->text($line, $centerX, $lineY, function ($font) use ($fontSize, $fontColor, $resolvedFontPath): void {
                $font->size($fontSize);
                $font->color($fontColor);
                $font->align('center');
                $font->valign('top');

                if ($resolvedFontPath !== null && file_exists($resolvedFontPath)) {
                    $font->filename($resolvedFontPath);
                }
            });
        }
    }

    /**
     * @param  ForegroundLayer|null  $foreground
     */
    private function placeCenteredForegroundSubject(
        ImageInterface $canvas,
        ?array $foreground,
        int $imageTopY,
    ): void {
        if ($foreground === null) {
            return;
        }

        $subjectImage = $this->cloneImage($foreground['image']);
        $subjectImage = $this->flipSubjectToFaceRight($subjectImage);
        $subjectWidth = max(1, $subjectImage->width());
        $subjectHeight = max(1, $subjectImage->height());

        $availableHeight = max(1, $canvas->height() - $imageTopY);
        $scale = $availableHeight / $subjectHeight;

        $targetWidth = max(1, (int) round($subjectWidth * $scale));
        $targetHeight = max(1, (int) round($subjectHeight * $scale));

        if ($targetWidth > $canvas->width()) {
            $scale = $canvas->width() / $subjectWidth;
            $targetWidth = max(1, (int) round($subjectWidth * $scale));
            $targetHeight = max(1, (int) round($subjectHeight * $scale));
        }

        $subjectImage->resize($targetWidth, $targetHeight);

        $x = (int) round(($canvas->width() - $targetWidth) / 2);
        $canvas->place($subjectImage, 'top-left', $x, $imageTopY);
    }

    private function resolveCenteredTitleMaxHeight(int $height): int
    {
        return (int) round($height * self::CENTERED_TITLE_MAX_HEIGHT_RATIO);
    }

    private function resolveCenteredTitleTopY(int $height, int $titleLayoutHeight): int
    {
        $titleTopPadding = (int) round($height * self::CENTERED_TITLE_TOP_PADDING_RATIO);

        return max(
            $titleTopPadding,
            (int) round(($height / 4) - ($titleLayoutHeight / 2)),
        );
    }

    private function resolveCenteredForegroundTopY(
        int $titleTopY,
        int $lineCount,
        int $lineSpacing,
        int $fontSize,
    ): int {
        if ($lineCount <= 0) {
            return $titleTopY;
        }

        $fullyOverlappedLines = (int) floor(self::CENTERED_SUBJECT_OVERLAP_LINES);
        $partialLineOverlap = self::CENTERED_SUBJECT_OVERLAP_LINES - $fullyOverlappedLines;
        $overlapStartLineIndex = max(0, $lineCount - $fullyOverlappedLines - 1);

        return $titleTopY
            + ($overlapStartLineIndex * $lineSpacing)
            + (int) round($fontSize * $partialLineOverlap);
    }

    private function getMontserratBlackFontPath(): ?string
    {
        if ($this->cachedMontserratPath !== false) {
            return $this->cachedMontserratPath;
        }

        // Prefer static weight-900 cut — variable font axis syntax (:wght=900) requires
        // FreeType 2.8.1+ which is not available in all environments.
        $fontPaths = [
            public_path('fonts/Montserrat-Black.ttf'),
            public_path('fonts/montserrat-black.ttf'),
            storage_path('fonts/montserrat-black.ttf'),
            // Variable font as last-resort (renders at default weight without axis support)
            public_path('fonts/Montserrat-VariableFont_wght.ttf'),
            public_path('fonts/Oswald-Regular.ttf'),
            public_path('fonts/oswald-regular.ttf'),
            storage_path('fonts/oswald-regular.ttf'),
            '/System/Library/Fonts/Helvetica.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ];

        foreach ($fontPaths as $path) {
            if (file_exists($path)) {
                return $this->cachedMontserratPath = $path;
            }
        }

        Log::warning('Montserrat Black font not found, text will use system default');

        return $this->cachedMontserratPath = null;
    }
}
