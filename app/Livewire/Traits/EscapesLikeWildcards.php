<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

trait EscapesLikeWildcards
{
    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
