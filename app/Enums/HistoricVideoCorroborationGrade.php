<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How much of a service a historic recording actually captures, which decides
 * what that recording is allowed to corroborate.
 *
 * The Email corpus records orders of service — hymn and song lists — so a
 * recording that holds only the sermon corroborates the preacher and the date
 * and nothing about song membership. Grading presence as a single boolean would
 * let a 22-minute sermon clip certify an era it cannot speak for.
 *
 * The 40-minute boundary between Full and ShortPartial is not arbitrary: it is
 * the empirical separation in the operator's own hand-labelled inventory
 * (`morning_service_recording_status.csv`), where across 286 labelled dates the
 * shortest full recording is 41.4 minutes and the longest partial is 39.8.
 */
enum HistoricVideoCorroborationGrade: string
{
    use HasValues;

    /** One recording spanning the whole service; may corroborate song membership. */
    case Full = 'full';

    /** One recording too short to be a whole service, typically sermon-only. */
    case ShortPartial = 'short_partial';

    /** Several recordings for one service; completeness needs adjudication. */
    case Fragmented = 'fragmented';

    /** Duration could not be established, so the grade is not yet known. */
    case Unknown = 'unknown';

    /** Minutes at or above which a single recording counts as a whole service. */
    public const FullServiceMinimumMinutes = 40.0;

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full service',
            self::ShortPartial => 'Short partial',
            self::Fragmented => 'Fragmented',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Whether a recording of this grade may be cited as corroborating the songs
     * a service used, as opposed to merely that the service happened.
     */
    public function corroboratesSongMembership(): bool
    {
        return $this === self::Full;
    }

    public static function forRecording(int $fileCount, ?float $totalMinutes): self
    {
        if ($fileCount > 1) {
            return self::Fragmented;
        }

        if ($totalMinutes === null) {
            return self::Unknown;
        }

        return $totalMinutes >= self::FullServiceMinimumMinutes ? self::Full : self::ShortPartial;
    }
}
