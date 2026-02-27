<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ThumbnailTextHelper
{
    /**
     * Calculate responsive font size based on image dimensions
     *
     * @param  int  $baseFontSize  Base font size for reference resolution
     * @param  int  $currentWidth  Current image width
     * @param  int  $referenceWidth  Reference width from config
     * @return int Scaled font size
     */
    public function calculateResponsiveFontSize(int $baseFontSize, int $currentWidth, int $referenceWidth): int
    {
        $scale = $currentWidth / $referenceWidth;
        // Limit scaling to prevent fonts from becoming too small or too large
        $scale = max(0.5, min(2.0, $scale));

        return (int) ($baseFontSize * $scale);
    }

    /**
     * Calculate text bounds for given text and font
     *
     * @param  string  $text  Text to measure
     * @param  int  $fontSize  Font size
     * @param  string|null  $fontPath  Path to font file
     * @return array{width: float|int, height: float|int} Text bounds [width, height]
     */
    public function calculateTextBounds(string $text, int $fontSize, ?string $fontPath = null): array
    {
        try {
            // Use GD functions to calculate text bounds if available
            if (function_exists('imagettfbbox') && $fontPath && file_exists($fontPath)) {
                $lines = explode("\n", $text);
                $maxWidth = 0;
                $totalHeight = 0;

                foreach ($lines as $line) {
                    $bbox = imagettfbbox($fontSize, 0, $fontPath, $line);
                    if ($bbox === false) {
                        continue;
                    }

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
     * Fallback text wrapping using character estimation
     *
     * @param  string  $text  Text to wrap
     * @param  int  $maxWidth  Maximum width in pixels
     * @param  int  $fontSize  Font size
     * @return string Wrapped text
     */
    public function fallbackTextWrap(string $text, int $maxWidth, int $fontSize): string
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
}
