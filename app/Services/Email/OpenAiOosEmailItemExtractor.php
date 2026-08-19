<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\AdjudicatingOosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Exceptions\OosEmailExtractionTruncatedException;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiUsageLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;

class OpenAiOosEmailItemExtractor implements AdjudicatingOosEmailItemExtractor
{
    public function __construct(
        private readonly ?OosParserEvaluationTelemetry $evaluationTelemetry = null,
    ) {}

    public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
    {
        return $this->request($subject, $body, $receivedDate, callRole: 'extract');
    }

    public function correct(
        string $subject,
        string $body,
        string $receivedDate,
        OosEmailItemExtractionResult $previousExtraction,
        array $validationFailures,
    ): OosEmailItemExtractionResult {
        $previous = json_encode([
            'service_count' => $previousExtraction->serviceCount,
            'services' => $previousExtraction->services,
            'ignored_lines' => $previousExtraction->ignoredLines,
            'notes' => $previousExtraction->notes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $failures = implode("\n", array_map(
            static fn (string $failure): string => "- {$failure}",
            $validationFailures,
        ));

        return $this->request(
            $subject,
            $body,
            $receivedDate,
            correctionContext: "The first extraction failed deterministic validation.\n"
                ."Previous extraction:\n{$previous}\n\nValidation failures:\n{$failures}\n\n"
                .'Return one corrected extraction. Do not defend or repeat a structurally invalid result.',
            callRole: 'correct',
        );
    }

    public function adjudicate(
        string $subject,
        string $body,
        string $receivedDate,
        OosEmailItemExtractionResult $initialExtraction,
        OosEmailItemExtractionResult $correctedExtraction,
        array $disagreementCategories,
    ): OosEmailItemExtractionResult {
        $candidates = json_encode([
            'first' => $this->extractionForPrompt($initialExtraction),
            'second' => $this->extractionForPrompt($correctedExtraction),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $differences = implode(', ', $disagreementCategories);

        return $this->request(
            $subject,
            $body,
            $receivedDate,
            correctionContext: "Two structurally valid extractions disagree only in these compared fields: {$differences}.\n"
                ."Candidate extractions:\n{$candidates}\n\n"
                .'Resolve those differences against the numbered source lines. Return the complete extraction that the source supports. Do not invent a third interpretation.',
            callRole: 'adjudicate',
        );
    }

    /** @return array<string, mixed> */
    private function extractionForPrompt(OosEmailItemExtractionResult $extraction): array
    {
        return [
            'service_count' => $extraction->serviceCount,
            'services' => $extraction->services,
            'ignored_lines' => $extraction->ignoredLines,
        ];
    }

    private function request(
        string $subject,
        string $body,
        string $receivedDate,
        ?string $correctionContext = null,
        string $callRole = 'extract',
    ): OosEmailItemExtractionResult {
        if (empty(config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for OoS email parsing.');
        }

        $model = (string) config('service-tracking.email_parsing.model', 'gpt-5.4-nano');
        $source = OosEmailSourceDocument::fromBody($body);
        $userContent = $this->calendarContext($receivedDate)."\nSubject: {$subject}\n\n"
            ."Numbered non-blank body lines:\n{$source->promptBody()}";

        if ($correctionContext !== null) {
            $userContent .= "\n\n{$correctionContext}";
        }

        $attempts = max(1, (int) config('service-tracking.email_parsing.extraction_attempts', 3));

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($model, $userContent, $source->lineIds(), $callRole, $attempt);
            } catch (RuntimeException $exception) {
                /*
                 * Every failure `attempt()` raises is "the model returned something
                 * unusable", and asking again is the remedy — verified on the entry
                 * the 2026-08-11 staging run lost, which parsed cleanly on the next
                 * call with an unchanged input. Without this, one flaky call
                 * permanently loses a service and F32's closeout then refuses the
                 * whole corpus operation on account of it.
                 *
                 * Configuration faults are raised before this loop, so they cannot
                 * be retried.
                 */
                if ($attempt >= $attempts) {
                    throw $exception;
                }

                /*
                 * `reason_class` separates a budget failure from a model-quality
                 * failure. Counting them together would let a completion ceiling
                 * sized for one reasoning effort masquerade as a worse model when
                 * the effort is raised.
                 */
                Log::warning('Retrying OoS email extraction', [
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                    'evaluation_arm' => config('openai.evaluation_arm'),
                    'reason_class' => $exception instanceof OosEmailExtractionTruncatedException
                        ? 'truncated'
                        : 'unusable_response',
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    /** @param list<int> $sourceLineIds */
    private function attempt(string $model, string $userContent, array $sourceLineIds, string $callRole, int $attempt): OosEmailItemExtractionResult
    {
        $startedAt = microtime(true);
        $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => OosEmailExtractionPrompt::configured()->text(),
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
            'service_tier' => config('openai.service_tier'),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'oos_email_extraction',
                    // Without this the schema only guides the model; the line-id enums that make
                    // an invented line id impossible are not binding. See schema() (F64).
                    'strict' => true,
                    'schema' => $this->schema($sourceLineIds),
                ],
            ],
            'temperature' => 0.1,
            'max_completion_tokens' => $this->maxCompletionTokens(),
        ], reasoningEffort: (string) config('service-tracking.email_parsing.reasoning_effort', 'minimal')));

        $this->evaluationTelemetry?->record($response, $callRole, $attempt, $startedAt);
        OpenAiUsageLogger::log($response, 'oos_email_parsing', $model, requestedReasoningEffort: (string) config('service-tracking.email_parsing.reasoning_effort', 'minimal'));

        return $this->resultFromResponse($response);
    }

    /**
     * The completion budget one email's extraction may use.
     *
     * Measured on the 2026-08-11 corpus: the same 49-line email returned 991,
     * 1,081 and 1,743 output tokens on three consecutive calls, so the spread
     * between identical requests is wide enough that a budget sized to the
     * typical response will occasionally truncate. Configurable because the
     * ceiling is a property of the corpus, not of the code.
     *
     * Those figures were measured at `none`/`minimal` effort and cover the
     * visible JSON only. Reasoning tokens are billed against this same budget,
     * so any effort above `minimal` adds its configured headroom — otherwise
     * raising effort truncates and the stronger setting is scored as the worse
     * one. Unused headroom is not billed, so the ceiling is set generously.
     */
    private function maxCompletionTokens(): int
    {
        $base = (int) config('service-tracking.email_parsing.max_completion_tokens', 6000);

        return $base + $this->reasoningTokenHeadroom();
    }

    /**
     * Extra ceiling for the effort this request will actually carry.
     *
     * Keyed on the *effective* effort rather than the configured one, because
     * `minimal` is sent as `none` on GPT-5.4+ and would otherwise be granted
     * headroom it cannot spend.
     */
    private function reasoningTokenHeadroom(): int
    {
        $effort = OpenAiChatPayload::effectiveReasoningEffort(
            (string) config('service-tracking.email_parsing.model', 'gpt-5.4-nano'),
            (string) config('service-tracking.email_parsing.reasoning_effort', 'minimal'),
        );

        if ($effort === null) {
            return 0;
        }

        $headroom = config('service-tracking.email_parsing.reasoning_token_headroom', []);

        return is_array($headroom) ? (int) ($headroom[$effort] ?? 0) : 0;
    }

    private function calendarContext(string $receivedDate): string
    {
        $received = CarbonImmutable::createFromFormat('Y-m-d', $receivedDate);

        if (! $received instanceof CarbonImmutable || $received->toDateString() !== $receivedDate) {
            throw new RuntimeException("Invalid email received date {$receivedDate}.");
        }

        $calendar = [];

        for ($dayOffset = 0; $dayOffset <= 14; $dayOffset++) {
            $date = $received->addDays($dayOffset);
            $calendar[] = $date->format('Y-m-d l');
        }

        return "Email received date: {$received->format('Y-m-d (l)')}\n"
            .'Calendar from the received date: '.implode(', ', $calendar);
    }

    /**
     * The request is strict (see attempt()), which binds the model to this schema but also
     * narrows the keywords it may contain to the subset already proven against the live API by
     * OpenAiServiceStructureService: type, enum, items, properties, required and
     * additionalProperties. Range, pattern and length keywords are therefore expressed in PHP
     * instead — `service_count` is clamped in resultFromResponse(), `confidence` in
     * normaliseServices(), the date format in OosEmailParserService::validatedDate(), and
     * "every item cites a line" by OosEmailExtractionValidator. Enforcing a constraint here
     * that strict mode rejects would fail the whole request, so never reintroduce one without
     * the covering test in OpenAiOosEmailItemExtractorTest.
     *
     * @param  list<int>  $sourceLineIds
     * @return array<string, mixed>
     */
    private function schema(array $sourceLineIds): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['service_count', 'services', 'ignored_lines', 'notes'],
            'properties' => [
                'service_count' => [
                    'type' => 'integer',
                ],
                'services' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['service', 'date', 'content_scope', 'service_evidence_line_ids', 'items', 'confidence'],
                        'properties' => [
                            'service' => [
                                'type' => 'string',
                                'enum' => ['morning', 'evening', 'other', 'unknown'],
                            ],
                            'date' => [
                                'type' => ['string', 'null'],
                            ],
                            'content_scope' => [
                                'type' => 'string',
                                'enum' => ['full', 'partial', 'unknown'],
                            ],
                            'service_evidence_line_ids' => $this->lineIdArraySchema($sourceLineIds),
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['type', 'title', 'source_line_ids', 'continuation'],
                                    'properties' => [
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => [
                                                'welcome',
                                                'prayer',
                                                'notices',
                                                'song',
                                                'childrens_talk',
                                                'bible_reading',
                                                'sermon',
                                                'other',
                                            ],
                                        ],
                                        'title' => ['type' => 'string'],
                                        'source_line_ids' => $this->lineIdArraySchema($sourceLineIds),
                                        'continuation' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                            'confidence' => [
                                'type' => 'number',
                            ],
                        ],
                    ],
                ],
                'ignored_lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['line_id', 'reason'],
                        'properties' => [
                            'line_id' => [
                                'type' => 'integer',
                                'enum' => $sourceLineIds,
                            ],
                            'reason' => [
                                'type' => 'string',
                                'enum' => ['context', 'forwarded_header', 'greeting', 'signature'],
                            ],
                        ],
                    ],
                ],
                'notes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @param  list<int>  $sourceLineIds
     * @return array<string, mixed>
     */
    private function lineIdArraySchema(array $sourceLineIds): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'integer',
                'enum' => $sourceLineIds,
            ],
        ];
    }

    private function resultFromResponse(CreateResponse $response): OosEmailItemExtractionResult
    {
        $content = $response->choices[0]->message->content ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Received empty response from OpenAI when parsing OoS email.');
        }

        /*
         * A `json_schema` response format cannot emit malformed JSON, so the only
         * ordinary way decoding fails is a response cut off at the completion
         * budget. Saying so is the difference between a diagnosable failure and
         * the bare "failed to decode" that cost the 2026-08-11 staging run an
         * entry nobody could explain from the message alone.
         */
        if (($response->choices[0]->finishReason ?? null) === 'length') {
            throw new OosEmailExtractionTruncatedException(sprintf(
                'OoS email parser response was truncated at the %d-token completion budget; raise '
                .'service-tracking.email_parsing.max_completion_tokens or '
                .'service-tracking.email_parsing.reasoning_token_headroom, or split the email.',
                $this->maxCompletionTokens(),
            ));
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Failed to decode OoS email parser response as JSON.');
        }

        if (! is_array($decoded['services'] ?? null)) {
            throw new RuntimeException('OoS email parser response did not contain typed service plans.');
        }

        $services = $this->normaliseServices($decoded['services']);

        return new OosEmailItemExtractionResult(
            items: $this->flattenServiceItems($services),
            confidence: $this->averageConfidence($services),
            notes: $this->normaliseNotes($decoded['notes'] ?? []),
            services: $services,
            serviceCount: is_int($decoded['service_count'] ?? null) ? max(0, $decoded['service_count']) : null,
            ignoredLines: $this->normaliseIgnoredLines($decoded['ignored_lines'] ?? []),
            provenanceComplete: true,
        );
    }

    /**
     * @return list<array{service:?string,date:?string,content_scope:string,service_evidence_line_ids:list<int>,items:array<int,array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>,confidence:float}>
     */
    private function normaliseServices(mixed $services): array
    {
        if (! is_array($services)) {
            return [];
        }

        $normalised = [];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $confidence = $service['confidence'] ?? 0.0;

            $normalised[] = [
                'service' => $this->normaliseString($service['service'] ?? null),
                'date' => $this->normaliseString($service['date'] ?? null),
                'content_scope' => $this->normaliseContentScope($service['content_scope'] ?? null),
                'service_evidence_line_ids' => $this->normaliseLineIds($service['service_evidence_line_ids'] ?? []),
                'items' => $this->normaliseItems($service['items'] ?? []),
                'confidence' => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0,
            ];
        }

        return $normalised;
    }

    private function normaliseContentScope(mixed $contentScope): string
    {
        if (! is_string($contentScope)) {
            return 'full';
        }

        return in_array($contentScope, ['full', 'partial', 'unknown'], true)
            ? $contentScope
            : 'unknown';
    }

    /**
     * @param  list<array{service:?string,date:?string,content_scope:string,service_evidence_line_ids:list<int>,items:array<int,array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>,confidence:float}>  $services
     * @return array<int, array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>
     */
    private function flattenServiceItems(array $services): array
    {
        $items = [];

        foreach ($services as $service) {
            foreach ($service['items'] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  list<array{service:?string,date:?string,content_scope:string,service_evidence_line_ids:list<int>,items:array<int,array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>,confidence:float}>  $services
     */
    private function averageConfidence(array $services): float
    {
        if ($services === []) {
            return 0.0;
        }

        $total = array_sum(array_map(static fn (array $service): float => $service['confidence'], $services));

        return round($total / count($services), 2);
    }

    /**
     * @return array<int, array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>
     */
    private function normaliseItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalised = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $this->normaliseString($item['type'] ?? null);
            $title = $this->normaliseString($item['title'] ?? null);

            if ($type === null || $title === null) {
                continue;
            }

            $normalised[] = [
                'type' => $type,
                'title' => $title,
                'source_line_ids' => $this->normaliseLineIds($item['source_line_ids'] ?? []),
                'continuation' => ($item['continuation'] ?? false) === true,
            ];
        }

        return $normalised;
    }

    /**
     * @return list<array{line_id:int,reason:string}>
     */
    private function normaliseIgnoredLines(mixed $ignoredLines): array
    {
        if (! is_array($ignoredLines)) {
            return [];
        }

        $normalised = [];

        foreach ($ignoredLines as $ignoredLine) {
            if (! is_array($ignoredLine)) {
                continue;
            }

            $lineId = $ignoredLine['line_id'] ?? null;
            $reason = $this->normaliseString($ignoredLine['reason'] ?? null);

            if (! is_int($lineId) || $reason === null) {
                continue;
            }

            $normalised[] = [
                'line_id' => $lineId,
                'reason' => $reason,
            ];
        }

        return $normalised;
    }

    /**
     * @return list<int>
     */
    private function normaliseLineIds(mixed $lineIds): array
    {
        if (! is_array($lineIds)) {
            return [];
        }

        return array_values(array_filter($lineIds, is_int(...)));
    }

    /**
     * @return list<string>
     */
    private function normaliseNotes(mixed $notes): array
    {
        if (! is_array($notes)) {
            return [];
        }

        $normalised = [];

        foreach ($notes as $note) {
            $note = $this->normaliseString($note);

            if ($note !== null) {
                $normalised[] = $note;
            }
        }

        return $normalised;
    }

    private function normaliseString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
