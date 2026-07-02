<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceStructureMode;

final class ChurchServiceProcessingTimeline
{
    public const CLASSIFY_SERVICE_SECTIONS = 'classify_service_sections';

    public const TRANSCRIBE_SPEECH_SEGMENTS = 'transcribe_speech_segments';

    // LLM-first structure pipeline steps; steps() includes them only in the
    // modes whose chains run them, so no mode shows permanently-pending
    // entries for jobs that will never log.
    public const TRANSCRIBE_FULL_SERVICE = 'transcribe_full_service';

    public const DETECT_SERVICE_STRUCTURE = 'detect_service_structure';

    public const CLASSIFY_SPEECH_SECTIONS = 'classify_speech_sections';

    public const PROJECT_LIVESTREAM_SERVICE_STRUCTURE = 'project_livestream_service_structure';

    public const ALIGN_WITH_OOS = 'align_with_oos';

    public const RESOLVE_READING_REFERENCES = 'resolve_reading_references';

    public const MATCH_SONGS_FROM_TRANSCRIPT = 'match_songs_from_transcript';

    public const RECLASSIFY_INTRO_OUTRO = 'reclassify_intro_outro';

    public const EXTRACT_SERMON = 'extract_sermon';

    public const PREPARE_SECTION_PUBLICATION_CANDIDATES = 'prepare_section_publication_candidates';

    /**
     * The timeline steps for the current service-structure mode's chain.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function steps(): array
    {
        $mode = ServiceStructureMode::fromConfig();

        if ($mode === ServiceStructureMode::Primary) {
            return [
                [
                    'key' => self::TRANSCRIBE_FULL_SERVICE,
                    'label' => 'Transcribe full service',
                ],
                [
                    'key' => self::DETECT_SERVICE_STRUCTURE,
                    'label' => 'Detect service structure',
                ],
                [
                    'key' => self::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
                    'label' => 'Project service structure',
                ],
                [
                    'key' => self::MATCH_SONGS_FROM_TRANSCRIPT,
                    'label' => 'Match songs from transcript',
                ],
                [
                    'key' => self::EXTRACT_SERMON,
                    'label' => 'Extract sermon',
                ],
                [
                    'key' => self::PREPARE_SECTION_PUBLICATION_CANDIDATES,
                    'label' => 'Prepare publication candidates',
                ],
            ];
        }

        $llmShadowSteps = $mode === ServiceStructureMode::Shadow
            ? [
                [
                    'key' => self::TRANSCRIBE_FULL_SERVICE,
                    'label' => 'Transcribe full service',
                ],
                [
                    'key' => self::DETECT_SERVICE_STRUCTURE,
                    'label' => 'Detect service structure (shadow)',
                ],
            ]
            : [];

        return [
            [
                'key' => self::CLASSIFY_SERVICE_SECTIONS,
                'label' => 'Classify service sections',
            ],
            [
                'key' => self::TRANSCRIBE_SPEECH_SEGMENTS,
                'label' => 'Transcribe speech segments',
            ],
            [
                'key' => self::CLASSIFY_SPEECH_SECTIONS,
                'label' => 'Classify speech sections',
            ],
            [
                'key' => self::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
                'label' => 'Project service structure',
            ],
            [
                'key' => self::ALIGN_WITH_OOS,
                'label' => 'Align with OoS',
            ],
            [
                'key' => self::RESOLVE_READING_REFERENCES,
                'label' => 'Resolve reading references',
            ],
            [
                'key' => self::MATCH_SONGS_FROM_TRANSCRIPT,
                'label' => 'Match songs from transcript',
            ],
            [
                'key' => self::RECLASSIFY_INTRO_OUTRO,
                'label' => 'Reclassify intro/outro sections',
            ],
            // The shadow LLM jobs run after the heuristic cluster, so their
            // timeline entries sit in the same position.
            ...$llmShadowSteps,
            [
                'key' => self::EXTRACT_SERMON,
                'label' => 'Extract sermon',
            ],
            [
                'key' => self::PREPARE_SECTION_PUBLICATION_CANDIDATES,
                'label' => 'Prepare publication candidates',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function stepKeys(): array
    {
        return array_column(self::steps(), 'key');
    }

    public static function fromCurrentStep(?string $currentStep): ?string
    {
        return match ($currentStep) {
            'classifying_sections',
            'section_classification_complete',
            'section_classification_skipped' => self::CLASSIFY_SERVICE_SECTIONS,
            self::TRANSCRIBE_FULL_SERVICE => self::TRANSCRIBE_FULL_SERVICE,
            self::DETECT_SERVICE_STRUCTURE => self::DETECT_SERVICE_STRUCTURE,
            'extraction',
            'extracting_sermon',
            'extraction_complete',
            'manual_review_required' => self::EXTRACT_SERMON,
            default => null,
        };
    }
}
