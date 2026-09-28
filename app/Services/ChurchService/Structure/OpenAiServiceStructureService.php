<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceOccasion;
use App\Enums\ServiceSectionType;
use App\Enums\SoundClass;
use App\Enums\TalkType;
use App\Services\Media\Audio\AudioTimeline;
use App\Support\OpenAiChatPayload;
use App\Support\OpenAiFlexFallback;
use App\Support\OpenAiUsageLogger;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;
use TypeError;

/**
 * One-call LLM structure detection: the whole timestamped transcript plus the
 * planned order of service go in, typed sections with times, confidence, OoS
 * anchoring, reading references and sung-song titles come out.
 *
 * This owns the understanding-what-was-said judgement (including
 * sermon-vs-short-talk, and a proposed talk type for each short talk); time, media and safety stay deterministic in the
 * Phase 3 gate, which every returned structure must pass before persistence.
 */
class OpenAiServiceStructureService implements ServiceStructureInterface
{
    private const SYSTEM_PROMPT = <<<'TEXT'
You identify the structure of a recorded church service from a timestamped transcript and the
planned order of service (OoS). Return typed sections in JSON matching the supplied schema.
Rules:
- Preserve the running order of the recording; sections must be chronological and must not overlap.
- Cover the whole recording. Leave gaps only where there is genuine silence or no speech.
- Do NOT invent sections: every section must correspond to content actually present in the transcript.
- A whole Bible reading is ONE section and a whole song is ONE section, even when pauses, verse
  breaks or spoken interjections occur inside them.
- The sound classification comes from the audio, not the transcript, and the transcriber cannot
  hear congregational singing. A music span inside the service is a sung item, including any
  instrumental introduction or ending, and is never evidence of speech, even when the transcript
  shows no lyrics there: a cue inside a music span that reads as filler ("Thank you.", ". . .",
  "The End") or is empty is unheard singing, not speech. A speech+music span is speech over music,
  such as a reading begun over a song's closing bars.
- Music before the first spoken item of the service is pre-service music: type it other, not
  song, unless the transcript or the order of service places a song there.
- A baptism is never inside a song section: each baptism is its own other section (a baptismal
  testimony given as a talk stays a short_talk). A hymn sung before,
  after or between baptisms is its own song section, even when the same hymn resumes after a
  baptism; end the song where the singing stops and start the next where it begins again.
- Do not create sections shorter than 15 seconds unless the order of service demands a discrete item.
- Label a section short_talk when it is a substantial spoken item that is not the sermon —
  typically with a projected item behind it: teaching for children, a catechism question, a
  hero-of-faith or Bible-character presentation, a mission or partner presentation, a testimony
  or interview. A whole talk is ONE short_talk section even when the speaker prays, asks
  questions or reads a Bible passage inside it: a passage the speaker introduces and reads as
  part of their talk belongs to the talk, not to a separate bible_reading, and the talk
  continues after it. This applies only to short talks: a sermon's Bible reading is always its
  own bible_reading section, even when the preacher reads it. A passage read after a talk has
  concluded is its own bible_reading section, even when the same person reads it. Each person's testimony is its own short_talk; never merge testimonies
  given by different people into one section. Do not use it for an ordinance (baptism,
  communion), a tribute, notices, or pre-service audio. A time of sharing and prayer — people
  give news or prayer requests and the church then prays over them — is prayer, not short_talk,
  even when each contributor speaks for a church or partner; one prayer section per contributor
  is fine. A partner or mission focus that presents its work is still a short_talk even if it
  leads into prayer: end the talk where the prayer begins and give the prayer its own section,
  rather than folding the presentation into the prayer.
- talk_type: only for type=short_talk — your proposal of what kind of talk it is; null otherwise.
  Propose childrens_talk ONLY with structural cues that it is aimed at children: the children are
  addressed or called forward, are dismissed to their groups afterwards, or the speaker addresses
  parents about the children (interactive question-and-answer alone is not enough). Propose
  partner_update when a named mission, society or partner presents its work; testimony when a
  person recounts their own story or is interviewed. Use null when none of these is clear.
- A service has exactly ONE primary sermon unless one is genuinely absent. When you cannot tell
  which block is the sermon, choose the best candidate and report LOW confidence rather than guess
  a second sermon into existence.
- sermon_absence: use it ONLY when the recording covers the whole service and no block in it is a
  sermon at all — a visiting mission presenting its work, a carol service, an all-age or
  testimony evening. Return null whenever you have labelled a sermon section, whenever the
  recording is a partial or fragmentary capture, and whenever the audio is too poor to tell:
  those are not services without a sermon, they are recordings you cannot read. When you do use
  it, `explanation` says in one sentence what stood in the sermon's place, and `occasion` names
  the recurring kind of service from the listed values, or null when none of them fits.
- When the preacher immediately concludes the sermon with a short prayer responding to what was
  preached (before any hymn, song or handover), that prayer belongs INSIDE the sermon section —
  the sermon ends when the preacher stops speaking. A closing prayer led by a DIFFERENT speaker,
  or one that follows a hymn or other intervening item, is its own prayer section.
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
- sermon_reference: only for type=sermon — the main passage the sermon expounds, when it is stated
  or clearly identifiable from the preaching, e.g. "Philippians 2:5-11". Null otherwise or when unsure.
- summary: a faithful one-sentence summary of the section in British English. Keep it factual and
  concise; use null when the transcript does not contain enough content to summarise it.
- start_time and end_time are seconds into the recording and MUST come from the supplied cue
  timestamps — each transcript line gives its cue's start and end. Never estimate a time that no
  cue supports.
- confidence (0 to 1) reflects the section TYPE label. Be decisive when the type is unmistakable.
- The top-level summary is a concise, factual summary of the whole service in no more than 80
  words. Do not mention the recording or the detection process.
- notices contains one entry per distinct announcement actually spoken in a notices section. Use
  a short title and factual details; return an empty array when no notices are present. Never
  invent dates, names, events or contact details.
- chapter_markers are the significant, listener-friendly sections of the recording. Use the
  section's grounded start_time and end_time, a concise title, and no more than one marker per
  section. Return an empty array when no reliable marker can be made.
- Use British English in all titles and notes.
TEXT;

