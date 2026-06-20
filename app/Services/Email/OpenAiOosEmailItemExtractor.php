<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Support\OpenAiChatPayload;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class OpenAiOosEmailItemExtractor implements OosEmailItemExtractor
{
    public function extract(string $subject, string $body): OosEmailItemExtractionResult
    {
        if (empty(config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for OoS email parsing.');
        }

        $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
            'model' => (string) config('service-tracking.email_parsing.model', 'gpt-4.1-nano'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => <<<'TEXT'
You extract an ordered church service list from email text.
Return valid JSON with this shape only:
{"items":[{"type":"welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other","title":"string"}],"confidence":0.0,"notes":["string"]}
Rules:
- Preserve the running order.
- Do not invent items.
- Use concise, human-readable titles.
- Use "song" for hymns/songs.
- Use "bible_reading" for scripture readings.
- Confidence reflects how reliable the extracted ordered list is.
TEXT,
                ],
                [
                    'role' => 'user',
                    'content' => "Subject: {$subject}\n\nBody:\n{$body}",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'oos_email_extraction',
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['items', 'confidence', 'notes'],
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['type', 'title'],
                                    'properties' => [
                                        'type' => [
                                            'type' => 'string',
                                        ],
                                        'title' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                            ],
                            'confidence' => [
                                'type' => 'number',
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
            'max_completion_tokens' => 1200,
        ], reasoningEffort: 'minimal'));

        $content = $response->choices[0]->message->content ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Received empty response from OpenAI when parsing OoS email.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Failed to decode OoS email parser response as JSON.');
        }

        $items = $this->normaliseItems($decoded['items'] ?? []);
        $notes = $this->normaliseNotes($decoded['notes'] ?? []);
        $confidence = $decoded['confidence'] ?? 0.0;

        return new OosEmailItemExtractionResult(
            items: $items,
            confidence: is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0,
            notes: $notes,
        );
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
