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
 * Hold a sermon whose treated sound missed its loudness or peak target.
 *
 * Every treated part of both files is measured again after the encode (§6.3). A
 * miss used to fail the extraction and delete the files, so nobody could hear
 * what had gone wrong and the run failed again on every retry. The files and
 * their measurements are now kept, and the operator hears whether the sermon is
 * acceptable; widening the tolerance would only hide the miss.
 *
 * Like {@see FlagSermonAudioPartUntreated} the hold is derived from the media:
 * each extraction raises or withdraws it from the report it has just produced.
 */
class FlagSermonAudioLoudnessMissed
{
    public const FLAG = 'sermon_audio_loudness_missed';

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run, ExtractedMedia $media): array
    {
        $outcome = ['raised' => 0, 'withdrawn' => 0];
        $owed = $media->loudnessMisses() !== [];

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
