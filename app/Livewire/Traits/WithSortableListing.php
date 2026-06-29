<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

/**
 * Shared sort + sanitize logic for admin list components.
 *
 * The using class must define:
 *  - Constants: ALLOWED_SORT_COLUMNS, DEFAULT_SORT_COLUMN, DEFAULT_SORT_DIRECTION
 *  - Properties: public string $sortBy, public string $sortDirection
 */
trait WithSortableListing
{
    public function sort(string $column): void
    {
        if (! in_array($column, static::ALLOWED_SORT_COLUMNS, true)) {
            $this->sortBy = static::DEFAULT_SORT_COLUMN;
            $this->sortDirection = static::DEFAULT_SORT_DIRECTION;

            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortBy = $column;
        $this->sortDirection = 'asc';
    }

    protected function sanitizeSorting(): void
    {
        if (! in_array($this->sortBy, static::ALLOWED_SORT_COLUMNS, true)) {
            $this->sortBy = static::DEFAULT_SORT_COLUMN;
        }

        if (! in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = static::DEFAULT_SORT_DIRECTION;
        }
    }
}
