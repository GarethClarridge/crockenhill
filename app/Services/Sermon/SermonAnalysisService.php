<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Contracts\SermonAnalysisInterface;
use App\Data\SermonAnalysis;
use App\Services\OpenAIResponseLogger;
use App\Services\Processing\SermonProcessingLogger;
use App\Services\Public\SermonRepository;
use App\Support\OpenAiChatPayload;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

/**
 * @phpstan-type SermonAnalysisResult array{
 *     title: string,
 *     series: string|null,
 *     reference: string|null,
 *     points: list<string>,
 *     summary: string|null,
 *     transcript: string,
 * }
 * @phpstan-type RawAiAnalysisData array{
 *     title?: mixed,
 *     series?: mixed,
 *     reference?: mixed,
 *     points?: mixed,
 *     summary?: mixed,
 *     transcript?: mixed,
 * }
 */
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
     * @throws ErrorException|TransporterException|Exception When AI analysis fails or response is malformed
     */
    public function analyzeSermon(string $transcript, array $existingSeries = [], ?string $processingId = null): SermonAnalysis
    {
        $startTime = microtime(true);

        if ($processingId === null) {
            throw new Exception('A processing ID is required for sermon analysis.');
        }

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
     * @return SermonAnalysisResult The parsed analysis results
     *
     * @throws Exception|ErrorException|TransporterException
     */
    private function performAiAnalysis(string $transcript, array $existingSeries, string $processingId): array
    {
        $apiStartTime = microtime(true);
        $model = (string) config('media-processing.analysis.model', 'gpt-5-mini');

        try {
            return $this->runAnalysis($transcript, $existingSeries, $processingId);
        } catch (Exception|\TypeError $e) {
            $this->logAnalysisFailure($e, $processingId, $apiStartTime, $model);

            $this->logger->logProcessingStep(
                $processingId,
                'ai_analysis',
                'failed',
                [
                    'final_error' => $this->sanitizeForLog($e->getMessage()),
                ]
            );

            throw $e instanceof \TypeError
                ? new Exception('OpenAI API response malformed.', 0, $e)
                : $e;
        }
    }

    private function logAnalysisFailure(
        Exception|\TypeError $exception,
        string $processingId,
        float $apiStartTime,
        string $model,
    ): void {
        $apiTime = microtime(true) - $apiStartTime;

        if ($exception instanceof ErrorException) {
            $this->logger->logApiCall(
                $processingId,
                'OpenAI',
                'chat/completions',
                $apiTime,
                $exception->getStatusCode(),
                $exception->getMessage(),
                [
                    'model' => $model,
                    'error_type' => $exception->getErrorType(),
                ],
            );

            return;
        }

        $this->logger->logError($processingId, 'ai_analysis', $exception, [
            'api_time_ms' => round($apiTime * 1000, 2),
            'model' => $model,
            'error_type' => class_basename($exception),
        ]);
    }

    /**
     * Execute the AI analysis.
     *
     * @param  array<int, string>  $existingSeries
     * @return SermonAnalysisResult
     *
     * @throws Exception|\TypeError|ErrorException|TransporterException
     */
    private function runAnalysis(string $transcript, array $existingSeries, string $processingId): array
    {
        $apiStartTime = microtime(true);
        $model = (string) config('media-processing.analysis.model', 'gpt-5-mini');

        $this->logger->logProcessingStep(
            $processingId,
            'ai_analysis',
            'started',
            ['model' => $model]
        );

        $prompt = $this->promptBuilder->buildAnalysisPrompt($transcript, $existingSeries);
        $response = $this->executeAiRequest($prompt, $model, $processingId);

        $apiTime = microtime(true) - $apiStartTime;

        $this->logger->logApiCall(
            $processingId,
            'OpenAI',
            'chat/completions',
            $apiTime,
            200,
            null,
            ['model' => $model, 'max_completion_tokens' => 4000]
        );

        $analysisData = $this->parseAiResponse($response, $processingId);
        $validatedData = $this->validator->validateAndCleanAnalysisData($analysisData, $transcript);

        if ($this->validator->isTitleTooLong($validatedData['title'])) {
            Log::info('AI-generated title exceeds character limit; rejecting result', $this->sanitizeArrayForLog([
                'title' => $validatedData['title'],
                'length' => strlen($validatedData['title']),
                'max' => SermonAnalysis::MAX_TITLE_CHARACTERS,
            ]));

            throw new Exception(sprintf(
                'AI title exceeds %d characters (%d chars).',
                SermonAnalysis::MAX_TITLE_CHARACTERS,
                strlen($validatedData['title'])
            ));
        }

        $this->logger->logProcessingStep(
            $processingId,
            'ai_analysis',
            'completed',
            [
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
     * Parse and validate OpenAI API response.
     *
     * @return RawAiAnalysisData
     *
     * @throws Exception If the response structure is invalid or JSON parsing fails
     */
    private function parseAiResponse(CreateResponse $response, string $processingId): array
    {
        // Validate response structure
        if (empty($response->choices)) {
            Log::error('Invalid OpenAI response structure', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'response_type' => gettype($response),
            ]));

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
    private function executeAiRequest(string $prompt, string $model, string $processingId): CreateResponse
    {
        try {
            return OpenAI::chat()->create(OpenAiChatPayload::forModel([
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
                // Headroom for reasoning models, whose hidden reasoning tokens share this budget
                // with the visible JSON; classic models stop early so the ceiling costs nothing.
                'max_completion_tokens' => 4000,
            ]));
        } catch (ErrorException $e) {
            throw $e;
        } catch (\TypeError $e) {
            OpenAIResponseLogger::logResponse($processingId, 1, null, 'TypeError');
            OpenAIResponseLogger::logTransportError($processingId, 1, $e->getMessage(), null, null);

            Log::error('OpenAI API response parsing failed (malformed response)', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'model' => $model,
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
            ]));

            throw new Exception('OpenAI API response malformed.');
        } catch (Exception $e) {
            Log::error('OpenAI API call failed', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'model' => $model,
            ]));
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

        Log::info('Retrieved existing series from database', $this->sanitizeArrayForLog([
            'count' => count($series),
            'series' => $series,
        ]));

        return $series;
    }
}
