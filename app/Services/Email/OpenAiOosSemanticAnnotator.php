<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\OosSemanticAnnotator;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Exceptions\OosSemanticResponseTruncatedException;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiTransientFailure;
use App\Support\OpenAiUsageLogger;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;
use Throwable;

class OpenAiOosSemanticAnnotator implements OosSemanticAnnotator
{
    public function __construct(
        private readonly ClientContract $client,
        private readonly OosSemanticAnnotationSchema $schema,
        private readonly OosSemanticAnnotationDecoder $decoder,
        private readonly OosSemanticAnnotationPrompt $prompt,
    ) {}

    public function annotate(OosEmailSourceDocument $source): OosSemanticAnnotationResult
    {
        if (empty(config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for semantic OoS email annotation.');
        }

        $attempts = max(1, (int) config('service-tracking.email_parsing.semantic.transport_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->request($source, $attempt);
            } catch (Throwable $exception) {
                if ($attempt >= $attempts || ! OpenAiTransientFailure::isTransient($exception)) {
                    throw $exception;
                }

                usleep(OpenAiTransientFailure::delayMs($exception, $attempt) * 1000);
            }
        }

        throw new RuntimeException('Semantic OoS email annotation exhausted its transport attempts.');
    }

    private function request(OosEmailSourceDocument $source, int $attempt): OosSemanticAnnotationResult
    {
        $model = (string) config('service-tracking.email_parsing.semantic.model', 'gpt-5.6-terra');
        $effort = (string) config('service-tracking.email_parsing.semantic.reasoning_effort', 'low');
        $startedAt = microtime(true);
        $response = $this->client->chat()->create(OpenAiChatPayload::forModel([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->prompt->text()],
                ['role' => 'user', 'content' => $this->userPrompt($source)],
            ],
            'service_tier' => config('openai.service_tier'),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'oos_semantic_annotations',
                    'strict' => true,
                    'schema' => $this->schema->build($source),
                ],
            ],
            'temperature' => 0.1,
            'max_completion_tokens' => (int) config('service-tracking.email_parsing.semantic.max_completion_tokens', 12000),
        ], reasoningEffort: $effort));

        OpenAiUsageLogger::log($response, 'oos_semantic_annotation', $model, requestedReasoningEffort: $effort);

        return $this->decode($source, $response, $attempt, $startedAt);
    }

    private function userPrompt(OosEmailSourceDocument $source): string
    {
        return 'Subject: '.($source->subject ?? '')."\n"
            .'Received date: '.($source->receivedDate ?? '')."\n"
            .'Calendar context: '.json_encode($source->calendarContext(), JSON_UNESCAPED_SLASHES)."\n\n"
            ."Source lines:\n{$source->semanticPromptBody()}";
    }

    private function decode(OosEmailSourceDocument $source, CreateResponse $response, int $attempt, float $startedAt): OosSemanticAnnotationResult
    {
        $content = $response->choices[0]->message->content ?? null;

        if (($response->choices[0]->finishReason ?? null) === 'length') {
            throw new OosSemanticResponseTruncatedException('Semantic OoS email annotation response was truncated.');
        }

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Semantic OoS email annotation response was empty.');
        }

        $payload = json_decode($content, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Semantic OoS email annotation response was not valid JSON.');
        }

        $usage = $response->usage;

        return $this->decoder->decode($source, $payload, [
            'attempt' => $attempt,
            'returned_model' => $response->model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'usage' => $usage === null ? null : [
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'total_tokens' => $usage->totalTokens,
            ],
        ]);
    }
}
