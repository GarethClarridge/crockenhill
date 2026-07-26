<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use Illuminate\Support\Str;

enum ServiceSectionType: string
{
    use HasValues;

    case Welcome = 'welcome';
    case Prayer = 'prayer';
    case Notices = 'notices';
    case Song = 'song';
    case ChildrensTalk = 'childrens_talk';
    case BibleReading = 'bible_reading';
    case Sermon = 'sermon';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome',
            self::Prayer => 'Prayer',
            self::Notices => 'Notices',
            self::Song => 'Song',
            self::ChildrensTalk => "Children's Talk",
            self::BibleReading => 'Bible Reading',
            self::Sermon => 'Sermon',
            self::Other => 'Other',
        };
    }

    public function requiresStructuralUncertaintyReview(): bool
    {
        return in_array($this, [
            self::ChildrensTalk,
            self::Song,
            self::Sermon,
            self::BibleReading,
        ], true);
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
            str_contains($lower, 'children'), str_contains($lower, 'family talk') => self::ChildrensTalk,
            str_contains($lower, 'bible reading'), str_contains($lower, 'scripture reading') => self::BibleReading,
            str_contains($lower, 'prayer') => self::Prayer,
            str_contains($lower, 'notice'), str_contains($lower, 'announcement') => self::Notices,
            str_contains($lower, 'welcome') => self::Welcome,
            str_contains($lower, 'sermon'), str_contains($lower, 'message') => self::Sermon,
            default => self::Other,
        };
    }
}
