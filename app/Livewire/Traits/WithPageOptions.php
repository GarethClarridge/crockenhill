<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\Page;

trait WithPageOptions
{
    /**
     * @return array<int, array{id: int, name: string}>
     */
    protected function pageOptions(): array
    {
        return Page::query()
            ->select(['id', 'heading'])
            ->orderBy('heading')
            ->get()
            ->map(fn (Page $page): array => ['id' => $page->id, 'name' => $page->heading])
            ->toArray();
    }
}
