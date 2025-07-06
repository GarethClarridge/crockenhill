<?php

namespace App\Enums;

enum MeetingType: string
{
    case SUNDAY_AND_BIBLE_STUDIES = 'SundayAndBibleStudies';
    case CHILDREN_AND_YOUNG_PEOPLE = 'ChildrenAndYoungPeople';
    case ADULTS = 'Adults';
    case OCCASIONAL = 'Occasional';

    public function label(): string
    {
        return match ($this) {
            static::SUNDAY_AND_BIBLE_STUDIES => 'Sunday & Bible Studies',
            static::CHILDREN_AND_YOUNG_PEOPLE => 'Children & Young People',
            static::ADULTS => 'Adults',
            static::OCCASIONAL => 'Occasional',
        };
    }

    // Helper to get all values for validation rules
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
