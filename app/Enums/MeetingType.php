<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum MeetingType: string
{
    use HasValues;

    case SUNDAY_AND_BIBLE_STUDIES = 'SundayAndBibleStudies';
    case CHILDREN_AND_YOUNG_PEOPLE = 'ChildrenAndYoungPeople';
    case ADULTS = 'Adults';
    case OCCASIONAL = 'Occasional';

    public function label(): string
    {
        return match ($this) {
            self::SUNDAY_AND_BIBLE_STUDIES => 'Sunday & Bible Studies',
            self::CHILDREN_AND_YOUNG_PEOPLE => 'Children & Young People',
            self::ADULTS => 'Adults',
            self::OCCASIONAL => 'Occasional',
        };
    }
}
