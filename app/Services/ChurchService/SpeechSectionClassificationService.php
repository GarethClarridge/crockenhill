<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Support\OpenAiChatPayload;
use App\Support\ServiceSectionConfidence;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;
use TypeError;

class SpeechSectionClassificationService
{
    /**
     * @param  array{sermon_count?: int, sermon_duration_seconds?: float, sermon_start_time?: float, sermon_end_time?: float, section_position?: int, section_total?: int}  $serviceContext
     * @return array<int, array{
     *     section_type: string,
     *     title: null,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     needs_manual_review: bool,
     *     metadata: array<string, mixed>
     * }>
     */
    public function classify(ServiceSection $section, array $serviceContext = []): array
    {
        $transcript = $this->requireTranscript($section);
        $sectionDuration = $this->sectionDurationSeconds($section);

        if ($sectionDuration <= 0.0) {
            throw new RuntimeException('Speech section has invalid time boundaries.');
        }

        $response = $this->requestClassificationResponse($section, $transcript, $serviceContext);
        $sections = $response['sections'];

        if ($sections === []) {
            return [$this->fallbackSection($section, $transcript, 'empty_ai_response')];
        }

        $anchorStarts = $this->anchorTranscriptStarts($transcript, $sections);

        $normalised = [];
        $previousEnd = 0.0;

        foreach ($sections as $candidateIndex => $candidate) {
            $startOffset = $this->clampFloat($candidate['start_offset_seconds'] ?? null, 0.0, $sectionDuration);
            $endOffset = $this->clampFloat($candidate['end_offset_seconds'] ?? null, 0.0, $sectionDuration);

            if ($startOffset === null || $endOffset === null) {
                continue;
            }

            $startOffset = max($startOffset, $previousEnd);
            if ($endOffset <= $startOffset) {
                continue;
            }

            $anchoredStart = $anchorStarts[$candidateIndex] ?? null;
            $sectionTranscript = $this->resolveSectionTranscript(
                $transcript,
                $anchorStarts,
                $candidateIndex,
                $startOffset,
                $endOffset,
                $sectionDuration
            );

            $confidence = $this->normaliseConfidence($candidate['confidence'] ?? 0.0);
            $requestedType = $this->normaliseSectionType($candidate['section_type'] ?? null);
            $anomalies = $this->normaliseStringList($candidate['anomalies'] ?? []);
            $notes = $this->normaliseStringList($candidate['notes'] ?? []);

            [$resolvedType, $confidenceLevel, $needsManualReview, $reviewReason] = $this->applyConfidencePolicy(
                $requestedType,
                $confidence,
                $anomalies
            );

            $normalised[] = [
                'section_type' => $resolvedType->value,
                'title' => null,
                'start_time' => (float) $section->start_time + $startOffset,
                'end_time' => (float) $section->start_time + $endOffset,
                'duration' => $endOffset - $startOffset,
                'confidence' => $confidence,
                'needs_manual_review' => $needsManualReview,
                'metadata' => array_filter([
                    'confidence_level' => $confidenceLevel,
                    'classification_mode' => 'ai_transcript',
                    'confidence_source' => 'ai_transcript',
                    'confidence_score' => $confidence,
                    'review_reason' => $reviewReason,
                    'ai_requested_section_type' => $requestedType->value,
                    'ai_notes' => $notes,
                    'ai_anomalies' => $anomalies,
                    'transcript' => $sectionTranscript,
                    'transcript_scope' => 'section_excerpt',
                    'transcript_alignment' => $anchoredStart === null ? 'time_ratio' : 'content_anchor',
                    'parent_transcript_available' => true,
                    'source_service_section_id' => $section->id,
                    'relative_start_seconds' => $startOffset,
                    'relative_end_seconds' => $endOffset,
                ], static fn (mixed $value): bool => $value !== null),
            ];

            $previousEnd = $endOffset;
        }

        if ($normalised === []) {
            return [$this->fallbackSection($section, $transcript, 'invalid_ai_boundaries')];
        }

        return $normalised;
    }

