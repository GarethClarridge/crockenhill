<?php

declare(strict_types=1);

namespace App\Services\Song;

use DOMDocument;
use DOMElement;
use DOMXPath;

class OpenLpLyricsParser
{
    /**
     * @return array{lyrics_plain: string|null, warnings: list<string>}
     */
    public function parse(string $lyricsXml, ?string $verseOrder = null): array
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

            $verseRows = [];

            foreach ($verseNodes as $verseNode) {
                if (! $verseNode instanceof DOMElement) {
                    continue;
                }

                $verseText = $this->extractVerseText($verseNode);
                if ($verseText !== null) {
                    $verseRows[] = [
                        'key' => $this->extractVerseKey($verseNode),
                        'text' => $verseText,
                    ];
                }
            }

            if ($verseRows === []) {
                return [
                    'lyrics_plain' => null,
                    'warnings' => ['Verse nodes were present but no text could be extracted.'],
                ];
            }

            $verses = $this->orderVerses($verseRows, $verseOrder, $warnings);

            return [
                'lyrics_plain' => implode("\n\n", $verses),
                'warnings' => $warnings,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  list<array{key:string|null,text:string}>  $verseRows
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function orderVerses(array $verseRows, ?string $verseOrder, array &$warnings): array
    {
        $xmlOrder = array_map(
            static fn (array $row): string => $row['text'],
            $verseRows
        );

        $tokens = $this->parseVerseOrderTokens($verseOrder);
        if ($tokens === []) {
            return $xmlOrder;
        }

        $verseMap = [];

        foreach ($verseRows as $row) {
            $key = $row['key'];

            if ($key === null || array_key_exists($key, $verseMap)) {
                continue;
            }

            $verseMap[$key] = $row['text'];
        }

        $ordered = [];
        $matchedTokens = [];
        $missingTokens = [];

        foreach ($tokens as $token) {
            $verse = $verseMap[$token] ?? null;

            if ($verse === null) {
                $missingTokens[$token] = true;

                continue;
            }

            $ordered[] = $verse;
            $matchedTokens[$token] = true;
        }

        if ($ordered === []) {
            $warnings[] = 'Verse order could not be matched to verse keys; used XML verse order.';

            return $xmlOrder;
        }

        foreach ($verseRows as $row) {
            $key = $row['key'];

            if ($key === null || ! array_key_exists($key, $matchedTokens)) {
                $ordered[] = $row['text'];
            }
        }

        if ($missingTokens !== []) {
            $warnings[] = 'Verse order referenced missing verse keys: '.implode(', ', array_keys($missingTokens)).'.';
        }

        return $ordered;
    }

    private function extractVerseKey(DOMElement $verseNode): ?string
    {
        $name = $this->normaliseVerseToken($verseNode->getAttribute('name'));
        if ($name !== null) {
            return $name;
        }

        $type = $this->normaliseVerseToken($verseNode->getAttribute('type'));
        $label = $this->normaliseVerseToken($verseNode->getAttribute('label'));

        if ($type !== null && $label !== null) {
            return $type.$label;
        }

        return $label;
    }

    /**
     * @return list<string>
     */
    private function parseVerseOrderTokens(?string $verseOrder): array
    {
        if (! is_string($verseOrder) || trim($verseOrder) === '') {
            return [];
        }

        $rawTokens = preg_split('/[\s,;]+/u', $verseOrder);
        if (! is_array($rawTokens)) {
            return [];
        }

        $tokens = [];

        foreach ($rawTokens as $rawToken) {
            $token = $this->normaliseVerseToken($rawToken);
            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private function normaliseVerseToken(string $value): ?string
    {
        $lowered = strtolower(trim($value));
        if ($lowered === '') {
            return null;
        }

        $normalised = (string) preg_replace('/[^a-z0-9]+/', '', $lowered);

        return $normalised === '' ? null : $normalised;
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
