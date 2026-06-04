<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Repositories\SermonRepository;
use App\Services\Processing\SermonProcessingLogger;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

class SermonAnalysisService implements SermonAnalysisInterface
{
    use SanitizesLogData;

    public function __construct(
        private readonly SermonProcessingLogger $logger,
        private readonly SermonRepository $sermonRepository,
        private readonly SermonAnalysisValidator $validator,
        private readonly SermonAnalysisPromptBuilder $promptBuilder,
    ) {
        // Only verify OpenAI API key if using the OpenAI service
        $analysisService = config('media-processing.analysis.service', 'openai');
        if ($analysisService === 'openai' && empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new Exception('OpenAI API key not configured for analysis service');
        }
    }

    /**
     * Analyze sermon transcript using AI to extract comprehensive information.
     *
     * Coordinates the full analysis pipeline: validation, optional series retrieval,
     * AI interaction, and data normalization into a SermonAnalysis DTO.
     *
     * @param  string  $transcript  The sermon transcript to analyze
     * @param  array<int, string>  $existingSeries  Optional array of existing series names for context
     * @param  string|null  $processingId  Processing ID for log correlation
     * @return SermonAnalysis The analyzed sermon data
     *
     * @throws Exception When validation fails, AI analysis fails, or response is malformed
     */
    public function analyzeSermon(string $transcript, array $existingSeries = [], ?string $processingId = null): SermonAnalysis
    {
        $startTime = microtime(true);
        $processingId ??= 'unknown';

        $this->logger->logProcessingStep(
            $processingId,
            'sermon_analysis',
            'started',
            [
                'transcript_length' => strlen($transcript),
                'existing_series_count' => count($existingSeries),
            ]
        );

        // Validate transcript
        if (! $this->validator->validateTranscript($transcript)) {
            throw new Exception('Transcript validation failed - content appears invalid or too short');
        }

        // Get existing series if not provided
        if (empty($existingSeries)) {
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
     * Perform comprehensive AI analysis using OpenAI GPT API.
     *
     * @param  string  $transcript  The sermon transcript
     * @param  array<int, string>  $existingSeries  Array of existing series names
     * @param  string  $processingId  Processing ID for logging
     * @return array{
     *     title: string,
     *     series: string|null,
     *     reference: string|null,
     *     points: list<string>,
     *     summary: string|null,
     *     transcript: string,
     * } The parsed analysis results
     *
     * @throws Exception|ErrorException|TransporterException
     */
    private function performAiAnalysis(string $transcript, array $existingSeries, string $processingId): array
    {
        $apiStartTime = microtime(true);

        try {
            return $this->runAnalysisAttempt($transcript, $existingSeries, $processingId, 1, $apiStartTime);
        } catch (Exception|\TypeError $e) {
            $this->handleAnalysisAttemptError($e, $processingId, 1, $apiStartTime);

            $this->logger->logProcessingStep(
                $processingId,
                'ai_analysis',
                'failed',
                [
                    'total_attempts' => 1,
                    'final_error' => $this->sanitizeForLog($e->getMessage()),
                ]
            );

            throw $e instanceof \TypeError
                ? new Exception('OpenAI API response malformed.', 0, $e)
                : $e;
        }
    }

    /**
     * Execute a single AI analysis attempt.
     *
     * @param  array<int, string>  $existingSeries
     * @return array{
     *     title: string,
     *     series: string|null,
     *     reference: string|null,
     *     points: list<string>,
     *     summary: string|null,
     *     transcript: string,
     * }
     *
     * @throws Exception|\TypeError|ErrorException|TransporterException
     */
    private function runAnalysisAttempt(string $transcript, array $existingSeries, string $processingId, int $attempt, float $apiStartTime): array
    {
        $model = (string) config('media-processing.analysis.model', 'gpt-4o-mini');

        $this->logger->logProcessingStep(
            $processingId,
            'ai_analysis_attempt',
            'started',
            ['attempt' => $attempt, 'model' => $model]
        );

        $prompt = $this->promptBuilder->buildAnalysisPrompt($transcript, $existingSeries);
        $response = $this->executeAiRequest($prompt, $model, $processingId, $attempt);

        $apiTime = microtime(true) - $apiStartTime;

        $this->logger->logApiCall(
            $processingId,
            'OpenAI',
            'chat/completions',
            $apiTime,
            200,
            null,
            ['attempt' => $attempt, 'model' => $model, 'max_completion_tokens' => 1500]
        );

        $analysisData = $this->parseAiResponse($response, $processingId);
        $validatedData = $this->validator->validateAndCleanAnalysisData($analysisData, $transcript);

        if ($this->validator->isTitleTooLong($validatedData['title'])) {
            Log::info('AI-generated title exceeds character limit, retrying', [
                'title' => $this->sanitizeForLog($validatedData['title']),
                'length' => strlen($validatedData['title']),
                'max' => SermonAnalysisValidator::MAX_TITLE_CHARACTERS,
                'attempt' => $attempt,
            ]);

            throw new Exception(sprintf(
                'AI title exceeds %d characters (%d chars).',
                SermonAnalysisValidator::MAX_TITLE_CHARACTERS,
                strlen($validatedData['title'])
            ));
        }

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
    }

    /**
     * Handle and log errors from an AI analysis attempt.
     */
    private function handleAnalysisAttemptError(Exception|\TypeError $e, string $processingId, int $attempt, float $apiStartTime): void
    {
        $apiTime = microtime(true) - $apiStartTime;

        if ($e instanceof ErrorException) {
            Log::error('OpenAI API ErrorException details', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'attempt' => $attempt,
                'error_code' => $e->getCode(),
                'error_message' => $this->sanitizeForLog($e->getMessage()),
                'api_time_ms' => round($apiTime * 1000, 2),
                'exception_class' => get_class($e),
                'status_code' => $e->getStatusCode(),
            ]);

            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'chat/completions',
                $apiTime,
                $e->getStatusCode(),
                $e->getMessage(),
                ['attempt' => $attempt]
            );

            return;
        }

        if ($e instanceof TransporterException) {
            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'chat/completions',
                $apiTime,
                0,
                $e->getMessage(),
                ['attempt' => $attempt, 'error_type' => 'network']
            );

            return;
        }

