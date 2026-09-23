<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What kind of talk a `sermons` row publishes. The primary sermon is one type
 * among several; every other type is published from an approved service section.
 */
enum TalkType: string
{
    use HasValues;

    case Sermon = 'sermon';
    case ChildrensTalk = 'childrens_talk';
    case PartnerUpdate = 'partner_update';
    case Testimony = 'testimony';

    public function label(): string
    {
        return match ($this) {
            self::Sermon => 'Sermon',
            self::ChildrensTalk => "Children's Talk",
            self::PartnerUpdate => 'Partner Update',
            self::Testimony => 'Testimony',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Sermon => 'Sermons',
            self::ChildrensTalk => "Children's Talks",
            self::PartnerUpdate => 'Partner Updates',
            self::Testimony => 'Testimonies',
        };
    }

    public function isSermon(): bool
    {
        return $this === self::Sermon;
    }

    /**
     * @return list<self>
     */
    public static function nonSermon(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => ! $type->isSermon()));
    }
}