    /**
     * @param  array{sermon_count?: int, sermon_duration_seconds?: float, sermon_start_time?: float, sermon_end_time?: float, section_position?: int, section_total?: int}  $serviceContext
     * @return array{sections: array<int, array<string, mixed>>}
     */
    protected function requestClassificationResponse(ServiceSection $section, string $transcript, array $serviceContext = []): array
    {
        return match ((string) config('media-processing.analysis.service', 'mock')) {
            'mock' => $this->mockResponse($section, $transcript),
            default => $this->openAiResponse($section, $transcript, $serviceContext),
        };
    }

    /**
     * @param  array{sermon_count?: int, sermon_duration_seconds?: float, sermon_start_time?: float, sermon_end_time?: float, section_position?: int, section_total?: int}  $serviceContext
     * @return array{sections: array<int, array<string, mixed>>}
     */
    private function openAiResponse(ServiceSection $section, string $transcript, array $serviceContext = []): array
    {
        if (empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for speech section classification.');
        }

        $sectionDuration = $this->sectionDurationSeconds($section);

        try {
            $response = OpenAI::chat()->create(OpenAiChatPayload::forModel([
                'model' => (string) config('media-processing.section_classification.model', 'gpt-5'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => <<<'TEXT'
You classify a church service speech transcript into section boundaries.
Return valid JSON only with this shape:
{"sections":[{"section_type":"welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other","start_offset_seconds":0.0,"end_offset_seconds":0.0,"start_text":"first words of this section copied verbatim","confidence":0.0,"notes":["string"],"anomalies":["string"]}]}
Rules:
- Use relative seconds from the start of the supplied speech segment.
- "start_text" MUST be the first 6 to 12 words of the section, copied verbatim and contiguously
  from the transcript (identical words, order, and spelling). It is used to locate the section's
  exact position in the transcript text, so it must appear there exactly as written.
- Cover only the speech content actually present in the transcript.
- Split only when there is a clear boundary phrase or topic shift.
- Confidence reflects the section TYPE label only, not boundary precision. Be decisive:
  when the type is unmistakable, report confidence ≥ 0.9 even if the exact start/end is
  approximate. A sustained expository address that opens a Bible passage and preaches
  through it is clearly a sermon; an interactive, narrative talk aimed at children with
  visual aids is clearly a childrens_talk.
- Only drop confidence below 0.85 when the TYPE itself is genuinely ambiguous (e.g. a
  short teaching block that could be either a sermon or a childrens_talk), not merely
  because a boundary is fuzzy.
- The "anomalies" array forces human review, so reserve it for genuine problems that a
  person must check: truncated/garbled transcript, conflicting type signals you could not
  resolve, or content that does not fit any type. Do NOT put routine reasoning, boundary
  notes, or an explanation of a confident decision in "anomalies" — that belongs in
  "notes". A confidently-typed section must have an empty "anomalies" array.
- Use British English.
- A church service almost never has two sermons. If you detect what looks like a sermon, consider whether it might be a childrens_talk instead. Children's talks are characterised by:
  • Shorter duration (typically 5–15 minutes vs 25–45 minutes for sermons)
  • Interactive language: "can anybody tell me?", "what do you think?", "hands up"
  • References to visual aids: "let's have the next slide", "can you see in the picture"
  • Simpler vocabulary and narrative-driven Bible teaching (often retelling a story)
  • Often ends with a brief prayer then transitions to a song
  • Structural cues that it is genuinely aimed at children: the children are called to the
    front or are dismissed to their groups afterwards ("off you go to your groups now"), and
    the speaker addresses the parents or wider congregation about the children.
  Require those structural cues — children dismissed, parent-addressed language — before
  labelling a block childrens_talk. Interactive question-and-answer alone is not enough: a
  catechism recitation or an all-age teaching block can be interactive yet have no children
  dismissed and no parent-addressed language, and is not a childrens_talk.
  Only when you remain genuinely in doubt between sermon and childrens_talk for a shorter expository section, prefer childrens_talk, lower the confidence, and add an anomaly. If the evidence clearly points one way, classify it confidently and explain your reasoning in "notes", not "anomalies".
TEXT,
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildUserPrompt($section, $sectionDuration, $transcript, $serviceContext),
                    ],
                ],
                'temperature' => 0.1,
                // Headroom for reasoning models, whose hidden reasoning tokens share this budget
                // with the visible JSON; classic models stop early so the ceiling costs nothing.
                'max_completion_tokens' => 4000,
            ]));
        } catch (TypeError $exception) {
            throw new RuntimeException(
                'OpenAI speech section classification response malformed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $content = $response->choices[0]->message->content ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Received empty response from OpenAI when classifying speech sections.');
        }

