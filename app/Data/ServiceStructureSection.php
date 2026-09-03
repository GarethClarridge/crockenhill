<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ServiceSectionType;

/**
 * One typed section of a detected service structure: an LLM boundary proposal
 * in whole-recording seconds, awaiting the deterministic gate (validation,
 * silence-snapping) before it may reach persistence.
 */
final readonly class ServiceStructureSection extends JsonData
{
    public const REVIEW_FLAG_UNKNOWN_TYPE = 'unknown_section_type';

    /**
     * @param  list<string>  $notes
     * @param  list<string>  $reviewFlags
     * @param  array{start: float, end: float}|null  $snapDeltas  Seconds each boundary moved during silence-snapping
     * @param  string|null  $summary  One-sentence summary of the section content
     */
    public function __construct(
        public ServiceSectionType $type,
        public ?string $title,
        public float $startTime,
        public float $endTime,
        public float $confidence,
        public ?int $oosItemId,
        public ?string $songTitle,
        public ?string $readingReference,
        public ?string $sermonReference = null,
        public array $notes = [],
        public array $reviewFlags = [],
        public ?array $snapDeltas = null,
        public ?string $summary = null,
    ) {}

    /**
     * Build a section from a raw detector payload entry.
     *
     * Returns null when the entry has no usable time boundaries. An unknown
     * section type is normalised to `other` and flagged for review rather
     * than rejected — the run continues, a human checks the label.
     */
    public static function fromArray(mixed $value): ?self
    {
        $payload = self::arrayValue($value);

        $startTime = self::floatOrNull($payload['start_time'] ?? null);
        $endTime = self::floatOrNull($payload['end_time'] ?? null);

        if ($startTime === null || $endTime === null) {
            return null;
        }

        $requestedType = self::stringOrNull($payload['type'] ?? null);
        $type = $requestedType === null ? null : ServiceSectionType::tryFrom($requestedType);

        $notes = self::stringList($payload['notes'] ?? []);
        $reviewFlags = self::stringList($payload['review_flags'] ?? []);

        if ($type === null) {
            $type = ServiceSectionType::Other;

            if (! in_array(self::REVIEW_FLAG_UNKNOWN_TYPE, $reviewFlags, true)) {
                $reviewFlags[] = self::REVIEW_FLAG_UNKNOWN_TYPE;
            }

            if ($requestedType !== null) {
                $notes[] = "Detector requested unknown section type \"{$requestedType}\".";
            }
        }

        $confidence = self::floatOrNull($payload['confidence'] ?? null) ?? 0.0;

        $snapStartDelta = self::floatOrNull(is_array($payload['snap_deltas'] ?? null) ? ($payload['snap_deltas']['start'] ?? null) : null);
        $snapEndDelta = self::floatOrNull(is_array($payload['snap_deltas'] ?? null) ? ($payload['snap_deltas']['end'] ?? null) : null);

        return new self(
            type: $type,
            title: self::stringOrNull($payload['title'] ?? null),
            startTime: max(0.0, $startTime),
            endTime: max(max(0.0, $startTime), $endTime),
            confidence: max(0.0, min(1.0, $confidence)),
            oosItemId: self::intOrNull($payload['oos_item_id'] ?? null),
            songTitle: self::stringOrNull($payload['song_title'] ?? null),
            readingReference: self::stringOrNull($payload['reading_reference'] ?? null),
            sermonReference: self::stringOrNull($payload['sermon_reference'] ?? null),
            notes: $notes,
            reviewFlags: $reviewFlags,
            snapDeltas: $snapStartDelta === null || $snapEndDelta === null
                ? null
                : ['start' => $snapStartDelta, 'end' => $snapEndDelta],
            summary: self::stringOrNull($payload['summary'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'confidence' => $this->confidence,
            'oos_item_id' => $this->oosItemId,
            'song_title' => $this->songTitle,
            'reading_reference' => $this->readingReference,
            'sermon_reference' => $this->sermonReference,
            'notes' => $this->notes,
            'review_flags' => $this->reviewFlags,
            'snap_deltas' => $this->snapDeltas,
            'summary' => $this->summary,
        ];
    }

    public function duration(): float
    {
        return $this->endTime - $this->startTime;
    }

    /**
     * A copy with adjusted boundaries (silence-snapping) — extra notes record
     * why the times moved.
     *
     * @param  list<string>  $additionalNotes
     */
    public function withTimes(float $startTime, float $endTime, array $additionalNotes = []): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            startTime: $startTime,
            endTime: $endTime,
            confidence: $this->confidence,
            oosItemId: $this->oosItemId,
            songTitle: $this->songTitle,
            readingReference: $this->readingReference,
            sermonReference: $this->sermonReference,
            notes: [...$this->notes, ...$additionalNotes],
            reviewFlags: $this->reviewFlags,
            snapDeltas: $this->snapDeltas,
            summary: $this->summary,
        );
    }

    /**
     * A copy of this section as it must be read on a recording that contains no
     * songs — a concatenated historic recording, assembled from the fragments
     * between songs that were excised for copyright.
     *
     * The window is kept, because something was said in it and coverage has to
     * account for it. What goes is every song claim: the type, the song title,
     * and the anchor onto an order-of-service song item, which could only ever
     * have been a guess at a sequence the audio cannot establish.
     */
    public function asNonSongInSongLessRecording(): self
    {
        return new self(
            type: ServiceSectionType::Other,
            title: $this->title,
            startTime: $this->startTime,
            endTime: $this->endTime,
            confidence: $this->confidence,
            oosItemId: null,
            songTitle: null,
            readingReference: $this->readingReference,
            sermonReference: $this->sermonReference,
            notes: [...$this->notes, 'Detected as a song, but this recording is concatenated and contains no songs.'],
            reviewFlags: $this->reviewFlags,
            snapDeltas: $this->snapDeltas,
            summary: $this->summary,
        );
    }

    /**
     * A copy carrying no review flags.
     *
     * {@see self::withReviewFlags()} merges, because during detection a section accumulates
     * flags from several passes and none of them may drop another's. A re-derivation over a
     * banked structure is the opposite case: it must start from the structure's own facts,
     * not from the conclusions an earlier pass already reached about them, or a flag the
     * current rules would never raise can never be withdrawn.
     */
    public function withoutReviewFlags(): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            startTime: $this->startTime,
            endTime: $this->endTime,
            confidence: $this->confidence,
            oosItemId: $this->oosItemId,
            songTitle: $this->songTitle,
            readingReference: $this->readingReference,
            sermonReference: $this->sermonReference,
            notes: $this->notes,
            reviewFlags: [],
            snapDeltas: $this->snapDeltas,
            summary: $this->summary,
        );
    }

    /**
     * A copy carrying additional review flags (deduplicated).
     *
     * @param  list<string>  $flags
     */
    public function withReviewFlags(array $flags): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            startTime: $this->startTime,
            endTime: $this->endTime,
            confidence: $this->confidence,
            oosItemId: $this->oosItemId,
            songTitle: $this->songTitle,
            readingReference: $this->readingReference,
            sermonReference: $this->sermonReference,
            notes: $this->notes,
            reviewFlags: array_values(array_unique([...$this->reviewFlags, ...$flags])),
            snapDeltas: $this->snapDeltas,
            summary: $this->summary,
        );
    }

    /**
     * A copy recording how far each boundary moved during silence-snapping.
     */
    public function withSnapDeltas(float $startDelta, float $endDelta): self
    {
        return new self(
            type: $this->type,
            title: $this->title,
            startTime: $this->startTime,
            endTime: $this->endTime,
            confidence: $this->confidence,
            oosItemId: $this->oosItemId,
            songTitle: $this->songTitle,
            readingReference: $this->readingReference,
            sermonReference: $this->sermonReference,
            notes: $this->notes,
            reviewFlags: $this->reviewFlags,
            snapDeltas: ['start' => $startDelta, 'end' => $endDelta],
            summary: $this->summary,
        );
    }
}
