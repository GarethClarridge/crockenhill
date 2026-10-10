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
 * Hold a sermon with a part whose speech is barely above the room noise.
 *
 * Canary 15, run 1138: the reading before the sermon was read off the microphone, 12.9 dB above
 * its room. Every treatment had to raise it some 28 dB and raised the room with it; the operator
 * heard it as "terrible" and would leave it out. Quietness alone is not the sign: 1292's reading
 * was as quiet but 26.8 dB clear of its room, and was fine. The margin is the pause level the
 * denoise choice already measures, read from the extraction's own report, and the operator decides
 * (ruled 2026-10-10: hold for review, never drop automatically).
 */
class FlagSermonAudioSpeechNearNoise
{
    public const FLAG = 'sermon_audio_speech_near_noise';

    /**
     * Between the one part heard as unlistenable (12.9 dB) and the nearest accepted (26.8 dB);
     * every other measured canary 15 part stood 29.5 dB or more clear.
     */
    public const MARGIN_DB = 20.0;

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run, ExtractedMedia $media): array
    {
        $outcome = ['raised' => 0, 'withdrawn' => 0];
        $owed = $media->speechNearNoise(self::MARGIN_DB) !== [];

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
