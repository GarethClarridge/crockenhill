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
                return $this->attempt($model, $userContent, $source->lineIds());
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
    private function attempt(string $model, string $userContent, array $sourceLineIds): OosEmailItemExtractionResult
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
                    // Without this the schema only guides the model; the line-id enums that make
                    // an invented line id impossible are not binding. See schema() (F64).
                    'strict' => true,
                    'schema' => $this->schema($sourceLineIds),
                ],
            ],
            'temperature' => 0.1,
            'max_completion_tokens' => $this->maxCompletionTokens(),
        ], reasoningEffort: (string) config('service-tracking.email_parsing.reasoning_effort', 'minimal')));

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

    private function systemPrompt(): string
    {
        return <<<'TEXT'
You extract church service orders from email text. One email often contains BOTH a morning
and an evening order (and occasionally a special service such as carols or Christmas).
Return valid JSON with this shape only:
{"service_count":1,"services":[{"service":"morning|evening|other|unknown","date":"YYYY-MM-DD or null","content_scope":"full|partial|unknown","service_evidence_line_ids":[1],"items":[{"type":"welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other","title":"exact source text","source_line_ids":[2],"continuation":false}],"confidence":0.0}],"ignored_lines":[{"line_id":3,"reason":"context|forwarded_header|greeting|signature"}],"notes":["string"]}
Rules:
- Count the distinct service orders first. service_count MUST equal the number of entries in services.
- Set content_scope to "full" only when the email presents the service's complete running order.
  Use "partial" for supporting material such as selected hymns, readings, sermon details or notices
  that does not claim to be the whole order. Use "unknown" when the email does not make completeness
  clear. Completeness is separate from confidence: a short hymn list can be a confident partial.
- A single order may have no heading. In that case service_evidence_line_ids may be empty and the
  subject or a time in the body may identify it. Multiple orders require distinct body-line evidence
  for each boundary, such as headings or standalone time markers.
- A sentence naming a person and a service occasion before a list of song titles (for example "X
  would like the following hymns tomorrow morning:") is boundary evidence for that service, equal
  to a heading. Like a heading, that introducing sentence itself belongs in
  service_evidence_line_ids, never as an item. Extract only the song titles that follow it as that
  service's items, even when the introducing sentence sits inside a personal note, and even though
  it frames the songs as one person's choice rather than a publicly confirmed running order.
- That intro-sentence rule does not relax the evening rules below, which still govern. A sentence in
  prose never establishes an evening plan by itself. When such an intro names an evening service and
  no standalone evening boundary appears anywhere in the email, do not open a second plan for it.
- Nor does it reach through forwarding into a different set of services. When the introducing
  sentence sits inside a forwarded older message describing services other than the ones this email
  is about, it is context: put those lines in ignored_lines rather than extracting songs that this
  email's subject date would then misdate.
- A service order does not need prayers, readings, notices or a sermon to be genuine. A bare,
  ordered list of songs for a named service is itself a valid order: extract each title as its own
  "song" item in listed order, and set that plan's content_scope to "partial" unless the email
  otherwise claims it is the complete order. Where this meets the Notices rule below, this one wins,
  but only for a list introduced for one named service: song numbers mentioned in passing inside a
  general Notices section stay context.
- A general Notices section is context, NOT a service order, even when its lines mention another
  service, time, date, sermon or Bible passage. Put those lines in ignored_lines.
- Determine the service slot separately from its occasion. A Sunday evening carol service is evening,
  and a Sunday morning Christmas service is morning. Use "other" only when a special service has no
  evidenced morning or evening slot. Never use it for notices, meetings, diary entries or ambiguous prose.
- Preserve running order. By default each non-blank item line is exactly one item. Never merge adjacent
  item lines. Use multiple source_line_ids only for a genuinely wrapped continuation on physically
  adjacent lines, and set continuation=true. Lines separated by a blank line are separate items.
- Every numbered body line must appear exactly once: as service evidence, in one item's source_line_ids,
  or in ignored_lines. Never reuse, omit, invent or reorder line IDs.
- title must copy the complete referenced source text exactly. Do not summarise, clean or rewrite it.
- Use "morning" for AM/10.30 services and "evening" for afternoon, tonight, PM or 5pm-and-later
  services. Use "unknown" only when the service slot is genuinely unclear.
- Do not infer an evening service from the word "evening" in a notice, diary entry, forwarded
  header or prose. An evening plan is valid only when its evidence lines contain a standalone
  evening/PM/18:00-style heading or a clearly separated evening order with items following it.
- Never create an evening plan merely because a morning email mentions that an evening service
  exists. If there is no distinct evening boundary and item sequence, keep one plan and put the
  mention in ignored_lines.
- Treat a relative or named date in the subject as service evidence. Subject-level dates apply to every service plan
  in the email unless a plan states a different date. When a subject says
  "tomorrow", "Sunday" or another relative day, resolve it using the supplied calendar and assign
  that date to each morning/evening plan belonging to that day. Use null only when neither the
  subject nor the plan's body lines identify its date.
- When a date is present but is not a Sunday, check whether it is a nearby weekday transcription
  of the Sunday service date. Do not copy the email receipt date as the service date.
- Resolve relative or yearless dates against the supplied email receipt date. These emails normally
  describe services from the receipt date through the following two weeks; do not use a training-data year.
- Use "song" for hymns/songs and "bible_reading" for readings, while keeping their complete source
  wording in title. Display-title cleanup happens after extraction.
- Confidence reflects how reliable that service's extracted order is.
TEXT;
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