        $decoded = $this->decodeJsonPayload($content);

        $sections = $decoded['sections'] ?? null;
        if (! is_array($sections)) {
            throw new RuntimeException('Speech section classification response did not include sections.');
        }

        /** @var array<int, array<string, mixed>> $normalisedSections */
        $normalisedSections = array_values(array_filter($sections, static fn (mixed $section): bool => is_array($section)));

        return ['sections' => $normalisedSections];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $content): array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');

        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $decoded = json_decode(substr($content, $jsonStart, $jsonEnd - $jsonStart + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('Failed to decode speech section classification response.');
    }

    /**
     * @return array{sections: array<int, array<string, mixed>>}
     */
    private function mockResponse(ServiceSection $section, string $transcript): array
    {
        $duration = max(1.0, $this->sectionDurationSeconds($section));
        $lowerTranscript = strtolower($transcript);
        $markers = [];
        $patterns = [
            ServiceSectionType::Welcome->value => ['welcome', 'good morning everyone'],
            ServiceSectionType::Prayer->value => ['let us pray', 'let\'s pray'],
            ServiceSectionType::ChildrensTalk->value => [
                'good morning children',
                'children',
                'can anybody tell me',
                'hands up',
                'let\'s have the next slide',
                'boys and girls',
            ],
            ServiceSectionType::BibleReading->value => ['our reading today is from', 'bible reading', 'reading from'],
            ServiceSectionType::Notices->value => ['notices', 'announcements'],
            ServiceSectionType::Sermon->value => ['turn in your bibles', 'our passage', 'if you have your bibles'],
        ];

        foreach ($patterns as $type => $needles) {
            foreach ($needles as $needle) {
                $position = strpos($lowerTranscript, $needle);
                if ($position !== false) {
                    $markers[] = [
                        'type' => $type,
                        'position' => $position,
                    ];

                    break;
                }
            }
        }

        usort($markers, fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        if ($markers === []) {
            return [
                'sections' => [[
                    'section_type' => $section->section_type->value,
                    'start_offset_seconds' => 0.0,
                    'end_offset_seconds' => $duration,
                    'start_text' => trim((string) Str::substr($transcript, 0, 40)),
                    'confidence' => 0.7,
                    'notes' => ['Mock classifier inferred a single section.'],
                    'anomalies' => [],
                ]],
            ];
        }

        $sections = [];
        $length = max(1, strlen($lowerTranscript));

        foreach ($markers as $index => $marker) {
            $nextPosition = $markers[$index + 1]['position'] ?? $length;
            $startOffset = round(($marker['position'] / $length) * $duration, 2);
            $endOffset = round(($nextPosition / $length) * $duration, 2);

            $sections[] = [
                'section_type' => $marker['type'],
                'start_offset_seconds' => $startOffset,
                'end_offset_seconds' => $index === array_key_last($markers) ? $duration : max($startOffset + 1.0, $endOffset),
                'start_text' => trim((string) Str::substr($transcript, $marker['position'], 40)),
                'confidence' => 0.9,
                'notes' => ['Mock classifier matched a transcript marker.'],
                'anomalies' => [],
            ];
        }

        return ['sections' => $sections];
    }

    /**
     * @param  list<string>  $anomalies
     * @return array{
     *     0: ServiceSectionType,
     *     1: 'high'|'low'|'none',
     *     2: bool,
     *     3: string|null
     * }
     */
    private function applyConfidencePolicy(
        ServiceSectionType $requestedType,
        float $confidence,
        array $anomalies
    ): array {
        if ($confidence < (float) config('service-tracking.confidence.review_below', 0.60)) {
            return [ServiceSectionType::Other, 'none', true, 'low_ai_confidence'];
        }

        if ($confidence < ServiceSectionConfidence::HIGH_THRESHOLD || $anomalies !== []) {
            return [$requestedType, 'low', true, $anomalies !== [] ? 'ai_classification_anomaly' : 'moderate_ai_confidence'];
        }

        return [$requestedType, 'high', false, null];
    }

    /**
     * @return array{
     *     section_type: string,
     *     title: null,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     needs_manual_review: true,
     *     metadata: array<string, mixed>
     * }
     */
    private function fallbackSection(ServiceSection $section, string $transcript, string $reason): array
    {
        return [
            'section_type' => ServiceSectionType::Other->value,
            'title' => null,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
            'duration' => $this->sectionDurationSeconds($section),
            'confidence' => ServiceSectionConfidence::scoreForLevel('none'),
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'none',
                'classification_mode' => 'ai_transcript',
                'confidence_source' => 'ai_transcript',
                'confidence_score' => 0.0,
                'review_reason' => $reason,
                'transcript' => $transcript,
                'transcript_scope' => 'parent_segment',
                'source_service_section_id' => $section->id,
            ],
        ];
    }

    /**
     * @param  array{sermon_count?: int, sermon_duration_seconds?: float, sermon_start_time?: float, sermon_end_time?: float, section_position?: int, section_total?: int}  $serviceContext
     */
    protected function buildUserPrompt(ServiceSection $section, float $sectionDuration, string $transcript, array $serviceContext = []): string
    {
        $lines = [
            sprintf('Segment duration: %.2f seconds (%.1f minutes)', $sectionDuration, $sectionDuration / 60.0),
            sprintf('Segment start time in recording: %.0f seconds', (float) $section->start_time),
            sprintf('Segment end time in recording: %.0f seconds', (float) $section->end_time),
            sprintf('Current coarse type: %s', $section->section_type->value),
        ];

        if (! empty($serviceContext['sermon_count'])) {
            $contextLine = sprintf(
                'Service context: a sermon section of %.0fs has already been identified at %.0f–%.0fs.',
                (float) ($serviceContext['sermon_duration_seconds'] ?? 0),
                (float) ($serviceContext['sermon_start_time'] ?? 0),
                (float) ($serviceContext['sermon_end_time'] ?? 0)
            );
            $lines[] = $contextLine;
        }

        if (isset($serviceContext['section_position'], $serviceContext['section_total'])) {
            $lines[] = sprintf(
                'Position in service: speech section %d of %d.',
                (int) $serviceContext['section_position'],
                (int) $serviceContext['section_total']
            );
        }

        if ($sectionDuration > 300.0) {
            $lines[] = sprintf(
                'Note: this segment is %.1f minutes long. Consider whether it may contain a children\'s talk sub-section followed by other content.',
                $sectionDuration / 60.0
            );
        }

        $lines[] = 'Transcript:';
        $lines[] = $transcript;

        return implode("\n", $lines);
    }

    private function requireTranscript(ServiceSection $section): string
    {
        $transcript = $section->metadata['transcript'] ?? null;

        if (! is_string($transcript) || trim($transcript) === '') {
            throw new RuntimeException('Speech section transcript missing.');
        }

        return trim($transcript);
    }

    private function sectionDurationSeconds(ServiceSection $section): float
    {
        return max(0.0, (float) $section->end_time - (float) $section->start_time);
    }

    /**
     * Locate each classified section inside the parent transcript by its verbatim opening words.
     *
     * The model returns its boundaries as elapsed-time offsets, but text density is not uniform
     * across a speech block (a prayer drifts over long silences, narration races), so slicing the
     * transcript by character ratio misattributes whole passages to the wrong section. The model
     * also reports a short verbatim `start_text` for each section; finding that snippet gives the
     * true character offset of the section's content, monotonically advancing a cursor so repeated
     * phrases resolve in order.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, int|null> Character offset per candidate, or null when no anchor was found.
     */
    private function anchorTranscriptStarts(string $transcript, array $candidates): array
    {
        $starts = [];
        $cursor = 0;

        foreach ($candidates as $index => $candidate) {
            $snippet = is_string($candidate['start_text'] ?? null)
                ? trim($candidate['start_text'])
                : '';

            if ($snippet === '') {
                $starts[$index] = null;

                continue;
            }

            $position = mb_stripos($transcript, $snippet, $cursor);

            if ($position === false) {
                // The model may have reordered or the cursor overshot a near-duplicate; retry
                // from the start before giving up and falling back to the time-ratio excerpt.
                $position = mb_stripos($transcript, $snippet);
            }

            if ($position === false) {
                $starts[$index] = null;

                continue;
            }

            $starts[$index] = $position;
            $cursor = $position + mb_strlen($snippet);
        }

        return $starts;
    }

    /**
     * Resolve a section's transcript from content anchors when available, falling back to the
     * legacy time-ratio excerpt. A section runs from its own anchor up to the next anchored
     * sibling (or the end of the transcript).
     *
     * @param  array<int, int|null>  $anchorStarts
     */
    private function resolveSectionTranscript(
        string $transcript,
        array $anchorStarts,
        int $index,
        float $startOffset,
        float $endOffset,
        float $sectionDuration
    ): string {
        $start = $anchorStarts[$index] ?? null;

        if ($start === null) {
            return $this->excerptTranscript($transcript, $startOffset, $endOffset, $sectionDuration);
        }

        $end = null;
        foreach ($anchorStarts as $otherIndex => $otherStart) {
            if ($otherIndex > $index && $otherStart !== null && $otherStart > $start) {
                $end = $otherStart;

                break;
            }
        }

        $slice = $end === null
            ? Str::substr($transcript, $start)
            : Str::substr($transcript, $start, max(1, $end - $start));

        $slice = trim($slice);

        return $slice !== ''
            ? $slice
            : $this->excerptTranscript($transcript, $startOffset, $endOffset, $sectionDuration);
    }

    private function excerptTranscript(string $transcript, float $startOffset, float $endOffset, float $sectionDuration): string
    {
        if ($sectionDuration <= 0.0) {
            return trim($transcript);
        }

        $length = Str::length($transcript);
        if ($length === 0) {
            return '';
        }

        $startRatio = max(0.0, min(1.0, $startOffset / $sectionDuration));
        $endRatio = max($startRatio, min(1.0, $endOffset / $sectionDuration));

        $startCharacter = (int) floor($length * $startRatio);
        $endCharacter = (int) ceil($length * $endRatio);
        $excerptLength = max(1, $endCharacter - $startCharacter);

        $excerpt = trim((string) Str::substr($transcript, $startCharacter, $excerptLength));

        return $excerpt !== '' ? $excerpt : trim($transcript);
    }

    private function clampFloat(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max($minimum, min($maximum, (float) $value));
    }

    private function normaliseConfidence(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.0;
    }

    private function normaliseSectionType(mixed $value): ServiceSectionType
    {
        return is_string($value)
            ? ServiceSectionType::tryFrom(trim($value)) ?? ServiceSectionType::Other
            : ServiceSectionType::Other;
    }

    /**
     * @return list<string>
     */
    private function normaliseStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalised = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $normalised[] = $trimmed;
        }

        return $normalised;
    }
}
