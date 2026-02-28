<?php

namespace App\Enums;

enum ServiceSectionType: string
{
    case WELCOME = 'welcome';
    case PRAYER = 'prayer';
    case NOTICES = 'notices';
    case SONG = 'song';
    case CHILDRENS_TALK = 'childrens_talk';
    case BIBLE_READING = 'bible_reading';
    case SERMON = 'sermon';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WELCOME => 'Welcome',
            self::PRAYER => 'Prayer',
            self::NOTICES => 'Notices',
            self::SONG => 'Song',
            self::CHILDRENS_TALK => "Children's Talk",
            self::BIBLE_READING => 'Bible Reading',
            self::SERMON => 'Sermon',
            self::OTHER => 'Other',
        };
    }
}
