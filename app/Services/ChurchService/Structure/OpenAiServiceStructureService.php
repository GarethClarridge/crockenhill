<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Support\OpenAiChatPayload;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;
use TypeError;

/**
 * One-call LLM structure detection: the whole timestamped transcript plus the
 * planned order of service go in, typed sections with times, confidence, OoS
 * anchoring, reading references and sung-song titles come out.
 *
 * This owns the understanding-what-was-said judgement (including
 * sermon-vs-children's-talk); time, media and safety stay deterministic in the
 * Phase 3 gate, which every returned structure must pass before persistence.
 */
class OpenAiServiceStructureService implements ServiceStructureInterface
{
    private const SYSTEM_PROMPT = <<<'TEXT'
You identify the structure of a recorded church service from a timestamped transcript and the
planned order of service (OoS). Return typed sections in JSON matching the supplied schema.
Rules:
- Preserve the running order of the recording; sections must be chronological and must not overlap.
- A section ends when its own content ends. NEVER stretch a section to meet the start of the next
  one: announcements, transitions, music without words or silence between two items belong to no
  section. Gaps between sections are normal and expected.
- Do NOT invent sections: every section must correspond to content actually present in the transcript.
- A whole Bible reading is ONE section and a whole song is ONE section, even when pauses, verse
  breaks or spoken interjections occur inside them.
- Two different songs sung back-to-back are TWO separate sections, one per song — the one-section
  rule applies per song, never per block of songs. Use the planned order of service to know how
  many distinct songs to expect.
- Do not create sections shorter than 15 seconds unless the order of service demands a discrete item.
- Label a section childrens_talk ONLY with structural cues that it is genuinely aimed at children:
  the children are addressed or called forward, are dismissed to their groups afterwards, or the
  speaker addresses parents about the children. Interactive question-and-answer alone is not enough.
- A service has exactly ONE primary sermon unless one is genuinely absent. When you cannot tell
  which block is the sermon, choose the best candidate and report LOW confidence rather than guess
  a second sermon into existence.
- oos_item_id: the id of the matching order-of-service item. Use each id AT MOST ONCE across all
  sections, and use null when no item clearly matches. Match on both the OoS text and the words
  actually spoken. Items of the SAME type (e.g. two songs) must be claimed in their planned
  relative order; if two same-type items appear swapped, claim the one that genuinely matches and
  use null for the other. Items of DIFFERENT types may legitimately be performed in a different
  order from the printed list — the projection software groups songs into a block even when
  readings and prayers are interleaved between them — so claim the genuinely matching item
  regardless of its printed position relative to other types.
- song_title: only for type=song — the sung title as heard in the transcript, for database
  confirmation. Null otherwise.
- reading_reference: only for type=bible_reading — the passage read, e.g. "Joshua 1:1-9". Null otherwise.
- start_time and end_time are seconds into the recording and MUST come from the supplied cue
  timestamps — each transcript line gives its cue's start and end in raw seconds, ready to copy
  directly into start_time/end_time. Never estimate a time that no cue supports.
- confidence (0 to 1) reflects the section TYPE label. Be decisive when the type is unmistakable.
- Use British English in all titles and notes.
TEXT;

    public function detect(
        ChurchServiceTranscript $transcript,
        array $oosItems,
        ?string $processingId = null,
    ): ServiceStructure {
        if (empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for service structure detection.');
        }

        if ($transcript->isEmpty()) {
            throw new RuntimeException('Cannot detect service structure from an empty transcript.');
        }

        $model = (string) config('media-processing.service_structure.model', 'gpt-5');
        $prompt = $this->buildPrompt($transcript, $oosItems);

        try {
            $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $prompt['system']],
                    ['role' => 'user', 'content' => $prompt['user']],
                ],
                'response_format' => $this->responseFormat(),
                'temperature' => 0.1,
                // Headroom for reasoning models, whose hidden reasoning tokens
                // share this budget with the visible JSON.
                'max_completion_tokens' => 16000,
            ]));
        } catch (TypeError $exception) {
            throw new RuntimeException(
                'OpenAI service structure response malformed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $content = $response->choices[0]->message->content ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Received empty response from OpenAI when detecting service structure.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Failed to decode service structure detection response as JSON.');
        }

        $sectionPayloads = $decoded['sections'] ?? null;

        if (! is_array($sectionPayloads)) {
            throw new RuntimeException('Service structure detection response did not include sections.');
        }

        $sections = [];
        $notes = ServiceStructure::fromArray(['notes' => $decoded['notes'] ?? []])->notes;

        foreach ($sectionPayloads as $sectionPayload) {
            $section = ServiceStructureSection::fromArray($sectionPayload);

            if ($section instanceof ServiceStructureSection) {
                $sections[] = $section;

                continue;
            }

            $notes[] = 'Dropped a detector section without usable time boundaries.';
        }

        if ($sections === []) {
            throw new RuntimeException('Service structure detection response contained no usable sections.');
        }

        return ServiceStructure::fromSections($sections, $notes, $model);
    }

    /**
     * Exposed for the prompt snapshot test: the exact system and user messages
     * a given transcript + OoS produce.
     *
     * @param  array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>  $oosItems
     * @return array{system: string, user: string}
     */
    public function buildPrompt(ChurchServiceTranscript $transcript, array $oosItems): array
    {
        $lines = [
            sprintf(
                'Recording duration: %.0f seconds (%.1f minutes).',
                $transcript->duration,
                $transcript->duration / 60.0
            ),
        ];

        if ($oosItems === []) {
            $lines[] = 'No order of service is available for this recording; every oos_item_id must be null.';
        } else {
            $lines[] = 'Planned order of service (use these ids for oos_item_id, each at most once):';

            foreach ($oosItems as $item) {
                $lines[] = sprintf(
                    '- id %d | position %d | type %s | title %s%s',
                    $item['id'],
                    $item['position'],
                    $item['type'],
                    $item['title'] === null || trim($item['title']) === '' ? '(untitled)' : '"'.trim($item['title']).'"',
                    ($item['song_id'] ?? null) === null ? '' : ' | linked to a known song',
                );
            }
        }

        $lines[] = 'Timestamped transcript ([start-end] in seconds, matching start_time/end_time, then the spoken text):';
        $lines[] = $transcript->toPromptText();

        return [
            'system' => self::SYSTEM_PROMPT,
            'user' => implode("\n", $lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'service_structure_detection',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['sections', 'notes'],
                    'properties' => [
                        'sections' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => [
                                    'type',
                                    'title',
                                    'start_time',
                                    'end_time',
                                    'confidence',
                                    'oos_item_id',
                                    'song_title',
                                    'reading_reference',
                                    'notes',
                                ],
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
                                    'title' => ['type' => ['string', 'null']],
                                    'start_time' => ['type' => 'number'],
                                    'end_time' => ['type' => 'number'],
                                    'confidence' => ['type' => 'number'],
                                    'oos_item_id' => ['type' => ['integer', 'null']],
                                    'song_title' => ['type' => ['string', 'null']],
                                    'reading_reference' => ['type' => ['string', 'null']],
                                    'notes' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                        'notes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
