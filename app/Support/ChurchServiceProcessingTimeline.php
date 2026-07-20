<?php

declare(strict_types=1);

namespace App\Support;

final class ChurchServiceProcessingTimeline
{
    public const TRANSCRIBE_FULL_SERVICE = 'transcribe_full_service';

    public const DETECT_SERVICE_STRUCTURE = 'detect_service_structure';

    public const PROJECT_LIVESTREAM_SERVICE_STRUCTURE = 'project_livestream_service_structure';

    public const MATCH_SONGS_FROM_TRANSCRIPT = 'match_songs_from_transcript';

    public const EXTRACT_SERMON = 'extract_sermon';

    public const PREPARE_SECTION_PUBLICATION_CANDIDATES = 'prepare_section_publication_candidates';

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function steps(): array
    {
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
