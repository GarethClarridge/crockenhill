<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Data\SermonAnalysis;
use App\Services\BritishEnglishConverter;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;

/**
 * @phpstan-import-type SermonAnalysisResult from SermonAnalysisService
 * @phpstan-import-type RawAiAnalysisData from SermonAnalysisService
 */
class SermonAnalysisValidator
{
    use SanitizesLogData;

    private const MIN_TRANSCRIPT_LENGTH = 100;

    public function __construct(
        private readonly BritishEnglishConverter $britishEnglishConverter,
        private readonly ScriptureReferenceResolver $scriptureReferenceResolver,
    ) {}

    /**
     * Validate transcript content
     *
     * @param  string  $transcript  The transcript to validate
     * @return bool True if transcript is valid
     */
    public function validateTranscript(string $transcript): bool
    {
        $transcript = trim($transcript);

        // Must have minimum length
        if (strlen($transcript) < self::MIN_TRANSCRIPT_LENGTH) {
            Log::warning('Transcript too short for analysis', $this->sanitizeArrayForLog([
                'length' => strlen($transcript),
                'minimum' => self::MIN_TRANSCRIPT_LENGTH,
            ]));

            return false;
        }

        // Must have reasonable word count
        $wordCount = str_word_count($transcript);
        if ($wordCount < 20) {
            Log::warning('Transcript has too few words for analysis', $this->sanitizeArrayForLog([
                'word_count' => $wordCount,
            ]));

            return false;
        }

        return true;
    }

    /**
     * Validate and clean raw AI analysis data.
     *
     * Normalises types, applies British English spelling corrections, enforces
     * word limits on titles, and ensures a valid structure for the SermonAnalysis DTO.
     *
     * @param  RawAiAnalysisData  $analysisData  Raw analysis data from AI
     * @param  string  $originalTranscript  Original transcript for fallback
     * @return SermonAnalysisResult Validated and cleaned analysis data
     */
    public function validateAndCleanAnalysisData(array $analysisData, string $originalTranscript): array
    {
        // Validate and clean title
        $title = $this->validateAndCleanTitle((string) ($analysisData['title'] ?? ''));

        // Validate series (must be null or non-empty string)
        $series = $this->sanitizeAiNullableString($analysisData['series'] ?? null);

        // Validate reference (must be null or valid Bible reference format)
        $reference = $this->sanitizeAiNullableString($analysisData['reference'] ?? null);
        if ($reference !== null) {
            $reference = $this->validateBibleReference($reference);
        }

        // Validate points (must be array of strings)
        $points = [];
        if (filled($analysisData['points'] ?? null) && is_array($analysisData['points'])) {
            foreach ($analysisData['points'] as $point) {
                if (filled($point) && is_string($point)) {
                    $cleanPoint = $this->britishEnglishConverter->convert(trim($point));
                    $points[] = $cleanPoint;
                }
            }
        }

        // Ensure we have at least some points
        if (empty($points)) {
            $points = ['Main Message']; // Fallback point
        }

        // Validate and clean summary
        $summary = $this->validateAndCleanSummary((string) ($analysisData['summary'] ?? ''));

        return [
            'title' => $title,
            'series' => $series,
            'reference' => $reference,
            'points' => $points,
            'summary' => $summary,
            'transcript' => $originalTranscript,
        ];
    }

    /**
     * Validate and clean sermon title.
     *
     * Trims whitespace, removes wrapping quotes, applies British English
     * spelling corrections, and enforces the MAX_TITLE_WORDS limit.
     *
     * @param  string  $title  Raw title from AI
     * @return string Validated and cleaned title
     */
    public function validateAndCleanTitle(string $title): string
    {
        if (blank($title)) {
            return 'Untitled sermon';
        }

        $title = trim($title);

        // Remove quotes if present
        $title = trim($title, '"\'');

        // Apply British English spelling corrections
        $title = $this->britishEnglishConverter->convert($title);

        // Limit to maximum words
        $title = SermonAnalysis::truncateTitle($title);

        // Ensure title is not too short
        if (strlen($title) < 3) {
            return 'Untitled sermon';
        }

        return $title;
    }

