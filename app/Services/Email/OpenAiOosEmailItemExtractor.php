<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\CorrectiveOosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiUsageLogger;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;

class OpenAiOosEmailItemExtractor implements CorrectiveOosEmailItemExtractor
{
    public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
    {
        return $this->request($subject, $body, $receivedDate);
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
        );
    }

    private function request(
        string $subject,
        string $body,
        string $receivedDate,
        ?string $correctionContext = null,
    ): OosEmailItemExtractionResult {
        if (empty(config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for OoS email parsing.');
        }

        $model = (string) config('service-tracking.email_parsing.model', 'gpt-5.4-nano');
        $source = OosEmailSourceDocument::fromBody($body);
        $userContent = "Email received date: {$receivedDate}\nSubject: {$subject}\n\n"
            ."Numbered non-blank body lines:\n{$source->promptBody()}";

        if ($correctionContext !== null) {
            $userContent .= "\n\n{$correctionContext}";
        }

        $attempts = max(1, (int) config('service-tracking.email_parsing.extraction_attempts', 3));

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($model, $userContent);
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

                Log::warning('Retrying OoS email extraction', [
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function attempt(string $model, string $userContent): OosEmailItemExtractionResult
    {
        $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
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
                    'schema' => $this->schema(),
                ],
            ],
            'temperature' => 0.1,
            'max_completion_tokens' => $this->maxCompletionTokens(),
        ], reasoningEffort: (string) config('service-tracking.email_parsing.reasoning_effort', 'minimal')));

        OpenAiUsageLogger::log($response, 'oos_email_parsing', $model);

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
     */
    private function maxCompletionTokens(): int
    {
        return (int) config('service-tracking.email_parsing.max_completion_tokens', 6000);
    }

    private function systemPrompt(): string
    {
        return <<<'TEXT'
You extract church service orders from email text. One email often contains BOTH a morning
and an evening order (and occasionally a special service such as carols or Christmas).
Return valid JSON with this shape only:
{"service_count":1,"services":[{"service":"morning|evening|other|unknown","date":"YYYY-MM-DD or null","service_evidence_line_ids":[1],"items":[{"type":"welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other","title":"exact source text","source_line_ids":[2],"continuation":false}],"confidence":0.0}],"ignored_lines":[{"line_id":3,"reason":"context|forwarded_header|greeting|signature"}],"notes":["string"]}
Rules:
- Count the distinct service orders first. service_count MUST equal the number of entries in services.
- A single order may have no heading. In that case service_evidence_line_ids may be empty and the
  subject or a time in the body may identify it. Multiple orders require distinct body-line evidence
  for each boundary, such as headings or standalone time markers.
- A general Notices section is context, NOT a service order, even when its lines mention another
  service, time, date, sermon or Bible passage. Put those lines in ignored_lines.
- Use "other" only for an explicitly evidenced standalone special service such as carols, Christmas,
  Good Friday or a baptism. Never use "other" for notices, meetings, diary entries or ambiguous prose.
- Preserve running order. By default each non-blank item line is exactly one item. Never merge adjacent
  item lines. Use multiple source_line_ids only for a genuinely wrapped continuation on physically
  adjacent lines, and set continuation=true. Lines separated by a blank line are separate items.
- Every numbered body line must appear exactly once: as service evidence, in one item's source_line_ids,
  or in ignored_lines. Never reuse, omit, invent or reorder line IDs.
- title must copy the complete referenced source text exactly. Do not summarise, clean or rewrite it.
- Use "morning" for AM/10.30 services, "evening" for PM/6pm services, "other" for specials
  (carols, Christmas), and "unknown" only when the service time is genuinely unclear.
- Do not infer an evening service from the word "evening" in a notice, diary entry, forwarded
  header or prose. An evening plan is valid only when its evidence lines contain a standalone
  evening/PM/18:00-style heading or a clearly separated evening order with items following it.
- Never create an evening plan merely because a morning email mentions that an evening service
  exists. If there is no distinct evening boundary and item sequence, keep one plan and put the
  mention in ignored_lines.
- Set "date" only when a service states its own date; otherwise use null.
- When a date is present but is not a Sunday, check whether it is a nearby weekday transcription
  of the Sunday service date. Do not copy the email receipt date as the service date.
- Resolve relative or yearless dates against the supplied email receipt date. These emails normally
  describe services from the receipt date through the following two weeks; do not use a training-data year.
- Use "song" for hymns/songs and "bible_reading" for readings, while keeping their complete source
  wording in title. Display-title cleanup happens after extraction.
- Confidence reflects how reliable that service's extracted order is.
TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['service_count', 'services', 'ignored_lines', 'notes'],
            'properties' => [
                'service_count' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'services' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['service', 'date', 'service_evidence_line_ids', 'items', 'confidence'],
                        'properties' => [
                            'service' => [
                                'type' => 'string',
                                'enum' => ['morning', 'evening', 'other', 'unknown'],
                            ],
                            'date' => [
                                'type' => ['string', 'null'],
                                'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
                            ],
                            'service_evidence_line_ids' => $this->lineIdArraySchema(),
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
                                        'source_line_ids' => $this->lineIdArraySchema(minItems: 1),
                                        'continuation' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 1,
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
                                'minimum' => 1,
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
     * @return array<string, mixed>
     */
    private function lineIdArraySchema(int $minItems = 0): array
    {
        return [
            'type' => 'array',
            'minItems' => $minItems,
            'items' => [
                'type' => 'integer',
                'minimum' => 1,
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
            throw new RuntimeException(sprintf(
                'OoS email parser response was truncated at the %d-token completion budget; raise '
                .'service-tracking.email_parsing.max_completion_tokens or split the email.',
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
            serviceCount: is_int($decoded['service_count'] ?? null) ? $decoded['service_count'] : null,
            ignoredLines: $this->normaliseIgnoredLines($decoded['ignored_lines'] ?? []),
            provenanceComplete: true,
        );
    }

    /**
     * @return list<array{service:?string,date:?string,service_evidence_line_ids:list<int>,items:array<int,array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>,confidence:float}>
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
                'service_evidence_line_ids' => $this->normaliseLineIds($service['service_evidence_line_ids'] ?? []),
                'items' => $this->normaliseItems($service['items'] ?? []),
                'confidence' => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0,
            ];
        }

        return $normalised;
    }

    /**
     * @param  list<array{service:?string,date:?string,service_evidence_line_ids:list<int>,items:array<int,array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>,confidence:float}>  $services
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
     * @param  list<array{service:?string,date:?string,service_evidence_line_ids:list<int>,items:array<int,array{type:string,title:string,source_line_ids:list<int>,continuation:bool}>,confidence:float}>  $services
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
