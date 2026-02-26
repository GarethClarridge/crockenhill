<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use Illuminate\Support\Facades\Log;

class MockSermonAnalysisService implements SermonAnalysisInterface
{
    public function __construct(private readonly BritishEnglishConverter $britishEnglishConverter) {}

    /**
     * Analyze sermon transcript using mock data generation
     *
     * @param  array<int, string>  $existingSeries
     */
    public function analyzeSermon(string $transcript, array $existingSeries = []): SermonAnalysis
    {
        $startTime = microtime(true);

        // Generate realistic title
        $mockTitle = $this->generateMockTitle($transcript);

        // Generate realistic series identification
        $mockSeries = $this->generateMockSeries($transcript, $existingSeries);

        // Generate realistic Bible reference
        $mockReference = $this->generateMockReference($transcript);

        // Generate realistic sermon points
        $mockPoints = $this->generateMockPoints($transcript);

        // Generate realistic summary
        $mockSummary = $this->generateMockSummary($transcript);

        $executionTime = microtime(true) - $startTime;

        Log::info('Mock sermon analysis completed', [
            'title' => $mockTitle,
            'series' => $mockSeries,
            'reference' => $mockReference,
            'points_count' => count($mockPoints),
            'execution_time_ms' => round($executionTime * 1000, 2),
            'transcript_length' => strlen($transcript),
        ]);

        return SermonAnalysis::create(
            title: $mockTitle,
            series: $mockSeries,
            reference: $mockReference,
            points: $mockPoints,
            summary: $mockSummary,
            transcript: $transcript
        );
    }

    /**
     * Generate a realistic title from transcript content
     */
    private function generateMockTitle(string $transcript): string
    {
        // Look for potential titles in common sermon patterns
        $patterns = [
            '/(?:today|this morning|this evening|tonight)\s+(?:we\'re|we are)\s+(?:looking at|exploring|examining|considering)\s+([^.!?]{10,60})/i',
            '/(?:the title|i want to speak about|let\'s talk about|we\'re going to discuss)\s+(?:is|)\s*([^.!?]{10,60})/i',
            '/([1-3]?\s*[a-z]+\s+\d+[:\-\d]*)\s*[.,:]\s*([^.!?]{10,60})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $transcript, $matches)) {
                $potential = trim($matches[count($matches) - 1]);
                if (strlen($potential) > 10 && strlen($potential) < 80) {
                    $title = $this->cleanMockTitle($potential);
                    if (! empty($title)) {
                        return $title;
                    }
                }
            }
        }

