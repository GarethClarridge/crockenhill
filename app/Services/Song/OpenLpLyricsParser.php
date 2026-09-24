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
        $read = $this->read($lyricsXml, $warnings);

        if (is_string($read)) {
            return ['lyrics_plain' => $read, 'warnings' => $warnings];
        }

        if ($read === null) {
            return ['lyrics_plain' => null, 'warnings' => $warnings];
        }

        $sequence = $this->orderVerses($read, $verseOrder, $warnings, keepUnnamed: true);

        return [
            'lyrics_plain' => implode("\n\n", array_column($sequence['verses'], 'text')),
            'warnings' => $warnings,
        ];
    }

    /**
     * The verses in the order the church sings them, repeats included, each with its key
     * (`v1`, `c1`). `ordered` says a recorded verse order produced the sequence, which then holds
     * only the verses it names; without one it is the document order, in which a chorus appears
     * once however often it is sung.
     *
     * @return array{verses: list<array{key: string|null, text: string}>, ordered: bool}
     */
    public function sequence(string $lyricsXml, ?string $verseOrder = null): array
    {
        $warnings = [];
        $read = $this->read($lyricsXml, $warnings);

        if (! is_array($read)) {
            return ['verses' => [], 'ordered' => false];
        }

        return $this->orderVerses($read, $verseOrder, $warnings, keepUnnamed: false);
    }

    /**
     * The document's verses, or plain text when it has no verse nodes, or null when nothing
     * could be read.
     *
     * @param  list<string>  $warnings
     * @return non-empty-list<array{key: string|null, text: string}>|string|null
     */
    private function read(string $lyricsXml, array &$warnings): array|string|null
    {
        $lyricsXml = trim($lyricsXml);

        if ($lyricsXml === '') {
            $warnings[] = 'Lyrics XML is empty.';

            return null;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($lyricsXml, LIBXML_NONET);

            if ($loaded !== true) {
                $warnings[] = 'Lyrics XML could not be parsed.';

                return null;
            }

            $xpath = new DOMXPath($dom);
            $verseNodes = $xpath->query('//verse');

            if ($verseNodes === false || $verseNodes->length === 0) {
                $fallback = $this->normaliseText($dom->textContent ?? '');

                if ($fallback === null) {
                    $warnings[] = 'No verse nodes found in lyrics XML.';

                    return null;
                }

                $warnings[] = 'No verse nodes found in lyrics XML; used fallback text extraction.';

                return $fallback;
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
                $warnings[] = 'Verse nodes were present but no text could be extracted.';

                return null;
            }

            return $verseRows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  list<array{key:string|null,text:string}>  $verseRows
     * @param  list<string>  $warnings
     * @param  bool  $keepUnnamed  Append the verses a recorded order never names, as the plain lyrics do
     * @return array{verses: list<array{key: string|null, text: string}>, ordered: bool}
     */
    private function orderVerses(array $verseRows, ?string $verseOrder, array &$warnings, bool $keepUnnamed): array
    {
        $tokens = $this->parseVerseOrderTokens($verseOrder);
        if ($tokens === []) {
            return ['verses' => $verseRows, 'ordered' => false];
        }

        $verseMap = [];

        foreach ($verseRows as $row) {
            $key = $row['key'];

            if ($key === null || array_key_exists($key, $verseMap)) {
                continue;
            }

            $verseMap[$key] = $row;
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

            return ['verses' => $verseRows, 'ordered' => false];
        }

        foreach ($keepUnnamed ? $verseRows : [] as $row) {
            $key = $row['key'];

            if ($key === null || ! array_key_exists($key, $matchedTokens)) {
                $ordered[] = $row;
            }
        }

        if ($missingTokens !== []) {
            $warnings[] = 'Verse order referenced missing verse keys: '.implode(', ', array_keys($missingTokens)).'.';
        }

        return ['verses' => $ordered, 'ordered' => true];
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
