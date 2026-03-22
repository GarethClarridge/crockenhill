<?php

declare(strict_types=1);

namespace App\Services;

class TranscriptFormatterService
{
    public function __construct(private readonly BritishEnglishConverter $britishEnglishConverter) {}

    /**
     * Format transcript as readable Markdown
     *
     * @param  string  $transcript  Raw transcript text
     * @return string Formatted Markdown content
     */
    public function formatAsMarkdown(string $transcript): string
    {
        // Clean up the transcript
        $transcript = trim($transcript);

        // Split into sentences for better processing
        $sentences = $this->splitIntoSentences($transcript);

        // Group sentences into paragraphs based on natural breaks
        $paragraphs = $this->groupSentencesIntoParagraphs($sentences);

        // Format each paragraph
        $formattedParagraphs = [];
        foreach ($paragraphs as $paragraph) {
            $formattedParagraph = $this->formatParagraph($paragraph);
            if (! empty(trim($formattedParagraph))) {
                $formattedParagraphs[] = $formattedParagraph;
            }
        }

        // Join paragraphs with proper spacing
        return implode("\n\n", $formattedParagraphs);
    }

    /**
     * Split transcript into sentences while preserving natural speech patterns
     *
     * @param  string  $transcript  Raw transcript text
     * @return array<int, string> Array of sentences
     */
    public function splitIntoSentences(string $transcript): array
    {
        // Split on sentence endings, but be careful with abbreviations and Bible references
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $transcript);
        if ($sentences === false) {
            return [];
        }

        // Clean up each sentence
        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences, function ($sentence) {
            return ! empty($sentence) && strlen($sentence) > 3;
        });

        return array_values($sentences);
    }

    /**
     * Group sentences into logical paragraphs
     *
     * @param  array<int, string>  $sentences  Array of sentences
     * @return array<int, string> Array of paragraph strings
     */
    public function groupSentencesIntoParagraphs(array $sentences): array
    {
        if (empty($sentences)) {
            return [];
        }

        $paragraphs = [];
        $currentParagraph = [];
        $sentenceCount = 0;

        foreach ($sentences as $sentence) {
            $currentParagraph[] = $sentence;
            $sentenceCount++;

            // Determine if we should start a new paragraph
            $shouldBreak = $this->shouldStartNewParagraph($sentence, $sentenceCount);

            if ($shouldBreak || $sentenceCount >= 8) { // Max 8 sentences per paragraph
                $paragraphs[] = implode(' ', $currentParagraph);
                $currentParagraph = [];
                $sentenceCount = 0;
            }
        }

        // Add any remaining sentences
        if (! empty($currentParagraph)) {
            $paragraphs[] = implode(' ', $currentParagraph);
        }

        return $paragraphs;
    }

    /**
     * Determine if a new paragraph should start after this sentence
     *
     * @param  string  $sentence  Current sentence
     * @param  int  $sentenceCount  Number of sentences in current paragraph
     * @return bool True if new paragraph should start
     */
    public function shouldStartNewParagraph(string $sentence, int $sentenceCount): bool
    {
        // Always break after at least 2 sentences
        if ($sentenceCount < 2) {
            return false;
        }

        // Break on topic transitions (common sermon phrases)
        $topicTransitions = [
            '/^(Now|So|Well|But|And so|Let me|I want to|Tonight|This morning|This evening)/i',
            '/^(Firstly|Secondly|Thirdly|Finally|In conclusion|To conclude)/i',
            '/^(Turn with me|Let\'s turn|Look at|Notice)/i',
            '/^(The first|The second|The third|The next)/i',
        ];

        foreach ($topicTransitions as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return true;
            }
        }

        // Break on Bible references at start of sentence
        if (preg_match('/^[A-Z][a-z]+ \d+/', $sentence)) {
            return true;
        }

        return false;
    }

    /**
     * Format a single paragraph with enhanced readability
     *
     * @param  string  $paragraph  Raw paragraph text
     * @return string Formatted paragraph
     */
    public function formatParagraph(string $paragraph): string
    {
        $paragraph = trim($paragraph);

        if (empty($paragraph)) {
            return '';
        }

        // Clean up spacing and punctuation
        $paragraph = $this->cleanupPunctuation($paragraph);

        return $paragraph;
    }

    /**
     * Clean up punctuation and spacing issues common in transcripts
     *
     * @param  string  $text  Text to clean up
     * @return string Cleaned text
     */
    public function cleanupPunctuation(string $text): string
    {
        // Fix multiple spaces
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        // Fix spacing around punctuation
        $text = preg_replace('/\s+([,.!?;:])/', '$1', $text) ?? $text;
        $text = preg_replace('/([,.!?;:])\s*([a-zA-Z])/', '$1 $2', $text) ?? $text;

        // Fix common transcription issues
        $text = str_replace([' ,', ' .', ' !', ' ?'], [',', '.', '!', '?'], $text);

        // Apply British English spelling corrections
        $text = $this->britishEnglishConverter->convert($text);

        // Ensure sentences end with proper punctuation
        if (! preg_match('/[.!?]$/', trim($text))) {
            $text = trim($text).'.';
        }

        return trim($text);
    }
}
