<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Services\Scripture\ScriptureReferenceResolver;
use App\Support\OpenAiChatPayload;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;
use TypeError;

/**
 * Derives the scripture reference of a bible-reading section from its recited transcript.
 *
 * A reader often launches straight into a passage ("Praise the Lord from the heavens…")
 * without announcing the reference, so the model is asked to *name* the passage from the
 * content; the result is then forced through {@see ScriptureReferenceResolver::normalize()}
 * so only a canonical, parseable reference is ever returned. Anything the model cannot
 * confidently name (a benediction, a prayer) resolves to null rather than fabricated data.
 *
 * The `mock` analysis service short-circuits the live call and only recognises references the
 * transcript explicitly states, keeping the resolution deterministic in tests.
 */
class ReadingReferenceExtractor
{
    public function __construct(
        private readonly ScriptureReferenceResolver $resolver,
    ) {}

    /**
     * @return array{reference: string|null, confidence: float, source: 'transcript_ai'|'none', raw: string|null}
     */
    public function extract(string $transcript): array
    {
        $transcript = trim($transcript);

        if ($transcript === '') {
            return $this->none();
        }

        $result = $this->requestReference($transcript);
        $raw = $result['reference'];
        $confidence = $result['confidence'];

        if (! is_string($raw) || trim($raw) === '' || strtolower(trim($raw)) === 'null') {
            return $this->none(is_string($raw) ? $raw : null);
        }

        if ($confidence < (float) config('media-processing.reading_references.min_confidence', 0.6)) {
            return $this->none($raw);
        }

        // normalizeAll keeps every passage of a multi-part reading —
        // normalize() would truncate "Luke 18:31-33, 35-43" to its first
        // subrange and lose the rest.
        $normalized = $this->resolver->normalizeAll($raw);

        if ($normalized === null) {
            return $this->none($raw);
        }

        return [
            'reference' => $normalized,
            'confidence' => $confidence,
            'source' => 'transcript_ai',
            'raw' => $raw,
        ];
    }

    /**
     * @return array{reference: string|null, confidence: float}
     */
    protected function requestReference(string $transcript): array
    {
        return match ((string) config('media-processing.analysis.service', 'mock')) {
            'mock' => $this->mockReference($transcript),
            default => $this->openAiReference($transcript),
        };
    }

    /**
     * Deterministic stand-in for the live model: only surfaces a reference the transcript
     * states outright. The parser expects a reference-anchored string, so each candidate window
     * is progressively trimmed of leading filler ("our reading is from …") until it parses.
     *
     * @return array{reference: string|null, confidence: float}
     */
    private function mockReference(string $transcript): array
    {
        $reference = $this->scanForExplicitReference($transcript);

        return [
            'reference' => $reference,
            'confidence' => $reference !== null ? 0.9 : 0.0,
        ];
    }

    private function scanForExplicitReference(string $transcript): ?string
    {
        // The verse expression accepts comma-separated continuations, each of
        // which may reopen a chapter ("3:16-18, 4:1-2") or stay within it
        // ("18:31-33, 35-43"), so a multi-part reading survives as one
        // candidate and normalizeAll keeps every passage of it — matching the
        // live model path above.
        $pattern = '/(?:[A-Za-z\']+\s+){0,6}(?:chapter\s+)?\d{1,3}(?:\s*:\s*\d{1,3}(?:\s*-\s*\d{1,3})?(?:\s*,\s*\d{1,3}(?:\s*:\s*\d{1,3})?(?:\s*-\s*\d{1,3})?)*|\s+verses?\s+\d{1,3}(?:\s*(?:-|to|through)\s*\d{1,3})?)?/i';

        if (preg_match_all($pattern, $transcript, $matches) === false) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $words = preg_split('/\s+/', trim((string) $candidate), -1, PREG_SPLIT_NO_EMPTY);

            if (! is_array($words)) {
                continue;
            }

            for ($start = 0, $count = count($words); $start < $count; $start++) {
                $normalized = $this->resolver->normalizeAll(implode(' ', array_slice($words, $start)));

                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    /**
     * @return array{reference: string|null, confidence: float}
     */
    private function openAiReference(string $transcript): array
    {
        if (empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for reading reference extraction.');
        }

        try {
            $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
                'model' => (string) config('media-processing.reading_references.model', 'gpt-5-mini'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => <<<'TEXT'
You identify the single Bible passage being read aloud in a church service transcript.
The reader frequently recites the passage WITHOUT naming it, so infer the reference from the
content of the words themselves. Return valid JSON only with this shape:
{"reference":"Joshua 2:1-24" or null,"confidence":0.0}
Rules:
- Use a standard reference: Book Chapter:Verse, optionally a verse range (e.g. "Psalm 148", "Joshua 2:1-24", "1 John 3:16-18").
- If the text is NOT a scripture reading — a prayer, a benediction, notices, or general speech — return reference null with low confidence.
- Confidence reflects how certain you are of the passage identity, from 0.0 to 1.0.
- Do not guess: a vague or non-scriptural passage must return null.
- Use British English.
TEXT,
                    ],
                    [
                        'role' => 'user',
                        'content' => "Transcript:\n".$transcript,
                    ],
                ],
                'temperature' => 0.0,
                'max_completion_tokens' => 1000,
            ]));
        } catch (TypeError $exception) {
            throw new RuntimeException(
                'OpenAI reading reference response malformed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $content = $response->choices[0]->message->content ?? null;

        if (! is_string($content) || trim($content) === '') {
            return ['reference' => null, 'confidence' => 0.0];
        }

        return $this->decodeReference($content);
    }

    /**
     * @return array{reference: string|null, confidence: float}
     */
    private function decodeReference(string $content): array
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            $jsonStart = strpos($content, '{');
            $jsonEnd = strrpos($content, '}');

            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $decoded = json_decode(substr($content, $jsonStart, $jsonEnd - $jsonStart + 1), true);
            }
        }

        if (! is_array($decoded)) {
            return ['reference' => null, 'confidence' => 0.0];
        }

        $reference = $decoded['reference'] ?? null;
        $confidence = $decoded['confidence'] ?? 0.0;

        return [
            'reference' => is_string($reference) ? $reference : null,
            'confidence' => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0,
        ];
    }

    /**
     * @return array{reference: null, confidence: float, source: 'none', raw: string|null}
     */
    private function none(?string $raw = null): array
    {
        return ['reference' => null, 'confidence' => 0.0, 'source' => 'none', 'raw' => $raw];
    }
}