    private const float MINIMUM_MUSIC_SPAN_SECONDS = 15.0;

    public function __construct(
        private readonly ?ServiceStructureEvaluationTelemetry $evaluationTelemetry = null,
    ) {}

    public function detect(
        ChurchServiceTranscript $transcript,
        array $oosItems,
        ?string $processingId = null,
        array $feedback = [],
        ?AudioTimeline $audioTimeline = null,
    ): ServiceStructure {
        if (empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for service structure detection.');
        }

        if ($transcript->isEmpty()) {
            throw new RuntimeException('Cannot detect service structure from an empty transcript.');
        }

        $model = (string) config('media-processing.service_structure.model', 'gpt-5.6-sol');
        $prompt = $this->buildPrompt($transcript, $oosItems, $feedback, $audioTimeline);

        try {
            /*
             * Sent through the flex fallback because this is the stage flex unavailability actually
             * bites: `detect_service_structure` runs the frontier model, whose flex pool was empty
             * for the whole of the 2026-09-02 pass while the smaller models' pools were fine.
             */
            $tiered = OpenAiFlexFallback::send(
                OpenAiChatPayload::forModel([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt['system']],
                        ['role' => 'user', 'content' => $prompt['user']],
                    ],
                    'response_format' => $this->responseFormat(),
                    'service_tier' => config('openai.service_tier'),
                    'temperature' => 0.1,
                    // Headroom for reasoning models, whose hidden reasoning tokens
                    // share this budget with the visible JSON.
                    'max_completion_tokens' => 16000,
                ], reasoningEffort: (string) config('media-processing.service_structure.reasoning_effort', 'medium')),
                static fn (array $payload): CreateResponse => OpenAI::chat()->create($payload),
                'service_structure',
            );

