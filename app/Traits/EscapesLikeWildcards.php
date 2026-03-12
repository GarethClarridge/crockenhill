<?php

declare(strict_types=1);

namespace App\Traits;

trait EscapesLikeWildcards
{
    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