        // Fall back to meaningful phrases from the transcript
        $sentences = preg_split('/[.!?]+/', $transcript);
        if ($sentences === false) {
            $sentences = [];
        }

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) > 20 && strlen($sentence) < 100) {
                // Look for sentences with theological keywords
                if (preg_match('/\b(?:god|jesus|christ|lord|saviour|salvation|gospel|faith|hope|love|grace|mercy)\b/i', $sentence)) {
                    $title = $this->cleanMockTitle($sentence);
                    if (! empty($title)) {
                        return $title;
                    }
                }
            }
        }

        // Final fallback using first meaningful content
        $words = explode(' ', strip_tags($transcript));
        $meaningfulWords = [];
        $skipWords = ['good', 'morning', 'evening', 'welcome', 'today', 'we', 'are', 'going', 'to', 'look', 'at', 'this'];

        foreach ($words as $word) {
            $cleanWord = strtolower(trim($word, '.,!?;:'));
            if (! in_array($cleanWord, $skipWords) && strlen($cleanWord) > 2) {
                $meaningfulWords[] = $word;
                if (count($meaningfulWords) >= 8) {
                    break;
                }
            }
        }

        if (count($meaningfulWords) >= 3) {
            $title = implode(' ', array_slice($meaningfulWords, 0, 8));

            return $this->cleanMockTitle($title);
        }

        return 'Sermon on Christian faith and hope';
    }

    /**
     * Clean and validate mock title
     */
    private function cleanMockTitle(string $title): string
    {
        // Remove quotes and clean punctuation
        $title = trim($title, '"\'');
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        // Capitalise first letter and ensure sentence case
        $title = ucfirst(strtolower($title));

        // Capitalise proper nouns (God, Jesus, Christ, Bible, etc.)
        $properNouns = ['god', 'jesus', 'christ', 'lord', 'holy spirit', 'father', 'son', 'bible', 'scripture', 'christian', 'christianity'];
        foreach ($properNouns as $noun) {
            $title = preg_replace('/\b'.preg_quote($noun, '/').'\b/i', ucfirst($noun), $title) ?? $title;
        }

        // Limit to 12 words maximum
        $words = explode(' ', $title);
        if (count($words) > 12) {
            $words = array_slice($words, 0, 12);
            $title = implode(' ', $words);
        }

        return trim($title);
    }

    /**
     * Generate mock series identification
     *
     * @param  array<int, string>  $existingSeries
     */
    private function generateMockSeries(string $transcript, array $existingSeries): ?string
    {
        // Look for book studies
        $bibleBooks = [
            'genesis', 'exodus', 'leviticus', 'numbers', 'deuteronomy',
            'joshua', 'judges', 'ruth', '1 samuel', '2 samuel', '1 kings', '2 kings',
            'matthew', 'mark', 'luke', 'john', 'acts', 'romans', '1 corinthians', '2 corinthians',
            'galatians', 'ephesians', 'philippians', 'colossians', '1 thessalonians', '2 thessalonians',
            '1 timothy', '2 timothy', 'titus', 'philemon', 'hebrews', 'james', '1 peter', '2 peter',
            '1 john', '2 john', '3 john', 'jude', 'revelation',
        ];

        foreach ($bibleBooks as $book) {
            if (preg_match('/\b'.preg_quote($book, '/').'\b/i', $transcript)) {
                // Check if this book exists in existing series
                foreach ($existingSeries as $series) {
                    if (stripos($series, $book) !== false) {
                        return $series;
                    }
                }

                // Return a standardised book study name
                return ucfirst($book);
            }
        }

        // Look for seasonal series
        $seasonalPatterns = [
            '/\b(?:christmas|advent|nativity)\b/i' => 'Christmas messages',
            '/\b(?:easter|resurrection|cross|crucifixion)\b/i' => 'Easter messages',
            '/\b(?:prayer|praying)\b/i' => 'Prayer',
            '/\b(?:faith|believing|trust)\b/i' => 'Faith',
            '/\b(?:love|loving)\b/i' => 'Love',
            '/\b(?:grace|mercy)\b/i' => 'Grace and mercy',
        ];

        foreach ($seasonalPatterns as $pattern => $seriesName) {
            if (preg_match($pattern, $transcript)) {
                // Check if similar series exists
                foreach ($existingSeries as $series) {
                    if (stripos($series, $seriesName) !== false) {
                        return $series;
                    }
                }

                return $seriesName;
            }
        }

        // 30% chance of returning null (standalone sermon)
        return mt_rand(1, 100) <= 30 ? null : 'Standalone messages';
    }

    /**
     * Generate mock Bible reference
     */
    private function generateMockReference(string $transcript): ?string
    {
        // Look for Bible reference patterns in the transcript
        $patterns = [
            // Book Chapter:Verse format
            '/\b([1-3]?\s*[A-Za-z]+)\s+(\d+)[:\.](\d+)(?:[-–](\d+))?\b/',
            // Book Chapter format
            '/\b([1-3]?\s*[A-Za-z]+)\s+(?:chapter\s+)?(\d+)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $transcript, $matches)) {
                $book = trim($matches[1]);
                $chapter = $matches[2];

                // Validate it's a real Bible book
                $validBooks = [
                    'genesis', 'exodus', 'matthew', 'mark', 'luke', 'john', 'acts', 'romans',
                    '1 corinthians', '2 corinthians', 'galatians', 'ephesians', 'philippians',
                    'colossians', 'hebrews', 'james', '1 peter', '2 peter', '1 john', 'revelation',
                ];

                $bookLower = strtolower($book);
                if (in_array($bookLower, $validBooks) || preg_match('/^[1-3]\s*[a-z]+/', $bookLower)) {
                    if (isset($matches[3])) {
                        $verse = $matches[3];
                        $endVerse = isset($matches[4]) ? $matches[4] : null;

                        return $endVerse ? "{$book} {$chapter}:{$verse}-{$endVerse}" : "{$book} {$chapter}:{$verse}";
                    } else {
                        return "{$book} {$chapter}";
                    }
                }
            }
        }

        // Generate realistic reference based on content themes
        if (preg_match('/\b(?:love|loving|beloved)\b/i', $transcript)) {
            return '1 John 4:7-12';
        }
        if (preg_match('/\b(?:salvation|saved|saviour)\b/i', $transcript)) {
            return 'Ephesians 2:8-9';
        }
        if (preg_match('/\b(?:faith|believe|believing)\b/i', $transcript)) {
            return 'Hebrews 11:1-6';
        }
        if (preg_match('/\b(?:hope|eternal life)\b/i', $transcript)) {
            return '1 Peter 1:3-5';
        }
        if (preg_match('/\b(?:grace|mercy)\b/i', $transcript)) {
            return 'Romans 8:28-39';
        }

        // 40% chance of returning null (no clear primary text)
        return mt_rand(1, 100) <= 40 ? null : 'Romans 8:28';
    }

    /**
     * Generate mock sermon points
     *
     * @return array<int, string>
     */
    private function generateMockPoints(string $transcript): array
    {
        // Look for numbered points or structural elements
        $points = [];

        // Search for explicit numbering
        $patterns = [
            '/(?:first|firstly|1\.?)\s+([^.!?]{10,100})/i',
            '/(?:second|secondly|2\.?)\s+([^.!?]{10,100})/i',
            '/(?:third|thirdly|3\.?)\s+([^.!?]{10,100})/i',
            '/(?:fourth|fourthly|4\.?)\s+([^.!?]{10,100})/i',
            '/(?:finally|lastly|in conclusion)\s+([^.!?]{10,100})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $transcript, $matches)) {
                $point = trim($matches[1]);
                $point = $this->cleanMockPoint($point);
                if (! empty($point)) {
                    $points[] = $point;
                }
            }
        }

        // If we found explicit points, use those
        if (count($points) >= 2) {
            return array_slice($points, 0, 6); // Max 6 points
        }

        // Generate points based on content themes and structure
        $thematicPoints = [];

        if (preg_match('/\bsovereignty|sovereign\b/i', $transcript)) {
            $thematicPoints[] = "God's sovereignty over all circumstances";
        }
        if (preg_match('/\bgood|work.*good|good.*work\b/i', $transcript)) {
            $thematicPoints[] = 'God works all things for good';
        }
        if (preg_match('/\blove.*god|god.*love\b/i', $transcript)) {
            $thematicPoints[] = 'This promise is for those who love God';
        }
        if (preg_match('/\bpurpose|called.*purpose\b/i', $transcript)) {
            $thematicPoints[] = 'We are called according to His purpose';
        }
        if (preg_match('/\btrust|trusting\b/i', $transcript)) {
            $thematicPoints[] = 'We can trust God in difficult times';
        }
        if (preg_match('/\bfaith|faithful\b/i', $transcript)) {
            $thematicPoints[] = 'God is faithful to His promises';
        }

        if (count($thematicPoints) >= 2) {
            return array_slice($thematicPoints, 0, 5);
        }

        // Fallback to generic but meaningful points
        return [
            'God is in control of all circumstances',
            'We can trust Him even when we don\'t understand',
            'His plans are always for our ultimate good',
        ];
    }

    /**
     * Clean mock sermon point
     */
    private function cleanMockPoint(string $point): string
    {
        $point = trim($point);
        $point = preg_replace('/\s+/', ' ', $point) ?? $point;

        // Capitalise first letter, keep rest in sentence case
        $point = ucfirst(strtolower($point));

        // Capitalise proper nouns
        $properNouns = ['god', 'jesus', 'christ', 'lord', 'holy spirit', 'father', 'son', 'bible', 'christian'];
        foreach ($properNouns as $noun) {
            $point = preg_replace('/\b'.preg_quote($noun, '/').'\b/i', ucfirst($noun), $point) ?? $point;
        }

        // Limit to reasonable length (max 12 words)
        $words = explode(' ', $point);
        if (count($words) > 12) {
            $words = array_slice($words, 0, 12);
            $point = implode(' ', $words);
        }

        return $point;
    }

    /**
     * Generate mock sermon summary
     */
    private function generateMockSummary(string $transcript): ?string
    {
        // Extract key themes and create a coherent summary
        $sentences = preg_split('/[.!?]+/', $transcript);
        if ($sentences === false) {
            $sentences = [];
        }

        $keyThemes = [];

        // Look for sentences with important theological concepts
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) > 30 && strlen($sentence) < 150) {
                if (preg_match('/\b(?:god|jesus|christ|lord|faith|love|grace|salvation|hope)\b/i', $sentence)) {
                    $keyThemes[] = $sentence;
                    if (count($keyThemes) >= 3) {
                        break;
                    }
                }
            }
        }

        if (empty($keyThemes)) {
            return null;
        }

        // Create a summary by combining and reformatting key themes
        $summary = '';

        // Add opening context
        if (preg_match('/romans?\s+8/i', $transcript)) {
            $summary = 'In Romans 8:28, we see that ';
        } elseif (preg_match('/\b([1-3]?\s*[a-z]+\s+\d+)/i', $transcript, $matches)) {
            $summary = "From {$matches[1]}, we learn that ";
        } else {
            $summary = 'Scripture teaches us that ';
        }

        // Add main themes
        $mainTheme = $keyThemes[0];
        $mainTheme = preg_replace('/^(good morning|today|this morning|we\'re|we are)/i', '', $mainTheme) ?? $mainTheme;
        $mainTheme = trim($mainTheme);
        $mainTheme = lcfirst($mainTheme);

        $summary .= $mainTheme.'. ';

        // Add application
        if (count($keyThemes) > 1) {
            $application = $keyThemes[1];
            $application = preg_replace('/^(and|but|so|therefore)/i', '', $application) ?? $application;
            $application = trim($application);
            if (! empty($application)) {
                $summary .= ucfirst($application).'. ';
            }
        }

        // Add conclusion
        $conclusions = [
            'We can therefore trust Him completely in all circumstances.',
            'This gives us great comfort and hope in difficult times.',
            'As believers, we can rest secure in His sovereign care.',
            'May we live with confidence in His perfect will.',
        ];

        $summary .= $conclusions[array_rand($conclusions)];

        // Clean up and limit length
        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;
        $summary = trim($summary);

        // Limit to approximately 100 words
        $words = explode(' ', $summary);
        if (count($words) > 100) {
            $words = array_slice($words, 0, 100);
            $summary = implode(' ', $words);

            // Try to end on a complete sentence
            $lastPeriod = strrpos($summary, '.');
            if ($lastPeriod !== false && $lastPeriod > strlen($summary) * 0.8) {
                $summary = substr($summary, 0, $lastPeriod + 1);
            }
        }

        // Apply British English corrections
        $summary = $this->britishEnglishConverter->convert($summary);

        return ! empty(trim($summary)) ? $summary : null;
    }
}
