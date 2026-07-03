<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;

/**
 * The facts a detected structure is validated against: the recording it must
 * fit inside and the order-of-service items it may anchor to.
 */
final readonly class ValidationContext
{
    /**
     * @param  array<int, ServiceSectionType>  $oosItemTypes  ChurchServiceItem id → semantic section type
     * @param  list<array{start: float, end: float, text: string}>  $cues  Transcript cues, so coverage can measure the speech time sections actually overlap
     * @param  array<int, int>  $oosItemPositions  ChurchServiceItem id → planned position, so anchoring can reject same-type items claimed out of order
     */
    public function __construct(
        public float $recordingDuration,
        public float $speechDuration,
        public array $oosItemTypes = [],
        public array $cues = [],
        public array $oosItemPositions = [],
    ) {}

    /**
     * @param  iterable<ChurchServiceItem>  $oosItems
     */
    public static function for(ChurchServiceTranscript $transcript, iterable $oosItems = []): self
    {
        $oosItemTypes = [];
        $oosItemPositions = [];

        foreach ($oosItems as $item) {
            $oosItemTypes[(int) $item->id] = $item->semanticSectionType();
            $oosItemPositions[(int) $item->id] = (int) $item->position;
        }

        return new self(
            recordingDuration: $transcript->duration,
            speechDuration: $transcript->speechDuration(),
            oosItemTypes: $oosItemTypes,
            cues: $transcript->cues,
            oosItemPositions: $oosItemPositions,
        );
    }
}
