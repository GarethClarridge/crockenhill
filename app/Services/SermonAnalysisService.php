<?php

namespace App\Services;

use App\Data\SermonAnalysis;
use App\Models\Sermon;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;

class SermonAnalysisService
{
    private const MAX_RETRIES = 3;

    private const RETRY_DELAY_BASE = 2; // seconds

    private const MAX_TITLE_WORDS = 12;

    private const MIN_TRANSCRIPT_LENGTH = 100;

    protected SermonProcessingLogger $logger;

    public function __construct(MediaProcessingLogger $logger)
    {
        $this->logger = $logger;

        // Only verify OpenAI API key if using the OpenAI service
        $analysisService = config('media-processing.analysis.service', 'openai');
        if ($analysisService === 'openai' && empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new Exception('OpenAI API key not configured for analysis service');
        }
    }

    /**
     * Analyze sermon transcript using AI to extract comprehensive information
     *
     * @param  string  $transcript  The sermon transcript to analyze
     * @param  array|null  $existingSeries  Optional array of existing series names
     * @param  string  $processingId  Processing ID for logging
     * @return SermonAnalysis The analyzed sermon data
     *
     * @throws Exception When analysis fails
     */
    public function analyzeSermon(string $transcript, ?array $existingSeries = null, string $processingId = 'unknown'): SermonAnalysis
    {
        $startTime = microtime(true);

        $this->logger->logProcessingStep(
            $processingId,
            'sermon_analysis',
            'started',
            [
                'transcript_length' => strlen($transcript),
                'existing_series_count' => $existingSeries ? count($existingSeries) : 0,
            ]
        );

        // Check if using mock analysis service
        $analysisService = config('media-processing.analysis.service', 'openai');
        if ($analysisService === 'mock') {
            return $this->getMockAnalysis($transcript, $processingId, $startTime);
        }

        // Validate transcript
        if (! $this->validateTranscript($transcript)) {
            throw new Exception('Transcript validation failed - content appears invalid or too short');
        }

        // Get existing series if not provided
        if ($existingSeries === null) {
            $existingSeries = $this->getExistingSeries();
        }

        // Perform comprehensive AI analysis
        $analysisResult = $this->performAiAnalysis($transcript, $existingSeries, $processingId);

        // Create and validate SermonAnalysis object
        $sermonAnalysis = SermonAnalysis::fromAiAnalysis($analysisResult);

        $executionTime = microtime(true) - $startTime;

        $this->logger->logProcessingStep(
            $processingId,
            'sermon_analysis',
            'completed',
            [
                'execution_time_ms' => round($executionTime * 1000, 2),
                'title' => $sermonAnalysis->title,
                'series' => $sermonAnalysis->series,
                'reference' => $sermonAnalysis->reference,
                'points_count' => count($sermonAnalysis->points),
            ]
        );

        return $sermonAnalysis;
    }

