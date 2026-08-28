<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ProcessingMetadata;
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
     * @param  array<int, string>  $oosItemRawTypes  ChurchServiceItem id → raw OpenLP item type (songs, bibles, custom, presentations…); same-type ordering is judged per raw type because only raw types are authored as printed blocks
     * @param  bool  $recordingOmitsSongs  The recording was assembled from the fragments between the songs, so it contains none of them
     */
    public function __construct(
        public float $recordingDuration,
        public float $speechDuration,
        public array $oosItemTypes = [],
        public array $cues = [],
        public array $oosItemPositions = [],
        public array $oosItemRawTypes = [],
        public bool $recordingOmitsSongs = false,
    ) {}

    /**
     * A concatenated historic recording is built from the fragments that survive
     * between the songs, which were excised for copyright — so its songs are not
     * merely hard to hear, they were never recorded.
     *
     * The 2026-08-26 calibration corpus shows the relationship exactly: the
     * 2024-12-22 evening service is 10 fragments against 9 songs on record, and
     * 10 fragments have 9 gaps between them. Both concatenated identities are the
     * two the reviewer marked "none in recording, all clipped out".
     */
    public static function recordingOmitsSongs(?ProcessingMetadata $processingMetadata): bool
    {
        $concatenation = $processingMetadata?->raw['historic_import']['concatenation'] ?? null;

        return is_string($concatenation) && $concatenation !== '' && $concatenation !== 'none';
    }

    /**
     * @param  iterable<ChurchServiceItem>  $oosItems
     */
    public static function for(
        ChurchServiceTranscript $transcript,
        iterable $oosItems = [],
        bool $recordingOmitsSongs = false,
    ): self {
        $oosItemTypes = [];
        $oosItemPositions = [];
        $oosItemRawTypes = [];

        foreach ($oosItems as $item) {
            $oosItemTypes[(int) $item->id] = $item->semanticSectionType();
            $oosItemPositions[(int) $item->id] = (int) $item->position;
            $oosItemRawTypes[(int) $item->id] = strtolower((string) $item->type);
        }

        return new self(
            recordingDuration: $transcript->duration,
            speechDuration: $transcript->speechDuration(),
            oosItemTypes: $oosItemTypes,
            cues: $transcript->cues,
            oosItemPositions: $oosItemPositions,
            oosItemRawTypes: $oosItemRawTypes,
            recordingOmitsSongs: $recordingOmitsSongs,
        );
    }
}