            $response = $tiered->response;
        } catch (TypeError $exception) {
            throw new RuntimeException(
                'OpenAI service structure response malformed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        OpenAiUsageLogger::log($response, 'service_structure', $model, $processingId, (string) config('media-processing.service_structure.reasoning_effort', 'medium'), $tiered->serviceTier);
        $this->evaluationTelemetry?->record($response);

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
        $parsedStructure = ServiceStructure::fromArray($decoded);
        $notes = $parsedStructure->notes;

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

        return ServiceStructure::fromSections(
            $sections,
            $notes,
            $model,
            $parsedStructure->summary,
            $parsedStructure->notices,
            $parsedStructure->chapterMarkers,
            $parsedStructure->sermonAbsence,
        );
    }

    /**
     * Exposed for the prompt snapshot test: the exact system and user messages
     * a given transcript + OoS produce.
     *
     * @param  array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>  $oosItems
     * @param  list<string>  $feedback
     * @return array{system: string, user: string}
     */
    public function buildPrompt(ChurchServiceTranscript $transcript, array $oosItems, array $feedback = [], ?AudioTimeline $audioTimeline = null): array
    {
        $lines = [
            sprintf(
                'Recording duration: %.0f seconds (%.1f minutes).',
                $transcript->duration,
                $transcript->duration / 60.0
            ),
        ];

        if ($feedback !== []) {
            $lines[] = 'Corrections from a previous detection attempt of this recording — address them:';

            foreach ($feedback as $note) {
                $lines[] = '- '.$note;
            }
        }

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

        if ($audioTimeline instanceof AudioTimeline) {
            $lines = [...$lines, ...$this->soundClassificationLines($audioTimeline)];
        }

        $lines[] = 'Timestamped transcript ([start-end] in seconds into the recording, the unit start_time and end_time use, then the spoken text):';
        $lines[] = $transcript->toPromptText();

        return [
            'system' => self::SYSTEM_PROMPT,
            'user' => implode("\n", $lines),
        ];
    }

    /**
     * Music spans of 15 s or more, and the speech+music spans touching them, in time order.
     *
     * Speech is not shown: the transcript already carries it. A lone short speech+music span is
     * noise, but one touching a music span is how a reading begun over a song's outro shows
     * (1304, 215-220 s), so it survives the length filter.
     *
     * @return list<string>
     */
    private function soundClassificationLines(AudioTimeline $timeline): array
    {
        $music = $timeline->spans(SoundClass::Music, self::MINIMUM_MUSIC_SPAN_SECONDS);
        $spans = array_map(static fn (array $span): array => [...$span, 'music'], $music);

        foreach ($timeline->spans(SoundClass::Mixed) as [$from, $to]) {
            foreach ($music as [$musicFrom, $musicTo]) {
                if (abs($from - $musicTo) < 0.01 || abs($to - $musicFrom) < 0.01) {
                    $spans[] = [$from, $to, 'speech+music'];

                    break;
                }
            }
        }

        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $lines = ['Sound classification (5 s windows, from the audio, independent of the transcript):'];

        if ($spans === []) {
            $lines[] = sprintf('- no music span of %.0f s or more', self::MINIMUM_MUSIC_SPAN_SECONDS);
        }

        foreach ($spans as [$from, $to, $label]) {
            $lines[] = sprintf('- %s %.0f-%.0f', $label, $from, $to);
        }

        return $lines;
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
                    'required' => ['sections', 'summary', 'notices', 'chapter_markers', 'notes', 'sermon_absence'],
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
                                    'sermon_reference',
                                    'talk_type',
                                    'notes',
                                    'summary',
                                ],
                                'properties' => [
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => ServiceSectionType::values(),
                                    ],
                                    'talk_type' => [
                                        'type' => ['string', 'null'],
                                        'enum' => [
                                            ...array_map(static fn (TalkType $type): string => $type->value, TalkType::nonSermon()),
                                            null,
                                        ],
                                    ],
                                    'title' => ['type' => ['string', 'null']],
                                    'start_time' => ['type' => 'number'],
                                    'end_time' => ['type' => 'number'],
                                    'confidence' => ['type' => 'number'],
                                    'oos_item_id' => ['type' => ['integer', 'null']],
                                    'song_title' => ['type' => ['string', 'null']],
                                    'reading_reference' => ['type' => ['string', 'null']],
                                    'sermon_reference' => ['type' => ['string', 'null']],
                                    'summary' => ['type' => ['string', 'null']],
                                    'notes' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                        'summary' => ['type' => ['string', 'null']],
                        'notices' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['title', 'details'],
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'details' => ['type' => ['string', 'null']],
                                ],
                            ],
                        ],
                        'chapter_markers' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['title', 'start_time', 'end_time'],
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'start_time' => ['type' => 'number'],
                                    'end_time' => ['type' => 'number'],
                                ],
                            ],
                        ],
                        'notes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        /*
                         * Null is the answer for almost every service, so the
                         * field is a nullable object rather than a flag plus
                         * fields that only sometimes mean anything: a structure
                         * either carries a complete assertion or carries none.
                         */
                        'sermon_absence' => [
                            'type' => ['object', 'null'],
                            'additionalProperties' => false,
                            'required' => ['occasion', 'explanation'],
                            'properties' => [
                                'occasion' => [
                                    'type' => ['string', 'null'],
                                    'enum' => [...ServiceOccasion::values(), null],
                                ],
                                'explanation' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