    /**
     * Perform comprehensive AI analysis using OpenAI GPT API
     *
     * @param  string  $transcript  The sermon transcript
     * @param  array  $existingSeries  Array of existing series names
     * @param  string  $processingId  Processing ID for logging
     * @return array The parsed analysis results
     *
     * @throws Exception When AI analysis fails
     */
    private function performAiAnalysis(string $transcript, array $existingSeries, string $processingId = 'unknown'): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $apiStartTime = microtime(true);
            try {

                $this->logger->logProcessingStep(
                    $processingId,
                    'ai_analysis_attempt',
                    'started',
                    ['attempt' => $attempt, 'model' => config('media-processing.analysis.model', 'gpt-3.5-turbo')]
                );

                $prompt = $this->buildAnalysisPrompt($transcript, $existingSeries);
                $model = config('media-processing.analysis.model', 'gpt-3.5-turbo');

                try {
                    $response = OpenAI::chat()->create([
                        'model' => $model,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are a theological assistant specialised in analysing Christian sermon transcripts. You provide accurate, structured analysis in JSON format using British English spelling and sentence case formatting (capitalise only the first word and proper nouns, not every word). Always respond with valid JSON.',
                            ],
                            [
                                'role' => 'user',
                                'content' => $prompt,
                            ],
                        ],
                        'temperature' => 0.3, // Lower temperature for more consistent results
                        'max_tokens' => 1500,
                        // Removed response_format to avoid compatibility issues
                    ]);
                } catch (\Exception $e) {
                    Log::error('OpenAI API call failed', [
                        'processing_id' => $processingId,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'model' => $model,
                    ]);
                    throw new \Exception('OpenAI API call failed: '.$e->getMessage());
                }

                $apiTime = microtime(true) - $apiStartTime;

                $this->logger->logApiCall(
                    $processingId,
                    'OpenAI',
                    'chat/completions',
                    $apiTime,
                    200,
                    null,
                    ['attempt' => $attempt, 'model' => $model, 'max_tokens' => 1500]
                );

                // Validate response structure
                if (empty($response->choices)) {
                    Log::error('Invalid OpenAI response structure', [
                        'processing_id' => $processingId,
                        'response_type' => gettype($response),
                    ]);
                    throw new \Exception('Invalid response structure from OpenAI API');
                }

                $content = $response->choices[0]->message->content ?? '';

                if (empty($content)) {
                    throw new \Exception('Received empty response from OpenAI API');
                }

                // Parse JSON response
                $analysisData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Failed to parse JSON response: '.json_last_error_msg());
                }

                // Validate required fields
                $validatedData = $this->validateAndCleanAnalysisData($analysisData, $transcript);

                $this->logger->logProcessingStep(
                    $processingId,
                    'ai_analysis_attempt',
                    'completed',
                    [
                        'attempt' => $attempt,
                        'title' => $validatedData['title'],
                        'series' => $validatedData['series'] ?? 'None',
                        'reference' => $validatedData['reference'] ?? 'None',
                        'points_count' => count($validatedData['points']),
                        'api_time_ms' => round($apiTime * 1000, 2),
                    ]
                );

                return $validatedData;
            } catch (ErrorException $e) {
                $lastException = $e;
                $apiTime = microtime(true) - $apiStartTime;

                $this->logger->logApiCall(
                    $processingId,
                    'OpenAI',
                    'chat/completions',
                    $apiTime,
                    $e->getCode(),
                    $e->getMessage(),
                    ['attempt' => $attempt]
                );

                // Don't retry on certain errors
                if ($this->isNonRetryableError($e)) {
                    break;
                }
            } catch (TransporterException $e) {
                $lastException = $e;
                $apiTime = microtime(true) - $apiStartTime;

                $this->logger->logApiCall(
                    $processingId,
                    'OpenAI',
                    'chat/completions',
                    $apiTime,
                    0,
                    $e->getMessage(),
                    ['attempt' => $attempt, 'error_type' => 'network']
                );
            } catch (Exception $e) {
                $lastException = $e;

                $this->logger->logError(
                    $processingId,
                    'ai_analysis_attempt',
                    $e,
                    ['attempt' => $attempt]
                );
            }

            // Wait before retry with exponential backoff
            if ($attempt < self::MAX_RETRIES) {
                $delay = self::RETRY_DELAY_BASE ** $attempt;
                $this->logger->logProcessingStep(
                    $processingId,
                    'retry_delay',
                    'waiting',
                    ['delay_seconds' => $delay, 'next_attempt' => $attempt + 1]
                );
                sleep($delay);
            }
        }

        // All attempts failed - return fallback data
        $this->logger->logProcessingStep(
            $processingId,
            'ai_analysis',
            'failed',
            [
                'total_attempts' => $attempt,
                'final_error' => $lastException->getMessage(),
                'using_fallback' => true,
            ]
        );

        return $this->getFallbackAnalysisData($transcript);
    }

    /**
     * Build comprehensive analysis prompt for OpenAI
     *
     * @param  string  $transcript  The sermon transcript
     * @param  array  $existingSeries  Array of existing series names
     * @return string The formatted prompt
     */
    private function buildAnalysisPrompt(string $transcript, array $existingSeries): string
    {
        $seriesList = empty($existingSeries) ? 'None available' : implode(', ', $existingSeries);

        return <<<PROMPT
Analyze this Christian sermon transcript and extract the following information. Return your response as a JSON object with the specified structure.

EXISTING SERMON SERIES (match one if applicable):
{$seriesList}

TRANSCRIPT:
{$transcript}

Please provide a JSON response with this exact structure:
{
    "title": "A descriptive sermon title in sentence case (maximum 12 words)",
    "series": "Name of matching existing series or null if no match",
    "reference": "Primary Bible passage being preached (e.g., 'John 3:16-21')",
    "points": ["Main point 1 in sentence case", "Main point 2 in sentence case", "Main point 3 in sentence case"],
    "summary": "A concise summary of the sermon in under 100 words using British English"
}

ANALYSIS GUIDELINES:

1. TITLE: Create a clear, engaging title that captures the sermon's main theme. Rules:
   - Maximum 12 words
   - Focus on the central message or key Bible passage
   - Use language from the transcript where possible
   - Use sentence case (capitalise only the first word and proper nouns, not every word)

2. SERIES: Only match to an existing series if the content clearly belongs to that series. Look for:
   - Book studies (e.g., "1 John", "Romans", "Genesis")
   - Thematic series (e.g., "Christmas messages", "Easter", "Prayer")
   - If uncertain or no clear match, return null

3. REFERENCE: Identify the PRIMARY Bible passage being expounded. This should be:
   - The main text the preacher is working through
   - Not just verses quoted in passing
   - Format as "Book Chapter:Verse-Verse" (e.g., "Romans 8:28-39")
   - If no clear primary passage, return null

4. POINTS: Extract 2-7 main sermon points/headings that structure the message:
   - Focus on the preacher's main divisions or arguments
   - Use the preacher's own words where possible
   - Use sentence case - don't capitalise every word
   - If creating points yourself, use clear, concise British English, matching the preacher's tone of voice
   - Stick to below 12 words per point
   - If no clear structure is evident, create logical divisions based on content flow
   - Use sub-points if they help to structure the message, but don't overcomplicate it

5. SUMMARY: Create a concise summary of the sermon in under 100 words that:
   - Captures the main message and key themes
   - Stays faithful to the transcript content
   - Never introduces new information or ideas that are not in the transcript
   - Uses clear, accessible, persuasive British English as would be expected from a sermon in a British conservative evangelical church
   - Matches the tone of the sermon
   - Uses "we" and "us" rather than "Christians" or "believers"
   - Uses active language, not passive
   - Never mentions "sermon" or "message" in the summary. Instead, talk as if you're the preacher summarising their own sermon

Respond only with the JSON object, no additional text.
PROMPT;
    }

    /**
     * Validate and clean the AI analysis data
     *
     * @param  array  $analysisData  Raw analysis data from AI
     * @param  string  $originalTranscript  Original transcript for fallback
     * @return array Validated and cleaned analysis data
     */
    private function validateAndCleanAnalysisData(array $analysisData, string $originalTranscript): array
    {
        // Validate and clean title
        $title = $this->validateAndCleanTitle($analysisData['title'] ?? '');

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
            $converter = app(BritishEnglishConverter::class);
            foreach ($analysisData['points'] as $point) {
                if (is_string($point) && ! empty(trim($point))) {
                    $cleanPoint = $converter->convert(trim($point));
                    $points[] = $cleanPoint;
                }
            }
        }

        // Ensure we have at least some points
        if (empty($points)) {
            $points = ['Main Message']; // Fallback point
        }

        // Validate and clean summary
        $summary = $this->validateAndCleanSummary($analysisData['summary'] ?? '');

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
    private function validateAndCleanTitle(string $title): string
    {
        $title = trim($title);

        if (empty($title)) {
            return 'Untitled sermon';
        }

        // Remove quotes if present
        $title = trim($title, '"\'');

        // Apply British English spelling corrections
        $converter = app(BritishEnglishConverter::class);
        $title = $converter->convert($title);

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
     * Validate Bible reference format
     *
     * @param  string  $reference  Raw Bible reference
     * @return string|null Validated reference or null if invalid
     */
    private function validateBibleReference(string $reference): ?string
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
    private function validateAndCleanSummary(string $summary): ?string
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
        $converter = app(BritishEnglishConverter::class);
        $summary = $converter->convert($summary);

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
     * Get existing sermon series from database
     *
     * @return array Array of unique series names
     */
    private function getExistingSeries(): array
    {
        try {
            $series = Sermon::whereNotNull('series')
                ->where('series', '!=', '')
                ->distinct()
                ->pluck('series')
                ->filter()
                ->values()
                ->toArray();

            Log::info('Retrieved existing series from database', [
                'count' => count($series),
                'series' => $series,
            ]);

            return $series;
        } catch (Exception $e) {
            Log::warning('Failed to retrieve existing series from database', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Validate transcript content
     *
     * @param  string  $transcript  The transcript to validate
     * @return bool True if transcript is valid
     */
    private function validateTranscript(string $transcript): bool
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
     * Check if an error should not be retried
     *
     * @param  ErrorException  $exception  The OpenAI API exception
     * @return bool True if error should not be retried
     */
    private function isNonRetryableError(ErrorException $exception): bool
    {
        $nonRetryableCodes = [
            400, // Bad Request - invalid request format
            401, // Unauthorized - invalid API key
            403, // Forbidden - insufficient permissions
        ];

        return in_array($exception->getStatusCode(), $nonRetryableCodes);
    }

    /**
     * Get fallback analysis data when AI processing fails
     *
     * @param  string  $transcript  Original transcript
     * @return array Fallback analysis data
     */
    private function getFallbackAnalysisData(string $transcript): array
    {
        Log::info('Generating fallback analysis data');

        // Generate basic title from transcript
        $title = $this->generateFallbackTitle($transcript);

        return [
            'title' => $title,
            'series' => null,
            'reference' => null,
            'points' => ['Main Message'], // Simple fallback
            'summary' => null, // No summary available when AI fails
            'transcript' => $transcript,
        ];
    }

    /**
     * Generate a basic title from transcript when AI fails
     *
     * @param  string  $transcript  The sermon transcript
     * @return string Generated title
     */
    private function generateFallbackTitle(string $transcript): string
    {
        // Try to extract a meaningful phrase from the beginning
        $words = explode(' ', trim($transcript));

        // Skip common sermon openings
        $skipWords = ['good', 'morning', 'evening', 'welcome', 'today', 'we', 'are', 'going', 'to', 'look', 'at'];
        $meaningfulWords = [];

        foreach ($words as $word) {
            $cleanWord = strtolower(trim($word, '.,!?;:'));
            if (! in_array($cleanWord, $skipWords) && strlen($cleanWord) > 2) {
                $meaningfulWords[] = $word;
                if (count($meaningfulWords) >= 4) {
                    break;
                }
            }
        }

        if (count($meaningfulWords) >= 2) {
            $title = implode(' ', array_slice($meaningfulWords, 0, 6));

            return $this->validateAndCleanTitle($title);
        }

        // Final fallback
        return 'Sermon - '.date('F j, Y');
    }

    /**
     * Generate sermon title from transcript (public method for individual use)
     *
     * @param  string  $transcript  The sermon transcript
     * @return string Generated title
     *
     * @throws Exception When title generation fails
     */
    public function generateTitle(string $transcript): string
    {
        if (! $this->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for title generation');
        }

        try {
            $analysis = $this->analyzeSermon($transcript);

            return $analysis->title;
        } catch (Exception $e) {
            Log::warning('Failed to generate title via full analysis, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->generateFallbackTitle($transcript);
        }
    }

    /**
     * Identify series from transcript and existing series list
     *
     * @param  string  $transcript  The sermon transcript
     * @param  array  $existingSeries  Array of existing series names
     * @return string|null Matched series name or null
     *
     * @throws Exception When series identification fails
     */
    public function identifySeries(string $transcript, array $existingSeries): ?string
    {
        if (! $this->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for series identification');
        }

        try {
            $analysis = $this->analyzeSermon($transcript, $existingSeries);

            return $analysis->series;
        } catch (Exception $e) {
            Log::warning('Failed to identify series via full analysis', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract main Bible passage from transcript
     *
     * @param  string  $transcript  The sermon transcript
     * @return string|null Identified Bible passage or null
     *
     * @throws Exception When passage extraction fails
     */
    public function extractBiblePassage(string $transcript): ?string
    {
        if (! $this->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for Bible passage extraction');
        }

        try {
            $analysis = $this->analyzeSermon($transcript);

            return $analysis->reference;
        } catch (Exception $e) {
            Log::warning('Failed to extract Bible passage via full analysis', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract sermon points/headings from transcript
     *
     * @param  string  $transcript  The sermon transcript
     * @return array Array of sermon points
     *
     * @throws Exception When point extraction fails
     */
    public function extractSermonPoints(string $transcript): array
    {
        if (! $this->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for sermon points extraction');
        }

        try {
            $analysis = $this->analyzeSermon($transcript);

            return $analysis->points;
        } catch (Exception $e) {
            Log::warning('Failed to extract sermon points via full analysis, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return ['Main Message'];
        }
    }

    /**
     * Generate comprehensive mock analysis for development/testing
     */
    private function getMockAnalysis(string $transcript, string $processingId, float $startTime): SermonAnalysis
    {
        // Simulate processing time
        usleep(200000); // 200ms delay to simulate API call

        // Generate realistic title
        $mockTitle = $this->generateMockTitle($transcript);

        // Generate realistic series identification
        $mockSeries = $this->generateMockSeries($transcript);

        // Generate realistic Bible reference
        $mockReference = $this->generateMockReference($transcript);

        // Generate realistic sermon points
        $mockPoints = $this->generateMockPoints($transcript);

        // Generate realistic summary
        $mockSummary = $this->generateMockSummary($transcript);

        $executionTime = microtime(true) - $startTime;

        $this->logger->logProcessingStep(
            $processingId,
            'sermon_analysis',
            'completed',
            [
                'service' => 'mock',
                'title' => $mockTitle,
                'series' => $mockSeries ?? 'None',
                'reference' => $mockReference ?? 'None',
                'points_count' => count($mockPoints),
                'execution_time_ms' => round($executionTime * 1000, 2),
            ]
        );

        Log::info('Mock sermon analysis completed', [
            'processing_id' => $processingId,
            'title' => $mockTitle,
            'series' => $mockSeries,
            'reference' => $mockReference,
            'points_count' => count($mockPoints),
            'execution_time_ms' => round($executionTime * 1000, 2),
            'transcript_length' => strlen($transcript),
            'mock' => true,
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
            // "Today we're looking at..." or "This morning we're exploring..."
            '/(?:today|this morning|this evening|tonight)\s+(?:we\'re|we are)\s+(?:looking at|exploring|examining|considering)\s+([^.!?]{10,60})/i',
            // "The title of this sermon is..." or "I want to speak about..."
            '/(?:the title|i want to speak about|let\'s talk about|we\'re going to discuss)\s+(?:is|)\s*([^.!?]{10,60})/i',
            // Bible reference followed by descriptive text
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
        $title = preg_replace('/\s+/', ' ', $title);

        // Capitalise first letter and ensure sentence case
        $title = ucfirst(strtolower($title));

        // Capitalise proper nouns (God, Jesus, Christ, Bible, etc.)
        $properNouns = ['god', 'jesus', 'christ', 'lord', 'holy spirit', 'father', 'son', 'bible', 'scripture', 'christian', 'christianity'];
        foreach ($properNouns as $noun) {
            $title = preg_replace('/\b'.preg_quote($noun, '/').'\b/i', ucfirst($noun), $title);
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
     */
    private function generateMockSeries(string $transcript): ?string
    {
        // Get existing series for realistic matching
        $existingSeries = $this->getExistingSeries();

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
        $point = preg_replace('/\s+/', ' ', $point);

        // Capitalise first letter, keep rest in sentence case
        $point = ucfirst(strtolower($point));

        // Capitalise proper nouns
        $properNouns = ['god', 'jesus', 'christ', 'lord', 'holy spirit', 'father', 'son', 'bible', 'christian'];
        foreach ($properNouns as $noun) {
            $point = preg_replace('/\b'.preg_quote($noun, '/').'\b/i', ucfirst($noun), $point);
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
        $mainTheme = preg_replace('/^(good morning|today|this morning|we\'re|we are)/i', '', $mainTheme);
        $mainTheme = trim($mainTheme);
        $mainTheme = lcfirst($mainTheme);

        $summary .= $mainTheme.'. ';

        // Add application
        if (count($keyThemes) > 1) {
            $application = $keyThemes[1];
            $application = preg_replace('/^(and|but|so|therefore)/i', '', $application);
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
        $summary = preg_replace('/\s+/', ' ', $summary);
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
        $converter = app(BritishEnglishConverter::class);
        $summary = $converter->convert($summary);

        return ! empty(trim($summary)) ? $summary : null;
    }
}