    /**
     * Check whether a title exceeds the character limit.
     */
    public function isTitleTooLong(string $title): bool
    {
        return strlen($title) > SermonAnalysis::MAX_TITLE_CHARACTERS;
    }

    /**
     * Validate and normalise a Bible reference.
     *
     * Delegates to the shared scripture parser (techwilk/bible-verse-parser via
     * {@see ScriptureReferenceResolver}), which understands abbreviations such as
     * "Jn" or "1Jn", verse ranges, and "chapter/verse" wording, and rejects prose
     * or gibberish. Returns the canonical reference (e.g. "Jn 3:16" -> "John 3:16"),
     * preserving every passage of multi-part references, or null when the input is
     * not a parseable Bible reference.
     *
     * A bare whole-book reference (e.g. "John", "Genesis") is rejected: a primary
     * sermon passage must identify at least a chapter, and a whole book belongs in
     * the series field rather than the reference.
     *
     * @param  string  $reference  Raw Bible reference
     * @return string|null Canonical reference or null if it cannot be parsed
     */
    public function validateBibleReference(string $reference): ?string
    {
        $normalized = $this->scriptureReferenceResolver->normalizeAll($reference);

        if ($normalized === null || ! $this->referenceIncludesChapter($normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Determine whether every passage in a canonical reference names a chapter.
     *
     * The parser collapses a whole-book passage to the bare book name (e.g. "John",
     * "1 John"), so once any leading book ordinal is removed a chapter is present
     * only when a digit remains.
     */
    private function referenceIncludesChapter(string $canonicalReference): bool
    {
        foreach (explode(',', $canonicalReference) as $passage) {
            $withoutBookOrdinal = preg_replace('/^\s*[1-3]\s+/', '', trim($passage));

            if (! preg_match('/\d/', (string) $withoutBookOrdinal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate and clean sermon summary.
     *
     * Trims whitespace, removes quotes, applies British English corrections,
     * and limits to approximately 200 words, attempting to end on a full sentence.
     *
     * @param  string  $summary  Raw summary from AI
     * @return string|null Validated and cleaned summary or null if invalid
     */
    public function validateAndCleanSummary(string $summary): ?string
    {
        if (blank($summary)) {
            return null;
        }

        $summary = trim($summary);

        // Remove quotes if present
        $summary = trim($summary, '"\'');

        // Ensure summary is not too short to be meaningful
        if (strlen($summary) < 20) {
            return null;
        }

        // Apply British English spelling corrections
        $summary = $this->britishEnglishConverter->convert($summary);

        // Limit to approximately 200 words
        $words = explode(' ', $summary);
        if (count($words) > 200) {
            $words = array_slice($words, 0, 200);
            $summary = implode(' ', $words);

            // Try to end on a complete sentence
            $lastPeriod = strrpos($summary, '.');
            $lastExclamation = strrpos($summary, '!');
            $lastQuestion = strrpos($summary, '?');

            $lastSentenceEnd = max($lastPeriod, $lastExclamation, $lastQuestion);

            if ($lastSentenceEnd !== false && $lastSentenceEnd > strlen($summary) * 0.8) {
                $summary = substr($summary, 0, $lastSentenceEnd + 1);
            }
        }

        return $summary;
    }

    /**
     * Sanitize a nullable string from AI response.
     *
     * Handles common AI patterns like 'null', 'none', and empty strings
     * by normalizing them to PHP null.
     */
    private function sanitizeAiNullableString(mixed $value): ?string
    {
        if (! filled($value) || ! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if (in_array(strtolower($trimmed), ['null', 'none'], true)) {
            return null;
        }

        return $trimmed;
    }
}
