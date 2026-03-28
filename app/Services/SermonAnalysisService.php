<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Repositories\SermonRepository;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;

class SermonAnalysisService implements SermonAnalysisInterface
{
    private const DEFAULT_MAX_RETRIES = 3;

    private const DEFAULT_RETRY_DELAY_BASE = 2; // seconds

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
     * Analyze sermon transcript using AI to extract comprehensive information
     *
     * @param  string  $transcript  The sermon transcript to analyze
     * @param  array<int, string>  $existingSeries  Optional array of existing series names
     * @return SermonAnalysis The analyzed sermon data
     *
     * @throws Exception When analysis fails
     */
    public function analyzeSermon(string $transcript, array $existingSeries = []): SermonAnalysis
    {
        $startTime = microtime(true);
        $processingId = 'unknown';

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
     * Resolve retry count from configuration.
     */
    private function maxRetries(): int
    {
        return max(1, (int) config('media-processing.analysis.max_retries', self::DEFAULT_MAX_RETRIES));
    }

    /**
     * Resolve retry delay base (seconds) from configuration.
     */
    private function retryDelayBase(): int
    {
        return max(0, (int) config('media-processing.analysis.retry_delay_base', self::DEFAULT_RETRY_DELAY_BASE));
    }

    /**
     * Perform comprehensive AI analysis using OpenAI GPT API
     *
     * @param  string  $transcript  The sermon transcript
     * @param  array<int, string>  $existingSeries  Array of existing series names
     * @param  string  $processingId  Processing ID for logging
     * @return array<string, mixed> The parsed analysis results
     *
     * @throws Exception When AI analysis fails
     */
    private function performAiAnalysis(string $transcript, array $existingSeries, string $processingId = 'unknown'): array
    {
        $maxRetries = $this->maxRetries();
        $retryDelayBase = $this->retryDelayBase();
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            $attempt++;
            $apiStartTime = microtime(true);
            try {

                $model = (string) config('media-processing.analysis.model', 'gpt-4o-mini');

                $this->logger->logProcessingStep(
                    $processingId,
                    'ai_analysis_attempt',
                    'started',
                    ['attempt' => $attempt, 'model' => $model]
                );

                $prompt = $this->promptBuilder->buildAnalysisPrompt($transcript, $existingSeries);

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
                        'temperature' => 0.3,
                        'max_completion_tokens' => 1500,
                    ]);
                } catch (\TypeError $e) {
                    // Handle malformed API response (e.g., non-JSON response body)
                    Log::error('OpenAI API response parsing failed (malformed response)', [
                        'processing_id' => $processingId,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'model' => $model,
                        'exception_file' => $e->getFile(),
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

                    throw new \Exception('OpenAI API response malformed: '.$e->getMessage());
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
                    ['attempt' => $attempt, 'model' => $model, 'max_completion_tokens' => 1500]
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
                $validatedData = $this->validator->validateAndCleanAnalysisData($analysisData, $transcript);

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

                // Extract detailed error info from OpenAI error response
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();

                Log::error('OpenAI API ErrorException details', [
                    'processing_id' => $processingId,
                    'attempt' => $attempt,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
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
            } catch (\TypeError $e) {
                $lastException = $e;
                $apiTime = microtime(true) - $apiStartTime;

                // Log comprehensive response parsing failure details
                OpenAIResponseLogger::logResponse($processingId, $attempt, null, 'TypeError');
                OpenAIResponseLogger::logTransportError(
                    $processingId,
                    $attempt,
                    $e->getMessage(),
                    null,
                    null
                );

                $this->logger->logError(
                    $processingId,
                    'ai_analysis_attempt',
                    $e,
                    [
                        'attempt' => $attempt,
                        'error_type' => 'response_parsing',
                        'api_time_ms' => round($apiTime * 1000, 2),
                    ]
                );
                // Retry on malformed responses
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
            if ($attempt < $maxRetries) {
                $delay = $retryDelayBase > 0 ? $retryDelayBase ** $attempt : 0;
                $this->logger->logProcessingStep(
                    $processingId,
                    'retry_delay',
                    'waiting',
                    ['delay_seconds' => $delay, 'next_attempt' => $attempt + 1]
                );

                if ($delay > 0) {
                    sleep($delay);
                }
            }
        }

        // All attempts failed - return fallback data
        $this->logger->logProcessingStep(
            $processingId,
            'ai_analysis',
            'failed',
            [
                'total_attempts' => $attempt,
                'final_error' => $lastException?->getMessage() ?? 'AI analysis failed after all retry attempts.',
                'using_fallback' => true,
            ]
        );

        return $this->getFallbackAnalysisData($transcript);
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
            'series' => $series,
        ]);

        return $series;
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
     * @return array<string, mixed> Fallback analysis data
     */
    private function getFallbackAnalysisData(string $transcript): array
    {
        Log::info('Generating fallback analysis data');

        // Generate basic title from transcript
        $title = $this->promptBuilder->generateFallbackTitle($transcript);

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
                'error' => $e->getMessage(),
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
        if (! $this->validator->validateTranscript($transcript)) {
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
                'error' => $e->getMessage(),
            ]);

            return ['Main Message'];
        }
    }
}
