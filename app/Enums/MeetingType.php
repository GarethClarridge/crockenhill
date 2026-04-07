<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum MeetingType: string
{
    use HasValues;

    case SundayAndBibleStudies = 'SundayAndBibleStudies';
    case ChildrenAndYoungPeople = 'ChildrenAndYoungPeople';
    case Adults = 'Adults';
    case Occasional = 'Occasional';

    public function label(): string
    {
        return match ($this) {
            self::SundayAndBibleStudies => 'Sunday & Bible Studies',
            self::ChildrenAndYoungPeople => 'Children & Young People',
            self::Adults => 'Adults',
            self::Occasional => 'Occasional',
        };
    }
}
