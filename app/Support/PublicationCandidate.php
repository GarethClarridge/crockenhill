<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AudioProfile;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\DeadFeedExcision;
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
    /**
     * The media comparison used by extraction reuse and recorded-cut boundary evidence.
     * End reasons are assessed separately: extraction reuse historically compares only sound/spans.
     *
     * @param array<string, mixed> $recorded
     * @param array<string, mixed> $planned
     */
    public static function sameMediaPlan(array $recorded, array $planned): bool
    {
        $spans = self::canonicalSegments($recorded['segments'] ?? null);
        $expected = self::canonicalSegments($planned['segments'] ?? null);
        $fade = $recorded['audio_fade_out'] ?? null;

        return count($spans) === count($expected) && array_all(
            $expected,
            static fn (array $span, int $index): bool => abs($span['start_time'] - $spans[$index]['start_time']) < 0.01
                && abs($span['end_time'] - $spans[$index]['end_time']) < 0.01,
        ) && (is_numeric($fade) ? (float) $fade : null) === ($planned['audio_fade_out'] ?? null)
            && self::sameVoiceRemoval($recorded['audio_voice_removal'] ?? null, $planned['audio_voice_removal'] ?? null);
    }

    /**
     * Malformed edges become NAN so comparisons and the extraction validator refuse them.
     *
     * @return list<array{start_time: float, end_time: float}>
     */
    public static function canonicalSegments(mixed $segments): array
    {
        return is_array($segments) ? array_values(array_map(static fn (mixed $segment): array => [
            'start_time' => is_array($segment) && is_numeric($segment['start_time'] ?? null) ? (float) $segment['start_time'] : NAN,
            'end_time' => is_array($segment) && is_numeric($segment['end_time'] ?? null) ? (float) $segment['end_time'] : NAN,
        ], $segments)) : [];
    }

    /** @param array<string, mixed> $plan */
    public static function finalEndReason(array $plan): ?string
    {
        $reason = null;
        foreach ($plan['cue_edge_widening'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['edge'] ?? null) === 'end' && is_string($entry['reason'] ?? null)) {
                $reason = $entry['reason'];
            }
        }

        return $reason;
    }

    /** JSON round trips can move a float in its last digits; reuse compares times to the millisecond (E29). */
    private static function sameVoiceRemoval(mixed $recorded, mixed $planned): bool
    {
        if ($planned === null || $recorded === null) {
            return $planned === $recorded;
        }

        return is_array($recorded) && is_array($planned) && is_numeric($recorded['from'] ?? null) && is_numeric($recorded['instruments_start'] ?? null)
            && is_numeric($planned['from'] ?? null) && is_numeric($planned['instruments_start'] ?? null)
            && abs((float) $recorded['from'] - (float) $planned['from']) < 0.001
            && abs((float) $recorded['instruments_start'] - (float) $planned['instruments_start']) < 0.001
            && ($recorded['instruments'] ?? null) == ($planned['instruments'] ?? null);
    }

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

        if ($section->section_type === ServiceSectionType::ShortTalk) {
            $deadFeed = app(DeadFeedExcision::class);
            $recordedIdentity = $candidate['dead_feed_input_identity'] ?? null;
            if (is_string($recordedIdentity) && $recordedIdentity !== $deadFeed->inputIdentity($section->processingLog)) {
                return 'was cut with different dead-feed evidence';
            }
            $spans = [];
            foreach ($candidate['segments'] ?? [] as $span) {
                if (! is_array($span) || ! is_numeric($span['start_time'] ?? null) || ! is_numeric($span['end_time'] ?? null)) {
                    return 'has invalid recorded cut bounds';
                }
                $spans[] = ['start_time' => (float) $span['start_time'], 'end_time' => (float) $span['end_time']];
            }
            if ($deadFeed->apply($section->processingLog, [$section], $spans)['dead_feed_excisions'] !== []) {
                return 'still contains an E20 dead feed';
            }
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

    /**
     * Whether the section holds a cut of its own that a song video's file is not: a candidate not
     * recorded as the one the song published, in another file. Song videos cut before songs recorded
     * their cut (2026-09) have no record; a section re-cut since, as a talk or a song, holds the newer
     * cut, and the old song file must not take its place (1180 §2375 published a September song as a talk).
     */
    public static function holdsCutOtherThan(ServiceSection $section, string $songVideoPath): bool
    {
        $candidateId = self::id($section);
        $publishedId = $section->metadata?->raw['song_video_extraction']['candidate_id'] ?? null;

        return $candidateId !== null && $publishedId !== $candidateId
            && filled($section->extracted_video_path) && $section->extracted_video_path !== $songVideoPath;
    }
}
