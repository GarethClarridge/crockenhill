<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AudioProfile;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Services\Media\Audio\AudioTreatmentSettings;
use App\Support\MediaProcessingVersion;
use Illuminate\Support\Str;

/**
 * What {@see PrepareSectionPublicationCandidates} records for a candidate cut under
 * the current processing: a candidate publishes only when this still matches (§6.3).
 */
final class PublicationCandidateFixture
{
    /**
     * @param  array<string, mixed>  $overrides  the run's own settings for the profile at cut time
     * @param  array<string, mixed>  $report  further audio report fields (parts, loudness_misses)
     * @return array<string, mixed>
     */
    public static function current(AudioProfile $profile, array $overrides = [], array $report = []): array
    {
        return [
            'candidate_id' => (string) Str::uuid(),
            'media_processing' => MediaProcessingVersion::signature(),
            'audio_treatment' => self::audioReport($profile, $overrides, $report),
        ];
    }

    /**
     * The audio report a treated cut returns with its media.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function audioReport(AudioProfile $profile, array $overrides = [], array $report = []): array
    {
        return [
            'settings' => AudioTreatmentSettings::for($profile, $overrides)->toArray(),
            'loudness_misses' => [],
            'parts' => [],
            ...$report,
        ];
    }
}
