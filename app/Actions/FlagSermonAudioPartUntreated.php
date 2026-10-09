<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Video\ExtractedMedia;
use App\Support\SectionReviewFlagPolicy;

/**
 * Hold a sermon with a part whose sound was left as recorded.
 *
 * Every sermon part is normalised on its own (§6.3), so a quiet reading plays as
 * loud as the sermon it joins. A part that is silent, too short to measure, or
 * would need more gain than allowed cannot be, and is left untouched rather than
 * given made-up statistics; the operator hears whether that part is acceptable.
 *
 * Like {@see FlagSermonAudioLengthMismatch} the hold is derived from the media:
 * each extraction raises or withdraws it from the report it has just produced.
 */
class FlagSermonAudioPartUntreated
{
    public const FLAG = 'sermon_audio_part_untreated';

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run, ExtractedMedia $media): array
    {
        $outcome = ['raised' => 0, 'withdrawn' => 0];
        $owed = $media->untreatedParts() > 0;

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
