<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum SermonContentType: string
{
    use HasValues;

    case Sermon = 'sermon';
    case ChildrensTalk = 'childrens_talk';

    public function label(): string
    {
        return match ($this) {
            self::Sermon => 'Sermon',
            self::ChildrensTalk => "Children's Talk",
        };
    }
}
