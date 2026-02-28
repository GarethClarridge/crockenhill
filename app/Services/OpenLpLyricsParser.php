<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

class OpenLpLyricsParser
{
    /**
     * @return array{lyrics_plain: string|null, warnings: list<string>}
     */
    public function parse(string $lyricsXml): array
    {
        $warnings = [];
        $lyricsXml = trim($lyricsXml);

        if ($lyricsXml === '') {
            return [
                'lyrics_plain' => null,
                'warnings' => ['Lyrics XML is empty.'],
            ];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($lyricsXml, LIBXML_NONET);

            if ($loaded !== true) {
                return [
                    'lyrics_plain' => null,
                    'warnings' => ['Lyrics XML could not be parsed.'],
                ];
            }

            $xpath = new DOMXPath($dom);
            $verseNodes = $xpath->query('//verse');

            if ($verseNodes === false || $verseNodes->length === 0) {
                $fallback = $this->normaliseText($dom->textContent ?? '');

                if ($fallback === null) {
                    return [
                        'lyrics_plain' => null,
                        'warnings' => ['No verse nodes found in lyrics XML.'],
                    ];
                }

                return [
                    'lyrics_plain' => $fallback,
                    'warnings' => ['No verse nodes found in lyrics XML; used fallback text extraction.'],
                ];
            }

            $verses = [];

            foreach ($verseNodes as $verseNode) {
                if (! $verseNode instanceof DOMElement) {
                    continue;
                }

                $verseText = $this->extractVerseText($verseNode);
                if ($verseText !== null) {
                    $verses[] = $verseText;
                }
            }

            if ($verses === []) {
                return [
                    'lyrics_plain' => null,
                    'warnings' => ['Verse nodes were present but no text could be extracted.'],
                ];
            }

            return [
                'lyrics_plain' => implode("\n\n", $verses),
                'warnings' => $warnings,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function extractVerseText(DOMElement $verseNode): ?string
    {
        $parts = [];

        foreach ($verseNode->childNodes as $childNode) {
            $chunk = $this->normaliseText($childNode->textContent ?? '');
            if ($chunk !== null) {
                $parts[] = $chunk;
            }
        }

        if ($parts !== []) {
            return implode("\n", $parts);
        }

        return $this->normaliseText($verseNode->textContent ?? '');
    }

    private function normaliseText(string $value): ?string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\n/u', $normalised) ?: []
        );

        $normalised = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return $normalised === '' ? null : $normalised;
    }
}
