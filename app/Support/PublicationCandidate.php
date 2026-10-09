<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AudioProfile;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\AudioTreatmentSettings;

/**
 * Whether a section's publication candidate still sounds as publication would.
 *
 * A candidate is promoted unchanged (§6.3), so it publishes only when it was cut
 * under the current processing and with the recording's current audio settings.
 * Global changes reach it through {@see MediaProcessingVersion}; a recording's own
 * overrides (`media:audio-treatment`) are not part of that version and are
 * compared here.
 */
final class PublicationCandidate
{
    /** Why the section's candidate may not publish as it is, or null when it may. */
    public static function staleReason(ServiceSection $section, AudioProfile $profile): ?string
    {
        $candidate = $section->metadata?->raw['publication_candidate_extraction'] ?? null;

        if (! is_array($candidate)) {
            return 'has no record of how it was cut';
        }

        if (! MediaProcessingVersion::matches($candidate['media_processing'] ?? null)) {
            return 'was cut under older media processing';
        }

        if (! self::settingsMatch($candidate['audio_treatment']['settings'] ?? null, $section->processingLog, $profile)) {
            return 'was cut with other audio settings than the recording now has';
        }

        return null;
    }

    /**
     * Whether recorded settings are the run's effective settings for a profile now.
     * Loose equality: JSON storage can turn -16.0 into -16.
     */
    public static function settingsMatch(mixed $recorded, MediaProcessingLog $run, AudioProfile $profile): bool
    {
        return is_array($recorded)
            && $recorded == AudioTreatmentSettings::for($profile, AudioTreatmentSettings::overridesFor($run, $profile))->toArray();
    }

    /** The identity of the cut a section's candidate holds, given to it when it was cut. */
    public static function id(ServiceSection $section): ?string
    {
        $id = $section->metadata?->raw['publication_candidate_extraction']['candidate_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Whether the section holds a candidate that is not the cut its published song came from: a replacement
     * waiting for review while the earlier clip stays public. The section's media then belongs to the
     * replacement, not to the published song.
     */
    public static function isUnpublishedReplacement(ServiceSection $section): bool
    {
        $candidateId = self::id($section);
        $publishedId = $section->metadata?->raw['song_video_extraction']['candidate_id'] ?? null;

        return $candidateId !== null && is_string($publishedId) && $publishedId !== '' && $publishedId !== $candidateId;
    }
}
