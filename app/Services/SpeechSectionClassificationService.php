<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Support\ServiceSectionConfidence;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class SpeechSectionClassificationService
{
    /**
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
    public function classify(ServiceSection $section): array
    {
        $transcript = $this->requireTranscript($section);
        $sectionDuration = max(0.0, (float) $section->end_time - (float) $section->start_time);

        if ($sectionDuration <= 0.0) {
            throw new RuntimeException('Speech section has invalid time boundaries.');
        }

        $response = $this->requestClassificationResponse($section, $transcript);
        $sections = $response['sections'];

        if ($sections === []) {
            return [$this->fallbackSection($section, $transcript, 'empty_ai_response')];
        }

        $normalised = [];
        $previousEnd = 0.0;

        foreach ($sections as $candidate) {
            $startOffset = $this->clampFloat($candidate['start_offset_seconds'] ?? null, 0.0, $sectionDuration);
            $endOffset = $this->clampFloat($candidate['end_offset_seconds'] ?? null, 0.0, $sectionDuration);

            if ($startOffset === null || $endOffset === null) {
                continue;
            }

            $startOffset = max($startOffset, $previousEnd);
            if ($endOffset <= $startOffset) {
                continue;
            }

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
                    'transcript' => $transcript,
                    'transcript_scope' => 'parent_segment',
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
     * @return array{sections: array<int, array<string, mixed>>}
     */
    protected function requestClassificationResponse(ServiceSection $section, string $transcript): array
    {
        return match ((string) config('media-processing.analysis.service', 'mock')) {
            'mock' => $this->mockResponse($section, $transcript),
            default => $this->openAiResponse($section, $transcript),
        };
    }

    /**
     * @return array{sections: array<int, array<string, mixed>>}
     */
    private function openAiResponse(ServiceSection $section, string $transcript): array
    {
        if (empty(config('media-processing.analysis.openai_api_key') ?? config('openai.api_key'))) {
            throw new RuntimeException('OpenAI API key not configured for speech section classification.');
        }

        $response = OpenAI::chat()->create([
            'model' => (string) config('media-processing.section_classification.model', 'gpt-4o-mini'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => <<<'TEXT'
You classify a church service speech transcript into section boundaries.
Return valid JSON only with this shape:
{"sections":[{"section_type":"welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other","start_offset_seconds":0.0,"end_offset_seconds":0.0,"confidence":0.0,"notes":["string"],"anomalies":["string"]}]}
Rules:
- Use relative seconds from the start of the supplied speech segment.
- Cover only the speech content actually present in the transcript.
- Split only when there is a clear boundary phrase or topic shift.
- Keep confidence conservative when boundaries or labels are uncertain.
- Use British English.
TEXT,
                ],
                [
                    'role' => 'user',
                    'content' => sprintf(
                        "Segment duration: %.2f seconds\nCurrent coarse type: %s\nTranscript:\n%s",
                        (float) $section->duration,
                        $section->section_type->value,
                        $transcript
                    ),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'speech_section_classification',
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['sections'],
                        'properties' => [
                            'sections' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => [
                                        'section_type',
                                        'start_offset_seconds',
                                        'end_offset_seconds',
                                        'confidence',
                                        'notes',
                                        'anomalies',
                                    ],
                                    'properties' => [
                                        'section_type' => ['type' => 'string'],
                                        'start_offset_seconds' => ['type' => 'number'],
                                        'end_offset_seconds' => ['type' => 'number'],
                                        'confidence' => ['type' => 'number'],
                                        'notes' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'anomalies' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 1400,
        ]);

        $content = $response->choices[0]->message->content ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Received empty response from OpenAI when classifying speech sections.');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Failed to decode speech section classification response.');
        }

        $sections = $decoded['sections'] ?? null;
        if (! is_array($sections)) {
            throw new RuntimeException('Speech section classification response did not include sections.');
        }

        /** @var array<int, array<string, mixed>> $normalisedSections */
        $normalisedSections = array_values(array_filter($sections, static fn (mixed $section): bool => is_array($section)));

        return ['sections' => $normalisedSections];
    }

    /**
     * @return array{sections: array<int, array<string, mixed>>}
     */
    private function mockResponse(ServiceSection $section, string $transcript): array
    {
        $duration = max(1.0, (float) $section->duration);
        $lowerTranscript = strtolower($transcript);
        $markers = [];
        $patterns = [
            ServiceSectionType::WELCOME->value => ['welcome', 'good morning everyone'],
            ServiceSectionType::PRAYER->value => ['let us pray', 'let\'s pray'],
            ServiceSectionType::CHILDRENS_TALK->value => ['good morning children', 'children'],
            ServiceSectionType::BIBLE_READING->value => ['our reading today is from', 'bible reading', 'reading from'],
            ServiceSectionType::NOTICES->value => ['notices', 'announcements'],
            ServiceSectionType::SERMON->value => ['turn in your bibles', 'our passage', 'if you have your bibles'],
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
        if ($confidence < 0.60) {
            return [ServiceSectionType::OTHER, 'none', true, 'low_ai_confidence'];
        }

        if ($confidence < 0.85 || $anomalies !== []) {
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
            'section_type' => ServiceSectionType::OTHER->value,
            'title' => null,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
            'duration' => max(0.0, (float) $section->end_time - (float) $section->start_time),
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

    private function requireTranscript(ServiceSection $section): string
    {
        $transcript = $section->metadata['transcript'] ?? null;

        if (! is_string($transcript) || trim($transcript) === '') {
            throw new RuntimeException('Speech section transcript missing.');
        }

        return trim($transcript);
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
            ? ServiceSectionType::tryFrom(trim($value)) ?? ServiceSectionType::OTHER
            : ServiceSectionType::OTHER;
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
