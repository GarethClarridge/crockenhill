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
            self::SUNDAY_AND_BIBLE_STUDIES => 'Sunday & Bible Studies',
            self::CHILDREN_AND_YOUNG_PEOPLE => 'Children & Young People',
            self::ADULTS => 'Adults',
            self::OCCASIONAL => 'Occasional',
        };
    }

    /**
     * Helper to get all values for validation rules
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
