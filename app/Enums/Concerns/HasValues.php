<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

trait HasValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
