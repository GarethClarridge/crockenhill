<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiUsageLogger;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class OpenAiOosEmailItemExtractor implements OosEmailItemExtractor
{
    public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
    {
        if (empty(config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for OoS email parsing.');
        }

        $model = (string) config('service-tracking.email_parsing.model', 'gpt-5.4-nano');
        $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => <<<'TEXT'
You extract church service orders from email text. One email often contains BOTH a morning
and an evening order (and occasionally a special service such as carols or Christmas).
Return valid JSON with this shape only:
{"services":[{"service":"morning|evening|other|unknown","date":"YYYY-MM-DD or null","items":[{"type":"welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other","title":"string"}],"confidence":0.0}],"notes":["string"]}
Rules:
- Emit one entry in "services" per distinct service order found. Keep morning before evening.
- Preserve the running order of items within each service.
- Do not invent items or merge two services into one list.
- Use "morning" for AM/10.30 services, "evening" for PM/6pm services, "other" for specials
  (carols, Christmas), and "unknown" only when the service time is genuinely unclear.
- Set "date" only when a service states its own date; otherwise use null.
- Resolve relative or yearless dates against the supplied email receipt date. These emails normally
  describe services from the receipt date through the following two weeks; do not use a training-data year.
- Use concise, human-readable titles. Use "song" for hymns/songs and "bible_reading" for readings.
- Confidence reflects how reliable that service's extracted order is.
TEXT,
                ],
                [
                    'role' => 'user',
                    'content' => "Email received date: {$receivedDate}\nSubject: {$subject}\n\nBody:\n{$body}",
                ],
            ],
            'service_tier' => config('openai.service_tier'),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'oos_email_extraction',
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['services', 'notes'],
                        'properties' => [
                            'services' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['service', 'date', 'items', 'confidence'],
                                    'properties' => [
                                        'service' => [
                                            'type' => 'string',
                                            'enum' => ['morning', 'evening', 'other', 'unknown'],
                                        ],
                                        'date' => [
                                            'type' => ['string', 'null'],
                                            'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
                                        ],
                                        'items' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'required' => ['type', 'title'],
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
                                                    'title' => [
                                                        'type' => 'string',
                                                    ],
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
                            'notes' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_completion_tokens' => 1600,
        ], reasoningEffort: (string) config('service-tracking.email_parsing.reasoning_effort', 'minimal')));

        OpenAiUsageLogger::log($response, 'oos_email_parsing', $model);

        $content = $response->choices[0]->message->content ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Received empty response from OpenAI when parsing OoS email.');
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
        );
    }

    /**
     * @return list<array{service:?string,date:?string,items:array<int,array{type:string,title:string}>,confidence:float}>
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
                'items' => $this->normaliseItems($service['items'] ?? []),
                'confidence' => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0,
            ];
        }

        return $normalised;
    }

    /**
     * @param  list<array{service:?string,date:?string,items:array<int,array{type:string,title:string}>,confidence:float}>  $services
     * @return array<int, array{type:string,title:string}>
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
     * @param  list<array{service:?string,date:?string,items:array<int,array{type:string,title:string}>,confidence:float}>  $services
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
     * @return array<int, array{type:string,title:string}>
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
            ];
        }

        return $normalised;
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
