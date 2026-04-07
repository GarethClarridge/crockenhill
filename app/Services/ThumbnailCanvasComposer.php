<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
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

    private const float TITLE_LINE_HEIGHT = 0.92;

    private const float METADATA_LINE_HEIGHT = 1.04;

    public function __construct(
        private readonly ThumbnailTextHelper $textHelper
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

        $this->placeForegroundSubject($canvas, $foreground, $subjectMaxWidth, $inset, $inset);

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

    private function encodeGdImage(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
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
}
