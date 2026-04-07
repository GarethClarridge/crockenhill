<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

enum ServiceSectionType: string
{
    use HasValues;

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

    /**
     * Infer a section type from a human-readable item title using keyword matching.
     *
     * Used when no explicit section_type metadata is present on a service item.
     */
    public static function inferFromTitle(string $title): self
    {
        $lower = Str::lower($title);

        return match (true) {
            str_contains($lower, 'children') => self::CHILDRENS_TALK,
            str_contains($lower, 'prayer') => self::PRAYER,
            str_contains($lower, 'notice'), str_contains($lower, 'announcement') => self::NOTICES,
            str_contains($lower, 'welcome') => self::WELCOME,
            str_contains($lower, 'sermon'), str_contains($lower, 'message') => self::SERMON,
            default => self::OTHER,
        };
    }
}
