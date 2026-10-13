<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use InvalidArgumentException;

/**
 * Whether a short talk is cut with the reading directly before it (operator ruling 2026-10-13:
 * "include the reading when the talk is about it"). Whether a talk is about its reading is a
 * judgement no rule settles — of fifteen talks after a reading, nine expounded it (962's
 * "Jehovah-Nissi" after Exodus 17) and six stood apart (1315's Mary Slessor after Matthew 22) —
 * so it is answered per talk and the answer is kept on the talk.
 *
 * Without an answer a talk is cut alone, as it always was. An answer holds while the reading it
 * was given for is still the section directly before the talk.
 */
final class TalkReadingPairing
{
    public const METADATA_KEY = 'reading_pairing';

    /** The longest gap between a reading and the talk after it for the two to play as one output. */
    public const GAP_SECONDS = 15.0;

    /** The reading directly before a talk: the section before it in time, ending at most {@see self::GAP_SECONDS} before it. */
    public function readingBefore(ServiceSection $talk): ?ServiceSection
    {
        if ($talk->section_type !== ServiceSectionType::ShortTalk) {
            return null;
        }

        $before = ServiceSection::query()
            ->where('media_processing_log_id', $talk->media_processing_log_id)
            ->whereKeyNot($talk->id)
            ->where('start_time', '<', $talk->start_time)
            ->orderByDesc('start_time')
            ->orderByDesc('id')
            ->first();

        return $before?->section_type === ServiceSectionType::BibleReading
            && (float) $talk->start_time - (float) $before->end_time <= self::GAP_SECONDS
                ? $before
                : null;
    }

    /** The reading the talk is cut with: answered as the talk's, and still the reading directly before it. */
    public function includedReading(ServiceSection $talk): ?ServiceSection
    {
        $answer = $talk->metadata?->raw[self::METADATA_KEY] ?? null;

        if (! is_array($answer) || ($answer['include'] ?? null) !== true) {
            return null;
        }

        $reading = $this->readingBefore($talk);

        return $reading !== null && $reading->id === ($answer['reading_section_id'] ?? null) ? $reading : null;
    }

    /**
     * The reading cut with a talk, when it is held for content review. The reading's hold then
     * holds the talk's output: it is published in it (Codex review 2026-10-13).
     */
    public function heldReading(ServiceSection $talk): ?ServiceSection
    {
        $reading = $this->includedReading($talk);

        return $reading !== null && HoldSectionForContentReview::isHeld($reading->metadata->reviewFlags ?? []) ? $reading : null;
    }

    /**
     * The sections a section's own output cuts, in time order: a talk and the reading it is
     * answered to go with, or the section alone.
     *
     * @return list<ServiceSection>
     */
    public function sectionsCut(ServiceSection $section): array
    {
        $reading = $this->includedReading($section);

        return $reading === null ? [$section] : [$reading, $section];
    }

    /**
     * Record whether a talk is about the reading directly before it.
     *
     * @throws InvalidArgumentException When the section is not a talk with a reading directly before it
     */
    public function record(ServiceSection $talk, bool $include, string $answeredBy): ServiceSection
    {
        $reading = $this->readingBefore($talk);

        if ($reading === null) {
            throw new InvalidArgumentException(sprintf('Section %d is not a short talk directly after a reading.', $talk->id));
        }

        $metadata = $talk->metadata?->toArray() ?? [];
        $metadata[self::METADATA_KEY] = [
            'include' => $include,
            'reading_section_id' => $reading->id,
            'reading_reference' => $reading->metadata?->readingReference,
            'answered_by' => $answeredBy,
            'answered_at' => now()->toIso8601String(),
        ];
        $talk->metadata = ServiceSectionMetadata::fromArray($metadata);
        $talk->save();

        return $talk;
    }
}
