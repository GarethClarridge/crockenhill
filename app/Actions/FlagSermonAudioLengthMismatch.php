<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Jobs\ExtractSermon;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\SectionReviewFlagPolicy;

/**
 * Hold a sermon whose MP3 is not its video's whole audio track.
 *
 * The §4.1b duration census found 12 sermon MP3s that lost their closing words
 * while their videos kept them: the MP3 was cut again, to the planned length,
 * from a stream-copied video that ran longer. {@see ExtractSermon}
 * now makes the MP3 from the final video's whole track, and this is the check
 * that the two still agree, whatever produced them.
 *
 * Like {@see FlagSermonPartsNotExtracted} the hold is derived from the media, not
 * stored as a verdict: each extraction raises or withdraws it from the lengths it
 * has just measured. An MP3 that could not be measured makes no claim either way.
 */
class FlagSermonAudioLengthMismatch
{
    /** The sermon MP3's length differs from its video's. */
    public const FLAG = 'sermon_audio_length_mismatch';

    /**
     * Encoder padding and container rounding stay well under a second; the
     * smallest confirmed loss was several seconds of closing words.
     */
    private const TOLERANCE_SECONDS = 1.0;

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run, float $videoSeconds, ?float $audioSeconds): array
    {
        $outcome = ['raised' => 0, 'withdrawn' => 0];

        if ($audioSeconds === null) {
            return $outcome;
        }

        $owed = abs($videoSeconds - $audioSeconds) > self::TOLERANCE_SECONDS;

        $sections = $run->serviceSections()
            ->where('section_type', ServiceSectionType::Sermon->value)
            ->orderBy('start_time')
            ->get();

        foreach ($sections as $section) {
            if ($this->apply($section, $owed)) {
                $outcome[$owed ? 'raised' : 'withdrawn']++;
            }
        }

        return $outcome;
    }

    private function apply(ServiceSection $section, bool $owed): bool
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $flags = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));

        if (in_array(self::FLAG, $flags, true) === $owed) {
            return false;
        }

        $without = array_values(array_filter($flags, static fn (string $flag): bool => $flag !== self::FLAG));
        $metadata['review_flags'] = $owed ? [...$without, self::FLAG] : $without;

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );
        $section->save();

        return true;
    }
}
