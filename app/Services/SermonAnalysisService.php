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

    public function __construct(SermonProcessingLogger $logger)
    {
        $this->logger = $logger;

        // Verify OpenAI API key is configured
        if (empty(config('sermon-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
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
                    ['attempt' => $attempt, 'model' => config('sermon-processing.analysis.model', 'gpt-3.5-turbo')]
                );

                $prompt = $this->buildAnalysisPrompt($transcript, $existingSeries);
                $model = config('sermon-processing.analysis.model', 'gpt-3.5-turbo');

                $response = OpenAI::chat()->create([
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a theological assistant specialised in analysing Christian sermon transcripts. You provide accurate, structured analysis in JSON format using British English spelling and sentence case formatting (capitalise only the first word and proper nouns, not every word).',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.3, // Lower temperature for more consistent results
                    'max_tokens' => 1500,
                    'response_format' => ['type' => 'json_object'],
                ]);

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

                $content = $response->choices[0]->message->content ?? '';

                if (empty($content)) {
                    throw new Exception('Received empty response from OpenAI API');
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
}
