<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\OosSemanticRepairer;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationPatch;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticFinding;
use App\Exceptions\OosSemanticResponseTruncatedException;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiTransientFailure;
use App\Support\OpenAiUsageLogger;
use OpenAI\Contracts\ClientContract;
use RuntimeException;
use Throwable;

class OpenAiOosSemanticRepairer implements OosSemanticRepairer
{
    public function __construct(
        private readonly ClientContract $client,
        private readonly OosSemanticAnnotationSchema $schema,
        private readonly OosSemanticAnnotationDecoder $decoder,
    ) {}

    public function repair(
        OosEmailSourceDocument $source,
        OosSemanticAnnotationResult $annotations,
        array $findings,
    ): OosSemanticAnnotationPatch {
        $lineIds = $this->repairLineIds($findings);

        if ($lineIds === []) {
            throw new RuntimeException('Semantic annotation findings do not permit a targeted repair.');
        }

        $attempts = max(1, (int) config('service-tracking.email_parsing.semantic.transport_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->request($source, $annotations, $findings, $lineIds, $attempt);
            } catch (Throwable $exception) {
                if ($attempt >= $attempts || ! OpenAiTransientFailure::isTransient($exception)) {
                    throw $exception;
                }

                usleep(OpenAiTransientFailure::delayMs($exception, $attempt) * 1000);
            }
        }

        throw new RuntimeException('Semantic OoS email repair exhausted its transport attempts.');
    }

    /**
     * @param  list<OosSemanticFinding>  $findings
     * @param  list<int>  $lineIds
     */
    private function request(
        OosEmailSourceDocument $source,
        OosSemanticAnnotationResult $annotations,
        array $findings,
        array $lineIds,
        int $attempt,
    ): OosSemanticAnnotationPatch {
        $model = (string) config('service-tracking.email_parsing.semantic.model', 'gpt-5.6-terra');
        $effort = (string) config('service-tracking.email_parsing.semantic.reasoning_effort', 'low');
        $startedAt = microtime(true);
        $response = $this->client->chat()->create(OpenAiChatPayload::forModel([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Repair only the supplied line annotations. Return every named line key and no other key. Preserve fields that do not need the permitted local correction.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->userPrompt($source, $annotations, $findings, $lineIds),
                ],
            ],
            'service_tier' => config('openai.service_tier'),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'oos_semantic_annotation_patch',
                    'strict' => true,
                    'schema' => $this->patchSchema($source, $lineIds),
                ],
            ],
            'temperature' => 0.1,
            'max_completion_tokens' => (int) config('service-tracking.email_parsing.semantic.repair_max_completion_tokens', 3000),
        ], reasoningEffort: $effort));

        OpenAiUsageLogger::log($response, 'oos_semantic_annotation_repair', $model, requestedReasoningEffort: $effort);
        $content = $response->choices[0]->message->content ?? null;

        if (($response->choices[0]->finishReason ?? null) === 'length') {
            throw new OosSemanticResponseTruncatedException('Semantic OoS email repair response was truncated.');
        }

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Semantic OoS email repair response was empty.');
        }

        $payload = json_decode($content, true);
        $patchAnnotations = is_array($payload) ? ($payload['annotations'] ?? null) : null;

        if (! is_array($patchAnnotations)) {
            throw new RuntimeException('Semantic OoS email repair response did not contain annotations.');
        }

        $mergedAnnotations = array_combine(
            array_map($this->schema->lineKey(...), array_keys($annotations->annotations)),
            array_map(
                static fn ($annotation): array => array_diff_key($annotation->toArray(), ['line_id' => true]),
                $annotations->annotations,
            ),
        );

        foreach ($patchAnnotations as $key => $annotation) {
            $mergedAnnotations[$key] = $annotation;
        }

        $decoded = $this->decoder->decode($source, [
            'services' => array_map(static fn ($service): array => $service->toArray(), $annotations->services),
            'annotations' => $mergedAnnotations,
        ]);
        $replacements = [];

        foreach ($lineIds as $lineId) {
            $replacements[$lineId] = $decoded->annotations[$lineId];
        }

        $usage = $response->usage;

        return new OosSemanticAnnotationPatch($replacements, [
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

    /**
     * @param  list<OosSemanticFinding>  $findings
     * @param  list<int>  $lineIds
     */
    private function userPrompt(OosEmailSourceDocument $source, OosSemanticAnnotationResult $annotations, array $findings, array $lineIds): string
    {
        $allowedFields = [];

        foreach ($findings as $finding) {
            foreach ($finding->lineIds as $lineId) {
                $allowedFields[$lineId] = array_values(array_unique([
                    ...($allowedFields[$lineId] ?? []),
                    ...$finding->repairableFields,
                ]));
            }
        }

        return "Source lines:\n{$source->semanticPromptBody()}\n\n"
            .'Current annotations: '.json_encode($annotations->toArray()['annotations'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            .'Findings: '.json_encode(array_map(static fn (OosSemanticFinding $finding): array => $finding->toArray(), $findings), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            .'Allowed line IDs: '.json_encode($lineIds)."\n"
            .'Allowed fields by line: '.json_encode($allowedFields);
    }

    /**
     * @param  list<int>  $lineIds
     * @return array<string, mixed>
     */
    private function patchSchema(OosEmailSourceDocument $source, array $lineIds): array
    {
        $fullAnnotations = $this->schema->build($source)['properties']['annotations'];
        $keys = array_map($this->schema->lineKey(...), $lineIds);

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['annotations'],
            // The subset line schemas reference the shared field definitions, so the patch schema
            // has to carry them too or every `$ref` in it dangles.
            '$defs' => $this->schema->definitions(),
            'properties' => [
                'annotations' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $keys,
                    'properties' => array_intersect_key($fullAnnotations['properties'], array_fill_keys($keys, true)),
                ],
            ],
        ];
    }

    /**
     * @param  list<OosSemanticFinding>  $findings
     * @return list<int>
     */
    private function repairLineIds(array $findings): array
    {
        $lineIds = [];

        foreach ($findings as $finding) {
            if ($finding->repairableFields !== []) {
                $lineIds = [...$lineIds, ...$finding->lineIds];
            }
        }

        $lineIds = array_values(array_unique($lineIds));
        sort($lineIds);

        return $lineIds;
    }
}
