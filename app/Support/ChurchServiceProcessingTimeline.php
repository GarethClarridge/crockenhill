<?php

declare(strict_types=1);

namespace App\Support;

final class ChurchServiceProcessingTimeline
{
    public const CLASSIFY_SERVICE_SECTIONS = 'classify_service_sections';

    public const TRANSCRIBE_SPEECH_SEGMENTS = 'transcribe_speech_segments';

    public const CLASSIFY_SPEECH_SECTIONS = 'classify_speech_sections';

    public const PROJECT_LIVESTREAM_SERVICE_STRUCTURE = 'project_livestream_service_structure';

    public const ALIGN_WITH_OOS = 'align_with_oos';

    public const EXTRACT_SERMON = 'extract_sermon';

    public const PREPARE_SECTION_PUBLICATION_CANDIDATES = 'prepare_section_publication_candidates';

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function steps(): array
    {
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
            'extraction',
            'extracting_sermon',
            'extraction_complete',
            'manual_review_required' => self::EXTRACT_SERMON,
            default => null,
        };
    }
}
