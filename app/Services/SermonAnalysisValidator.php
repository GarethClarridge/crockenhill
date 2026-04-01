<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SermonAnalysisValidator
{
    public const MAX_TITLE_WORDS = 12;

    public const MAX_TITLE_CHARACTERS = 60;

    private const MIN_TRANSCRIPT_LENGTH = 100;

    public function __construct(private readonly BritishEnglishConverter $britishEnglishConverter) {}

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
            Log::warning('Transcript too short for analysis', [
                'length' => strlen($transcript),
                'minimum' => self::MIN_TRANSCRIPT_LENGTH,
            ]);

            return false;
        }

        // Must have reasonable word count
        $wordCount = str_word_count($transcript);
        if ($wordCount < 20) {
            Log::warning('Transcript has too few words for analysis', [
                'word_count' => $wordCount,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Validate and clean the AI analysis data
     *
     * @param  array<string, mixed>  $analysisData  Raw analysis data from AI
     * @param  string  $originalTranscript  Original transcript for fallback
     * @return array<string, mixed> Validated and cleaned analysis data
     */
    public function validateAndCleanAnalysisData(array $analysisData, string $originalTranscript): array
    {
        // Validate and clean title
        $rawTitle = $analysisData['title'] ?? '';
        $title = $this->validateAndCleanTitle(is_string($rawTitle) ? $rawTitle : (string) $rawTitle);

        // Validate series (must be null or non-empty string)
        $series = null;
        if (! empty($analysisData['series']) && is_string($analysisData['series'])) {
            $series = trim($analysisData['series']);
            if (empty($series) || strtolower($series) === 'null' || strtolower($series) === 'none') {
                $series = null;
            }
        }

        // Validate reference (must be null or valid Bible reference format)
        $reference = null;
        if (! empty($analysisData['reference']) && is_string($analysisData['reference'])) {
            $reference = trim($analysisData['reference']);
            if (empty($reference) || strtolower($reference) === 'null' || strtolower($reference) === 'none') {
                $reference = null;
            } else {
                $reference = $this->validateBibleReference($reference);
            }
        }

        // Validate points (must be array of strings)
        $points = [];
        if (isset($analysisData['points']) && is_array($analysisData['points'])) {
            foreach ($analysisData['points'] as $point) {
                if (is_string($point) && ! empty(trim($point))) {
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
        $rawSummary = $analysisData['summary'] ?? '';
        $summary = $this->validateAndCleanSummary(is_string($rawSummary) ? $rawSummary : (string) $rawSummary);

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
     * Validate and clean sermon title
     *
     * @param  string  $title  Raw title from AI
     * @return string Validated and cleaned title
     */
    public function validateAndCleanTitle(string $title): string
    {
        $title = trim($title);

        if (empty($title)) {
            return 'Untitled sermon';
        }

        // Remove quotes if present
        $title = trim($title, '"\'');

        // Apply British English spelling corrections
        $title = $this->britishEnglishConverter->convert($title);

        // Limit to maximum words
        $words = explode(' ', $title);
        if (count($words) > self::MAX_TITLE_WORDS) {
            $words = array_slice($words, 0, self::MAX_TITLE_WORDS);
            $title = implode(' ', $words);
        }

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
        return strlen($title) > self::MAX_TITLE_CHARACTERS;
    }

    /**
     * Validate Bible reference format
     *
     * @param  string  $reference  Raw Bible reference
     * @return string|null Validated reference or null if invalid
     */
    public function validateBibleReference(string $reference): ?string
    {
        $reference = trim($reference);

        // Basic validation - should contain book name and numbers
        if (preg_match('/^[1-3]?\s*[A-Za-z]+\s+\d+/', $reference)) {
            return $reference;
        }

        // If it doesn't match basic pattern, return null
        return null;
    }

    /**
     * Validate and clean sermon summary
     *
     * @param  string  $summary  Raw summary from AI
     * @return string|null Validated and cleaned summary or null if invalid
     */
    public function validateAndCleanSummary(string $summary): ?string
    {
        $summary = trim($summary);

        if (empty($summary)) {
            return null;
        }

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
}