        if ($e instanceof \TypeError) {
            OpenAIResponseLogger::logResponse($processingId, $attempt, null, 'TypeError');
            OpenAIResponseLogger::logTransportError($processingId, $attempt, $e->getMessage(), null, null);

            $this->logger->logError($processingId, 'ai_analysis_attempt', $e, [
                'attempt' => $attempt,
                'error_type' => 'response_parsing',
                'api_time_ms' => round($apiTime * 1000, 2),
            ]);

            return;
        }

        $this->logger->logError($processingId, 'ai_analysis_attempt', $e, ['attempt' => $attempt]);
    }

    /**
     * Parse and validate OpenAI API response.
     *
     * @return array{
     *     title?: string,
     *     series?: string|null,
     *     reference?: string|null,
     *     points?: array<int, string>,
     *     summary?: string|null,
     *     transcript?: string,
     * }
     *
     * @throws Exception If the response structure is invalid or JSON parsing fails
     */
    private function parseAiResponse(CreateResponse $response, string $processingId): array
    {
        // Validate response structure
        if (empty($response->choices)) {
            Log::error('Invalid OpenAI response structure', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'response_type' => gettype($response),
            ]);

            throw new Exception('Invalid response structure from OpenAI API');
        }

        $content = $response->choices[0]->message->content ?? '';

        if (empty($content)) {
            throw new Exception('Received empty response from OpenAI API');
        }

        // Parse JSON response
        $analysisData = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON response: '.json_last_error_msg());
        }

        return $analysisData;
    }

    /**
     * Execute AI analysis request via OpenAI SDK.
     *
     * @throws Exception|ErrorException|\TypeError
     */
    private function executeAiRequest(string $prompt, string $model, string $processingId, int $attempt): CreateResponse
    {
        try {
            return OpenAI::chat()->create([
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
                'temperature' => 0.3,
                'max_completion_tokens' => 1500,
            ]);
        } catch (ErrorException $e) {
            throw $e;
        } catch (\TypeError $e) {
            // Handle malformed API response (e.g., non-JSON response body)
            Log::error('OpenAI API response parsing failed (malformed response)', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'attempt' => $attempt,
                'error' => $this->sanitizeForLog($e->getMessage()),
                'model' => $this->sanitizeForLog($model),
                'exception_file' => $this->sanitizeForLog($e->getFile()),
                'exception_line' => $e->getLine(),
            ]);

            // Log details about response body (from stack context)
            $trace = $e->getTrace();
            foreach ($trace as $frame) {
                if (str_contains($frame['file'] ?? '', 'Chat.php') && ($frame['line'] ?? 0) === 35) {
                    // This is where CreateResponse::from() is called
                    // The first argument would have been $response->data()
                    Log::warning('OpenAI SDK response type mismatch detected at Chat.php:35 - response body is string not array');
                    break;
                }
            }

            throw new Exception('OpenAI API response malformed.');
        } catch (Exception $e) {
            Log::error('OpenAI API call failed', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'attempt' => $attempt,
                'error' => $this->sanitizeForLog($e->getMessage()),
                'model' => $this->sanitizeForLog($model),
            ]);
            throw new Exception('OpenAI API call failed.');
        }
    }

    /**
     * Get existing sermon series from database
     *
     * @return array<int, string> Array of unique series names
     */
    private function getExistingSeries(): array
    {
        $series = $this->sermonRepository->getExistingSeries();

        Log::info('Retrieved existing series from database', [
            'count' => count($series),
            'series' => array_map(fn (string $s) => $this->sanitizeForLog($s), $series),
        ]);

        return $series;
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
        if (! $this->validator->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for title generation');
        }

        try {
            $analysis = $this->analyzeSermon($transcript);

            return $analysis->title;
        } catch (Exception $e) {
            Log::warning('Failed to generate title via full analysis, using fallback', [
                'error' => $this->sanitizeForLog($e->getMessage()),
            ]);

            return $this->promptBuilder->generateFallbackTitle($transcript);
        }
    }

    /**
     * Identify series from transcript and existing series list
     *
     * @param  string  $transcript  The sermon transcript
     * @param  array<int, string>  $existingSeries  Array of existing series names
     * @return string|null Matched series name or null
     *
     * @throws Exception When series identification fails
     */
    public function identifySeries(string $transcript, array $existingSeries): ?string
    {
        if (! $this->validator->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for series identification');
        }

        try {
            $analysis = $this->analyzeSermon($transcript, $existingSeries);

            return $analysis->series;
        } catch (Exception $e) {
            Log::warning('Failed to identify series via full analysis', [
                'error' => $this->sanitizeForLog($e->getMessage()),
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
        if (! $this->validator->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for Bible passage extraction');
        }

        try {
            $analysis = $this->analyzeSermon($transcript);

            return $analysis->reference;
        } catch (Exception $e) {
            Log::warning('Failed to extract Bible passage via full analysis', [
                'error' => $this->sanitizeForLog($e->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * Extract sermon points/headings from transcript
     *
     * @param  string  $transcript  The sermon transcript
     * @return array<int, string> Array of sermon points
     *
     * @throws Exception When point extraction fails
     */
    public function extractSermonPoints(string $transcript): array
    {
        if (! $this->validator->validateTranscript($transcript)) {
            throw new Exception('Invalid transcript for sermon points extraction');
        }

        try {
            $analysis = $this->analyzeSermon($transcript);

            return $analysis->points;
        } catch (Exception $e) {
            Log::warning('Failed to extract sermon points via full analysis, using fallback', [
                'error' => $this->sanitizeForLog($e->getMessage()),
            ]);

            return ['Main Message'];
        }
    }
}
