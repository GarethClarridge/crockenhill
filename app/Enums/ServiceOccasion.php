<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a service was, when what it was explains why it does not look like an
 * ordinary Sunday — most often why it carried no sermon.
 *
 * Deliberately a fixed enum and not free text (D2, 2026-09-03): free text
 * cannot be filtered on, cannot be validated, and drifts across spellings of
 * the same occasion until nothing groups. The detector proposes one of these or
 * none; an operator confirms it before it is ever rendered publicly.
 *
 * Values are stored on `church_services.occasion`. Adding a case is a schema
 * decision, not a labelling one — a new occasion should be genuinely recurring
 * rather than a one-off description of a single evening.
 */
enum ServiceOccasion: string
{
    use HasValues;

    /** A visiting mission or society presenting its work in place of a sermon. */
    case MissionPresentation = 'mission_presentation';

    case CarolService = 'carol_service';

    case Baptism = 'baptism';

    case Communion = 'communion';

    case ChurchAnniversary = 'church_anniversary';

    case GiftDay = 'gift_day';

    public function label(): string
    {
        return match ($this) {
            self::MissionPresentation => 'Mission presentation',
            self::CarolService => 'Carol service',
            self::Baptism => 'Baptismal service',
            self::Communion => 'Communion service',
            self::ChurchAnniversary => 'Church anniversary',
            self::GiftDay => 'Gift day',
        };
    }
}
